<?php
/* =========================================================================
 * stats_update.php — statistiche d'uso dai log di Apache (Tranche 4)
 * ---------------------------------------------------------------------------
 * Gira ogni notte dal crontab dell'utente che possiede il codice, nel gruppo
 * adm per leggere /var/log/apache2 (www-data non puo', ed e' giusto cosi'):
 *
 *   50 1 * * * umask 027; /usr/bin/php /percorso/gallery/stats_update.php --quiet >> /percorso/gallery/stats/update.log 2>&1
 *
 * (umask 027: il log lo crea la shell, e in stats/ prende il gruppo del server
 * web; senza, con un umask 002 sarebbe scrivibile da www-data.)
 *
 * Legge TUTTI i log disponibili (access.log, access.log.1, access.log.N.gz:
 * pochi secondi per le due settimane che logrotate conserva), conta le
 * richieste di immagini per giorno, codice e provenienza (vedi _stats.php)
 * e aggiorna stats/stats.db.
 *
 * Come si fondono vecchio e nuovo: per ogni giorno vince il conteggio che ha
 * visto PIU' righe. Cosi' un giorno gia' completo non viene sostituito da uno
 * parziale quando il suo primo log esce dalla rotazione, il giorno in corso
 * si completa la notte dopo, e lo storico oltre i log resta nel file.
 * Lanciarlo piu' volte non cambia nulla: e' idempotente.
 *
 * Il file si prepara su una copia e la si mette al suo posto con rename():
 * il pannello, che lo legge come www-data, vede il vecchio o il nuovo, mai
 * uno a meta'. Permessi 640, gruppo della cartella (www-data): il server web
 * legge, non scrive.
 *
 * Uso:  php stats_update.php [--logs GLOB]... [--db FILE] [--dry-run] [--quiet]
 *   --logs GLOB  log da leggere, ripetibile (default: ACCESS_LOGS in
 *                secret.php, altrimenti /var/log/apache2/access.log*)
 *   --db FILE    file delle statistiche (default: STATS_DB, cioe' stats/stats.db)
 *   --dry-run    legge e riassume, non scrive nulla
 *   --quiet      una riga di riepilogo (per il log del cron), piu' gli avvisi
 * Esito: 0 fatto; 1 errore (nessun log leggibile, file non scrivibile, ...).
 * ========================================================================= */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo CLI\n"); }
umask(027);       // file creati qui (.lock, stats.db): mai scrivibili dal gruppo del server web
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_stats.php';

$opt = ['logs' => [], 'db' => null, 'dry' => false, 'quiet' => false];
for ($i = 1; $i < count($argv); $i++) {
  switch ($argv[$i]) {
    case '--logs':    $opt['logs'][] = (string) ($argv[++$i] ?? ''); break;
    case '--db':      $opt['db'] = (string) ($argv[++$i] ?? ''); break;
    case '--dry-run': $opt['dry'] = true; break;
    case '--quiet':   $opt['quiet'] = true; break;
    case '-h': case '--help':
      echo preg_replace('~^ \* ?~m', '', implode("\n", array_slice(explode("\n", file_get_contents(__FILE__)), 2, 33))), "\n";
      exit(0);
    default: fwrite(STDERR, "opzione sconosciuta: {$argv[$i]}\n"); exit(2);
  }
}

$stamp = date('Y-m-d H:i');
function say(string $m): void { global $opt; if (!$opt['quiet']) echo $m, "\n"; }
function warn(string $m): void { global $stamp; fwrite(STDERR, "$stamp avviso: $m\n"); }
function fail(string $m): never { global $stamp; fwrite(STDERR, "$stamp ERRORE: $m\n"); exit(1); }

$dbPath = $opt['db'] ?: stats_path();
$globs  = $opt['logs'] ?: [(string) ($ACCESS_LOGS ?? '/var/log/apache2/access.log*')];
$t0 = microtime(true);

/* ---- 1. lettura dei log ------------------------------------------------- */
$files = [];
foreach ($globs as $g) foreach (glob($g) ?: [] as $f) if (is_file($f)) $files[$f] = true;
$files = array_keys($files);
natsort($files);
if (!$files) fail("nessun log trovato: " . implode(', ', $globs));

$own = stats_own_hosts();
$agg = []; $lines = []; $seen = []; $read = 0; $since = null; $total = 0;
foreach ($files as $f) {
  $h = @fopen((str_ends_with($f, '.gz') ? 'compress.zlib://' : '') . $f, 'r');
  if (!$h) { warn("log non leggibile: $f"); continue; }
  $first = fgets($h);
  if ($first === false) { fclose($h); continue; }                     // vuoto
  // Stessa prima riga = stesso log con un altro nome: succede se logrotate
  // gira proprio mentre si legge. Contarlo due volte gonfierebbe quei giorni.
  $sig = sha1($first);
  if (isset($seen[$sig])) { warn("saltato, stesso contenuto di {$seen[$sig]}: " . basename($f)); fclose($h); continue; }
  $seen[$sig] = basename($f);
  $read++;
  if (preg_match('~\[(\d{2})/(\w{3})/(\d{4}):~', $first, $m) && isset(STATS_MONTHS[$m[2]])) {
    $d = sprintf('%04d-%02d-%02d', $m[3], STATS_MONTHS[$m[2]], $m[1]);
    if ($since === null || $d < $since) $since = $d;
  }
  for ($line = $first; $line !== false; $line = fgets($h)) {
    $p = stats_parse_line($line, $own);
    if ($p === null) continue;
    [$day, $short, $src, $ref, $ok] = $p;
    $k = "$short\t$src\t$ref\t$ok";
    $agg[$day][$k] = ($agg[$day][$k] ?? 0) + 1;
    $lines[$day] = ($lines[$day] ?? 0) + 1;
    $total++;
  }
  if (!feof($h)) warn("lettura interrotta prima della fine: " . basename($f));
  fclose($h);
}
if (!$read) fail("nessun log leggibile fra: " . implode(', ', $files));
// log letti ma nessuna immagine riconosciuta: piu' probabile un formato di log
// cambiato (LogFormat diverso da "combined") che due settimane senza visite
if (!$total) warn("nessuna richiesta di immagini riconosciuta in $read log: il formato del log e' ancora \"combined\"?");
ksort($agg);
$secs = round(microtime(true) - $t0, 1);
say("letti $read log in {$secs}s: $total richieste di immagini in " . count($agg) . " giorni"
  . ($agg ? " (" . array_key_first($agg) . " → " . array_key_last($agg) . ")" : ''));

/* ---- 2. fusione con lo storico, su una copia --------------------------- */
$dir = dirname($dbPath);
if (!is_dir($dir)) {
  if ($opt['dry']) fail("cartella assente: $dir");
  // 2750 e gruppo della cartella sopra (www-data): il server web legge, non scrive
  if (!@mkdir($dir, 02750, true)) fail("impossibile creare $dir");
  @chmod($dir, 02750);
  @chgrp($dir, filegroup(dirname($dir)));
}
$lock = @fopen("$dir/.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail("un altro aggiornamento e' in corso (o $dir non e' scrivibile)");

$tmp = "$dir/.stats.db.tmp-" . getmypid();
@unlink($tmp);
register_shutdown_function(fn() => @unlink($tmp));
if (is_file($dbPath)) {
  if (!@copy($dbPath, $tmp)) fail("impossibile copiare $dbPath");
  // un file rovinato non deve fermare il job per sempre: si mette da parte
  try {
    $chk = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $res = $chk->query("PRAGMA quick_check")->fetchColumn();
    $chk->query("SELECT day, short, src, ref, ok, n FROM hits LIMIT 1");
    $chk = null;
    if ($res !== 'ok') throw new RuntimeException("quick_check: $res");
  } catch (Throwable $e) {
    $chk = null;
    @unlink($tmp);
    $bad = $dbPath . '.rovinato-' . date('Ymd-His');
    if (!$opt['dry']) @rename($dbPath, $bad);
    warn("statistiche precedenti illeggibili (" . $e->getMessage() . "): " . ($opt['dry'] ? '' : "messe da parte in " . basename($bad) . ", ") . "si riparte dai log");
  }
}

try {
  $pdo = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode = DELETE');   // niente WAL: chi legge in sola lettura non deve creare -shm
  $pdo->exec('PRAGMA synchronous = OFF');       // e' una copia: se qualcosa va storto si butta
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS hits (
      day TEXT NOT NULL, short TEXT NOT NULL, src TEXT NOT NULL, ref TEXT NOT NULL,
      ok INTEGER NOT NULL, n INTEGER NOT NULL,
      PRIMARY KEY (day, short, src, ref, ok)) WITHOUT ROWID;
    CREATE INDEX IF NOT EXISTS hits_short ON hits(short, day);
    CREATE TABLE IF NOT EXISTS days (day TEXT PRIMARY KEY, lines INTEGER NOT NULL) WITHOUT ROWID;
    CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT) WITHOUT ROWID;
  ");
  $pdo->beginTransaction();
  $old = $pdo->query("SELECT day, lines FROM days")->fetchAll(PDO::FETCH_KEY_PAIR);
  $del  = $pdo->prepare("DELETE FROM hits WHERE day = ?");
  $ins  = $pdo->prepare("INSERT INTO hits(day, short, src, ref, ok, n) VALUES(?,?,?,?,?,?)");
  $dayq = $pdo->prepare("INSERT INTO days(day, lines) VALUES(?,?) ON CONFLICT(day) DO UPDATE SET lines = excluded.lines");
  $updated = []; $kept = [];
  foreach ($agg as $day => $rows) {
    if (isset($old[$day]) && (int) $old[$day] > $lines[$day]) { $kept[] = $day; continue; }   // il vecchio ne aveva viste di piu'
    $del->execute([$day]);
    foreach ($rows as $k => $n) {
      [$s, $src, $ref, $ok] = explode("\t", $k);
      $ins->execute([$day, $s, $src, $ref, (int) $ok, $n]);
    }
    $dayq->execute([$day, $lines[$day]]);
    $updated[] = $day;
  }
  $cut = stats_day_ago(STATS_KEEP_DAYS);
  $pdo->prepare("DELETE FROM hits WHERE day < ?")->execute([$cut]);
  $pdo->prepare("DELETE FROM days WHERE day < ?")->execute([$cut]);
  $meta = $pdo->query("SELECT k, v FROM meta")->fetchAll(PDO::FETCH_KEY_PAIR);
  $set = $pdo->prepare("INSERT INTO meta(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v");
  $sinceAll = min(array_filter([$meta['since'] ?? null, $since, $pdo->query("SELECT MIN(day) FROM days")->fetchColumn() ?: null]) ?: [date('Y-m-d')]);
  foreach (['updated_at' => time(), 'since' => $sinceAll, 'logs' => $read, 'version' => 1] as $k => $v) $set->execute([$k, (string) $v]);
  $pdo->commit();
  $rows = (int) $pdo->query("SELECT COUNT(*) FROM hits")->fetchColumn();
  $pdo = null;
} catch (Throwable $e) {
  $pdo = null;
  fail("scrittura delle statistiche: " . $e->getMessage());
}

say("giorni aggiornati: " . count($updated) . ($kept ? ", tenuti dallo storico perche' piu' completi: " . implode(' ', $kept) : ''));

if ($opt['dry']) {
  echo "prova a vuoto: $dbPath non modificato\n";
  exit(0);
}

/* ---- 3. al suo posto, tutto o niente ------------------------------------ */
@chmod($tmp, 0640);
@chgrp($tmp, filegroup($dir));
if ($fh = @fopen($tmp, 'r')) { fsync($fh); fclose($fh); }
if (!@rename($tmp, $dbPath)) fail("impossibile sostituire $dbPath");
if ($fh = @fopen($dir, 'r')) { @fsync($fh); fclose($fh); }

$msg = "$stamp ok: $read log, $total richieste, " . count($updated) . " giorni aggiornati"
  . ($kept ? ", " . count($kept) . " tenuti" : '') . ", $rows righe, dati dal $sinceAll, {$secs}s";
if ($opt['quiet']) echo $msg, "\n"; else say("scritto $dbPath ($rows righe, dati dal $sinceAll)");
exit(0);
