<?php
/* =========================================================================
 * bench/tests.php — batteria di prove del banco (la lancia bench/run.sh)
 * ---------------------------------------------------------------------------
 * Lavora SOLO sui percorsi del banco descritti in bench.json; si rifiuta di
 * partire se uno di essi esce dal banco o coincide con il DB reale.
 * Gruppi: migrazioni · integrita' dell'archivio copiato · upload e miniature
 * · difese (bombe, CSRF, auth, parametri ostili) · copia/elimina · permessi
 * · migrazioni su DB nuovi/vecchi/concorrenti · log degli errori.
 * ========================================================================= */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$C = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
if (!is_array($C)) { fwrite(STDERR, "uso: php tests.php bench.json\n"); exit(2); }
foreach (['www', 'db', 'log'] as $k) {
  if (!str_starts_with($C[$k], $C['bench'] . '/')) { fwrite(STDERR, "percorso fuori dal banco ($k): {$C[$k]}\n"); exit(2); }
}
if (realpath($C['db']) === realpath($C['real_db'])) { fwrite(STDERR, "il DB del banco coincide con quello reale\n"); exit(2); }

$WWW = $C['www'];
$TMP = $C['bench'] . '/img';
require $WWW . '/_migrations.php';          // solo definizioni di funzioni
$EXPECT = range(1, schema_latest());        // versioni attese dopo la migrazione
@mkdir($TMP, 0700, true);

/* ---------- strumenti ---------- */
$RESULTS = ['ok' => 0, 'fail' => 0, 'failed' => []];

/* Ogni gruppo gira in un try/catch: un'eccezione diventa una prova fallita
 * e la batteria prosegue con il gruppo successivo. */
function group(string $name, callable $body): void {
  echo "\n[$name]\n";
  try { $body(); }
  catch (Throwable $e) { check("eccezione nel gruppo: " . get_class($e), false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
}

function check(string $name, bool $ok, string $detail = ''): bool {
  global $RESULTS;
  clearstatcache();
  if ($ok) { $RESULTS['ok']++; echo "  ok  $name\n"; }
  else     { $RESULTS['fail']++; $RESULTS['failed'][] = $name; echo "  XX  $name" . ($detail !== '' ? "  — $detail" : '') . "\n"; }
  return $ok;
}

function info(string $msg): void { echo "  ··  $msg\n"; }

/* Client HTTP. 'auth' => credenziali del banco; 'session' => cookie condivisi
 * (un "browser" con la sua sessione PHP e quindi il suo token CSRF). */
function req(string $method, string $path, array $o = []): array {
  global $C;
  static $share = null;
  $share ??= curl_share_init();
  curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);

  $ch = curl_init($C['base'] . $path);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => $o['headers'] ?? [],
  ]);
  if (!empty($o['auth']))    curl_setopt($ch, CURLOPT_USERPWD, $C['user'] . ':' . $C['pass']);
  if (!empty($o['session'])) { curl_setopt($ch, CURLOPT_SHARE, $share); curl_setopt($ch, CURLOPT_COOKIEFILE, ''); }
  if (isset($o['post']))     curl_setopt($ch, CURLOPT_POSTFIELDS, $o['post']);
  $raw  = (string) curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $h = [];
  foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
    if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
  }
  return ['code' => $code, 'h' => $h, 'body' => substr($raw, $hs)];
}

function browser_get(string $path): array { return req('GET', $path, ['auth' => true, 'session' => true]); }
function browser_post(string $path, array $fields): array { return req('POST', $path, ['auth' => true, 'session' => true, 'post' => $fields]); }
function browser_post_json(string $path, array $fields): array {
  $r = req('POST', $path, ['auth' => true, 'session' => true, 'post' => $fields, 'headers' => ['Accept: application/json']]);
  $r['json'] = json_decode($r['body'], true);
  return $r;
}
/* Upload dal caricatore della pagina; restituisce [risposta, json]. */
function upload_json(string $file, string $mime, array $extra = []): array {
  $r = browser_post_json('/gallery/upload.php', ['csrf' => csrf(), 'img' => new CURLFile($file, $mime, basename($file))] + $extra);
  return [$r, $r['json']];
}
/* Tutti gli attributi data-snip di una pagina, decodificati. */
function snips(string $html): array {
  preg_match_all('~data-snip="([^"]*)"~', $html, $m);
  return array_values(array_filter(array_map(fn($a) => json_decode(html_entity_decode($a, ENT_QUOTES), true), $m[1])));
}

function csrf(): string {
  $r = browser_get('/gallery/');
  return preg_match('~name="csrf" value="([0-9a-f]{64})"~', $r['body'], $m) ? $m[1] : '';
}

/* DB del banco: connessione nuova a ogni uso, per non tenere lock. */
function db_bench(?string $path = null): PDO {
  global $C;
  $pdo = new PDO('sqlite:' . ($path ?? $C['db']), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  return $pdo;
}
function q1(string $sql, array $args = [], ?string $path = null) {
  $st = db_bench($path)->prepare($sql); $st->execute($args); return $st->fetchColumn();
}
function table_exists(string $name, ?string $path = null): bool {
  return (bool) q1("SELECT 1 FROM sqlite_master WHERE name=?", [$name], $path);
}
function versions(?string $path = null): array {
  if (!table_exists('schema_version', $path)) return [];
  return db_bench($path)->query("SELECT version FROM schema_version ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
}
function images_digest(?string $path = null): string {
  $rows = db_bench($path)->query("SELECT id,short,filename,mime,size,width,height,title,alt,delkey,created_at,folder FROM images ORDER BY id")->fetchAll();
  return sha1(json_encode($rows));
}

/* Il server rilegge secret.php a ogni richiesta: cosi' si cambia DB al volo. */
function use_db(string $path): void {
  global $C, $WWW;
  $s = file_get_contents("$WWW/secret.php");
  $s = preg_replace("~'DB_PATH'\s*=>\s*'[^']*'~", "'DB_PATH'       => '" . $path . "'", $s);
  file_put_contents("$WWW/secret.php", $s);
  $eff = json_decode(req('GET', '/__bench/config')['body'], true)['db'] ?? '?';
  if ($eff !== $path) throw new RuntimeException("il server usa ancora $eff invece di $path");
}

function make_img(string $name, int $w, int $h, string $fmt): string {
  global $TMP;
  $im = imagecreatetruecolor($w, $h);
  imagesavealpha($im, true);
  imagefill($im, 0, 0, imagecolorallocatealpha($im, 40, 90, 160, $fmt === 'png' ? 60 : 0));
  imagefilledrectangle($im, 0, 0, intdiv($w, 2), intdiv($h, 2), imagecolorallocate($im, 200, 60, 40));
  $p = "$TMP/$name.$fmt";
  match ($fmt) { 'jpg' => imagejpeg($im, $p, 90), 'png' => imagepng($im, $p), 'gif' => imagegif($im, $p), 'webp' => imagewebp($im, $p, 85) };
  imagedestroy($im);
  return $p;
}

function upload(string $file, string $mime, array $extra = []): array {
  $tok = csrf();
  $r = browser_post('/gallery/upload.php', ['csrf' => $tok, 'img' => new CURLFile($file, $mime, basename($file))] + $extra);
  $short = preg_match('~[?&]ok=([A-Za-z0-9_-]+)~', $r['h']['location'] ?? '', $m) ? $m[1] : null;
  return [$r, $short];
}

function row(string $short, ?string $path = null): ?array {
  $st = db_bench($path)->prepare("SELECT * FROM images WHERE short=?"); $st->execute([$short]);
  return $st->fetch() ?: null;
}

function files_in(string $dir): array { clearstatcache(); return array_values(array_filter(scandir($dir), fn($f) => $f[0] !== '.')); }

function log_size(): int { global $C; clearstatcache(); return is_file($C['log']) ? filesize($C['log']) : 0; }
function log_since(int $off): string { global $C; return is_file($C['log']) ? (string) file_get_contents($C['log'], false, null, $off) : ''; }

/* Stato condiviso fra i gruppi */
$live_shorts = []; $first = ''; $uploaded = []; $n_img = 0; $copy_migrated = false;

/* ======================================================================= */
group('Migrazioni sulla copia del DB reale', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $live_shorts = db_bench()->query("SELECT short FROM images ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  $digest0 = images_digest();
  // La copia puo' essere indietro (codice nuovo non ancora rilasciato: la
  // migrazione deve partire) o gia' allineata (deve restare com'e').
  $v0 = versions();
  $copy_migrated = $v0 !== $EXPECT;
  info('versione della copia: ' . json_encode($v0) . ($copy_migrated ? ' -> la prima richiesta deve migrarla' : ' (gia\' allineata al codice)'));
  $first = $live_shorts[0] ?? '';
  $r = req('GET', "/gallery/i/$first");
  check("prima richiesta (pubblica /i/$first) risponde 200", $r['code'] === 200, "HTTP {$r['code']}");
  check('dopo la prima richiesta: schema_version = ' . json_encode($EXPECT), versions() === $EXPECT, json_encode(versions()));
  check('righe di images invariate (contenuto identico)', images_digest() === $digest0);
  $n_img = (int) q1("SELECT COUNT(*) FROM images");
  check('indice FTS allineato: una voce per immagine', (int) q1("SELECT COUNT(*) FROM images_fts_docsize") === $n_img);
  check('integrity_check del DB', q1("PRAGMA integrity_check") === 'ok');
  try { db_bench()->exec("INSERT INTO images_fts(images_fts, rank) VALUES('integrity-check', 1)"); $fts_ok = true; }
  catch (Throwable $e) { $fts_ok = false; }
  check('integrity-check dell\'indice FTS rispetto a images', $fts_ok);
  req('GET', "/gallery/i/$first"); req('GET', '/gallery/', ['auth' => true]);
  check('richieste successive non rimigrano', versions() === $EXPECT);
  // v3: impronta sha256 di ogni originale presente su disco, giusta
  $bad = []; $n = 0;
  foreach (db_bench()->query("SELECT short, filename, sha256 FROM images") as $row) {
    if (!is_file("$WWW/uploads/{$row['filename']}")) continue;
    $n++;
    if ($row['sha256'] !== hash_file('sha256', "$WWW/uploads/{$row['filename']}")) $bad[] = $row['short'];
  }
  check("impronte sha256 complete e corrette ($n originali)", $n > 0 && !$bad, implode(',', array_slice($bad, 0, 5)));
  // v2: le immagini piu' vecchie, prima assenti dall'indice, ora si trovano
  $oldest = db_bench()->query("SELECT short, title FROM images WHERE COALESCE(title,'') <> '' ORDER BY id LIMIT 1")->fetch();
  if ($oldest) {
    $r = req('GET', '/gallery/?q=' . rawurlencode($oldest['title']), ['auth' => true]);
    check("la ricerca trova l'immagine piu' vecchia (\"{$oldest['title']}\")", str_contains($r['body'], "/i/{$oldest['short']}"));
  }
});

/* ======================================================================= */
group('Archivio copiato: ogni immagine e miniatura servita', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $bad_full = []; $bad_thumb = [];
  foreach (db_bench()->query("SELECT short, filename, mime FROM images ORDER BY id") as $row) {
    $r = req('GET', "/gallery/i/{$row['short']}");
    if ($r['code'] !== 200 || $r['body'] !== @file_get_contents("$WWW/uploads/{$row['filename']}") || ($r['h']['content-type'] ?? '') !== $row['mime']) $bad_full[] = $row['short'];
    $t = req('GET', "/gallery/t/{$row['short']}");
    if ($t['code'] !== 200 || !@getimagesizefromstring($t['body'])) $bad_thumb[] = $row['short'];
  }
  check("originali identici ai file ($n_img)", !$bad_full, implode(',', array_slice($bad_full, 0, 5)));
  check("miniature valide ($n_img)", !$bad_thumb, implode(',', array_slice($bad_thumb, 0, 5)));
});

/* ======================================================================= */
group('Upload e pipeline delle miniature', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $cases = [
    ['jpg',  'image/jpeg', 1600, 1200, [320, 240]],
    ['png',  'image/png',   640,  480, [320, 240]],
    ['gif',  'image/gif',   300,  200, [300, 200]],
    ['webp', 'image/webp',  500,  500, [320, 320]],
    ['png',  'image/png',  3000,    2, [320, 1]],     // allungata: con il vecchio regen_thumbs -> altezza 0
    ['png',  'image/png',     2, 3000, [1, 320]],
  ];
  $uploaded = [];
  foreach ($cases as $i => [$ext, $mime, $w, $h, $exp]) {
    $f = make_img("case$i", $w, $h, $ext);
    [$r, $short] = upload($f, $mime, ['folder' => 'Banco', 'title' => "prova $i {$w}x{$h}", 'alt' => 'alt di prova']);
    $label = "$ext {$w}×{$h}";
    if (!check("upload $label -> 303", $r['code'] === 303 && $short !== null, "HTTP {$r['code']} " . substr($r['body'], 0, 80))) continue;
    $row = row($short);
    check("  riga DB corretta ($label)", $row && $row['mime'] === $mime && (int)$row['width'] === $w && (int)$row['height'] === $h && $row['folder'] === 'Banco');
    $orig = "$WWW/uploads/{$row['filename']}";
    check("  originale salvato byte per byte ($label)", is_file($orig) && sha1_file($orig) === sha1_file($f));
    $tp = "$WWW/thumbs/{$row['filename']}";
    $gi = @getimagesize($tp);
    check("  miniatura {$exp[0]}×{$exp[1]} ($label)", $gi && [$gi[0], $gi[1]] === $exp, $gi ? "{$gi[0]}×{$gi[1]}" : 'assente');
    $t = req('GET', "/gallery/t/$short");
    check("  /t/ risponde 200 $mime ($label)", $t['code'] === 200 && ($t['h']['content-type'] ?? '') === $mime);
    $uploaded[$short] = $row + ['exp' => $exp, 'label' => $label];
  }
  check('nessun file temporaneo rimasto in thumbs/', !glob("$WWW/thumbs/*.tmp-*"));

  // La stessa immagine allungata attraverso le altre tre strade della pipeline
  $long = array_key_first(array_filter($uploaded, fn($u) => $u['exp'] === [320, 1]));
  if ($long) {
    $tp = "$WWW/thumbs/{$uploaded[$long]['filename']}";

    unlink($tp);
    $t = req('GET', "/gallery/t/$long");
    $gi = @getimagesize($tp);
    check('i.php rigenera al volo la miniatura mancante (320×1)', $t['code'] === 200 && $gi && [$gi[0], $gi[1]] === [320, 1]);

    unlink($tp);
    $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'retthumb', 'short' => $long]);
    $gi = @getimagesize($tp);
    check('admin "Rigenera thumb" (320×1)', $r['code'] === 302 && $gi && [$gi[0], $gi[1]] === [320, 1], "HTTP {$r['code']}");

    unlink($tp);
    $out = shell_exec('cd ' . escapeshellarg($WWW) . ' && php -d error_log=' . escapeshellarg($C['log']) . ' regen_thumbs.php 2>&1');
    $gi = @getimagesize($tp);
    check('regen_thumbs.php da CLI (320×1, prima andava in errore)', $gi && [$gi[0], $gi[1]] === [320, 1], trim((string)$out));
  }

  // Equivalenza: la pipeline unica produce le stesse miniature di prima?
  $before = [];
  foreach ($live_shorts as $s) { $fn = row($s)['filename']; $before[$fn] = @sha1_file("$WWW/thumbs/$fn"); }
  $out = trim((string) shell_exec('cd ' . escapeshellarg($WWW) . ' && php -d error_log=' . escapeshellarg($C['log']) . ' regen_thumbs.php --all 2>&1'));
  check('regen_thumbs.php --all: nessuna miniatura non riuscita', str_contains($out, ' 0 non riuscite'), $out);
  $same = 0; $diffdim = [];
  foreach ($before as $fn => $h) {
    if (@sha1_file("$WWW/thumbs/$fn") === $h) { $same++; continue; }
    $diffdim[] = $fn;
  }
  // Le differenze attese sono miniature fatte da codice piu' vecchio (es. il
  // regen_thumbs.php che troncava le misure: 1 px in meno su un lato).
  info(sprintf('miniature dell\'archivio rigenerate identiche alle esistenti: %d su %d%s',
    $same, count($before), $diffdim ? ' (diverse: ' . implode(', ', array_slice($diffdim, 0, 6)) . (count($diffdim) > 6 ? '…' : '') . ')' : ''));
});

/* ======================================================================= */
group('API', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $f = make_img('api', 400, 300, 'jpg');
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'headers' => ['X-Api-Token: ' . $C['token']], 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg'), 'folder' => 'Banco']]);
  $j = json_decode($r['body'], true);
  check('upload API con X-Api-Token -> JSON ok', $r['code'] === 200 && ($j['ok'] ?? false) === true && str_starts_with($j['url'] ?? '', $C['base'] . '/gallery/i/'), "HTTP {$r['code']} " . substr($r['body'], 0, 80));
  check('  JSON API: miniatura /t/, misure, link di cancellazione, duplicate=false',
    ($j['thumb'] ?? '') === $C['base'] . '/gallery/t/' . ($j['id'] ?? '') && ($j['width'] ?? 0) === 400 && ($j['height'] ?? 0) === 300
    && str_contains($j['delete'] ?? '', '/delete.php?c=') && ($j['duplicate'] ?? null) === false);
  if (!empty($j['id'])) $api_short = $j['id'];
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'headers' => ['X-Api-Token: sbagliato-sbagliato-sbagliato'], 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('token errato -> 401', $r['code'] === 401);
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('senza token -> 401', $r['code'] === 401);
  $r = req('POST', '/gallery/api/upload.php', ['headers' => ['X-Api-Token: ' . $C['token']], 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('token giusto ma senza credenziali Apache -> 401', $r['code'] === 401);
});

/* ======================================================================= */
group('Difese', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $n_up = count(files_in("$WWW/uploads")); $n_th = count(files_in("$WWW/thumbs"));

  $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
  $bomb = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 30000, 30000, 8, 3, 0, 0, 0))
        . $chunk('PLTE', "\0\0\0") . $chunk('IDAT', gzcompress(str_repeat("\0", 300000), 9)) . $chunk('IEND', '');
  file_put_contents("$TMP/bomb.png", $bomb);
  [$r] = upload("$TMP/bomb.png", 'image/png');
  check('immagine-bomba 30000×30000 (' . round(strlen($bomb) / 1024) . ' KB) -> 413', $r['code'] === 413, "HTTP {$r['code']}");

  file_put_contents("$TMP/finta.jpg", "<?php echo 'ciao'; ?>\n");
  [$r] = upload("$TMP/finta.jpg", 'image/jpeg');
  check('file non immagine con estensione .jpg -> 415', $r['code'] === 415, "HTTP {$r['code']}");

  check('nessun file lasciato da upload respinti', count(files_in("$WWW/uploads")) === $n_up && count(files_in("$WWW/thumbs")) === $n_th);

  $f = make_img('csrf', 100, 100, 'jpg');
  $r = browser_post('/gallery/upload.php', ['img' => new CURLFile($f, 'image/jpeg', 'x.jpg')]);
  check('upload senza token CSRF -> 419', $r['code'] === 419, "HTTP {$r['code']}");
  $r = browser_post('/gallery/upload.php', ['csrf' => str_repeat('0', 64), 'img' => new CURLFile($f, 'image/jpeg', 'x.jpg')]);
  check('upload con token CSRF errato -> 419', $r['code'] === 419, "HTTP {$r['code']}");
  $r = browser_post('/gallery/upload.php', ['csrf[]' => 'x', 'img' => new CURLFile($f, 'image/jpeg', 'x.jpg')]);
  check('upload con csrf[] -> 419', $r['code'] === 419, "HTTP {$r['code']}");
  $victim = array_key_first($uploaded);
  $r = browser_post('/gallery/admin/index.php', ['act' => 'delete', 'short' => $victim]);
  check('admin elimina senza CSRF -> 419 e la riga resta', $r['code'] === 419 && row($victim) !== null, "HTTP {$r['code']}");

  foreach (['/gallery/', '/gallery/admin/', '/gallery/upload.php', '/gallery/delete.php', '/gallery/_fpm_check.php'] as $p) {
    $r = req('GET', $p);
    check("senza credenziali $p -> 401", $r['code'] === 401, "HTTP {$r['code']}");
  }
  $r = req('GET', "/gallery/i/$first");
  check('senza credenziali /i/ resta pubblico -> 200', $r['code'] === 200);

  $hostile = [
    ['/gallery/?q[]=a', 200], ['/gallery/?f[]=x', 200], ['/gallery/?p[]=1', 200], ['/gallery/?ok[]=1', 200],
    ['/gallery/?p=99999999', 200], ['/gallery/admin/?q[]=a', 200], ['/gallery/admin/?p=99999999', 200],
    ['/gallery/?q=%22', 200], ['/gallery/?q=folder:%22x', 200], ['/gallery/?q=NEAR(', 200],
    ['/gallery/?q=a*b', 200], ['/gallery/?q=%27%20OR%201=1%20--', 200], ['/gallery/?q=id:', 200],
    ['/gallery/delete.php?c[]=a&k[]=b', 400], ['/gallery/i.php?c[]=a', 404], ['/gallery/i.php?c=../../secret', 404],
  ];
  $off = log_size();
  foreach ($hostile as [$p, $exp]) {
    $r = req('GET', $p, ['auth' => true]);
    check("$p -> $exp", $r['code'] === $exp, "HTTP {$r['code']}");
  }
  check('parametri ostili: nessun warning nel log', !preg_match('~PHP (Warning|Notice|Deprecated|Fatal)~', log_since($off)));

  $sid = array_key_first($uploaded);
  foreach (['/gallery/' => 'galleria', '/gallery/admin/' => 'admin'] as $p => $where) {
    $r = req('GET', $p . '?q=' . rawurlencode("id:$sid"), ['auth' => true]);
    check("ricerca id:CODICE in $where trova l'immagine", $r['code'] === 200 && str_contains($r['body'], "/i/$sid"));
  }
  $r = req('GET', '/gallery/?q=' . rawurlencode('prova 4'), ['auth' => true]);
  check('ricerca FTS trova un\'immagine appena caricata', $r['code'] === 200 && str_contains($r['body'], 'prova 4'));
});

/* ======================================================================= */
group('Doppioni, risposta JSON, snippet', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $count = fn() => (int) q1("SELECT COUNT(*) FROM images");
  $f = make_img('json', 700, 300, 'png');

  // a) il caricatore della pagina riceve JSON
  [$r, $j] = upload_json($f, 'image/png', ['folder' => 'Banco', 'title' => 'json prova', 'alt' => 'alt json']);
  $id = $j['id'] ?? '';
  check('caricatore: risposta JSON ok, duplicate=false', $r['code'] === 200 && ($j['ok'] ?? false) === true && ($j['duplicate'] ?? null) === false, "HTTP {$r['code']} " . substr($r['body'], 0, 100));
  check('  JSON: url /i/, miniatura /t/, misure, album, titolo',
    ($j['url'] ?? '') === $C['base'] . "/gallery/i/$id" && ($j['thumb'] ?? '') === $C['base'] . "/gallery/t/$id"
    && ($j['width'] ?? 0) === 700 && ($j['height'] ?? 0) === 300 && ($j['folder'] ?? '') === 'Banco' && ($j['title'] ?? '') === 'json prova');
  check('  JSON del caricatore senza link di cancellazione (solo API)', !isset($j['delete']));
  check('  impronta salvata nella riga', $id !== '' && (row($id)['sha256'] ?? '') === hash_file('sha256', $f));

  // b) la stessa immagine di nuovo: link esistente, nessun file o riga in piu'
  $n_up = count(files_in("$WWW/uploads")); $rows = $count();
  [$r, $j2] = upload_json($f, 'image/png', ['folder' => 'Banco']);
  check('stessa immagine di nuovo: duplicate=true, stesso link', ($j2['duplicate'] ?? null) === true && ($j2['id'] ?? '') === $id, json_encode($j2));
  check('  nessun file e nessuna riga in piu\'', count(files_in("$WWW/uploads")) === $n_up && $count() === $rows);
  [$r, $s2] = upload($f, 'image/png', ['folder' => 'Banco']);
  check('stessa immagine dal form classico: 303 verso ?ok=ID&dup=1', $r['code'] === 303 && str_contains($r['h']['location'] ?? '', "ok=$id&dup=1"), $r['h']['location'] ?? '');
  $page = req('GET', "/gallery/?ok=$id&dup=1", ['auth' => true]);
  check('  la pagina lo dice e mostra gli snippet', str_contains($page['body'], 'Già in archivio') && in_array($id, array_column(snips($page['body']), 'id'), true));
  check('  ?ok inesistente e dup[] non rompono la pagina',
    req('GET', '/gallery/?ok=nonesiste&dup[]=1', ['auth' => true])['code'] === 200);

  // c) doppione di un'immagine vera dell'archivio copiato
  $real = db_bench()->query("SELECT short, filename, mime, COALESCE(folder,'') AS folder FROM images ORDER BY id LIMIT 1")->fetch();
  copy("$WWW/uploads/{$real['filename']}", "$TMP/vera");
  [$r, $j3] = upload_json("$TMP/vera", $real['mime'], ['folder' => 'Altro album']);
  check("immagine gia' in archivio ({$real['short']}): restituito il suo link", ($j3['duplicate'] ?? null) === true && ($j3['id'] ?? '') === $real['short'], json_encode($j3));

  // d) fra piu' righe sullo stesso file vince quella dello stesso album
  browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'copy', 'short' => $id, 'dest_folder' => 'Copie2']);
  $copy = (string) q1("SELECT short FROM images WHERE sha256=? AND folder='Copie2'", [hash_file('sha256', $f)]);
  check('"Copia" in admin conserva l\'impronta', $copy !== '' && row($copy)['sha256'] === row($id)['sha256']);
  [$r, $j4] = upload_json($f, 'image/png', ['folder' => 'Copie2']);
  check('  doppione caricato nell\'album della copia -> link della copia', ($j4['id'] ?? '') === $copy, json_encode($j4));
  [$r, $j5] = upload_json($f, 'image/png', ['folder' => 'Ancora un altro']);
  check('  doppione in un altro album -> la riga piu\' vecchia', ($j5['id'] ?? '') === $id, json_encode($j5));

  // e) API: anche li' il doppione, con il link di cancellazione
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'headers' => ['X-Api-Token: ' . $C['token']],
    'post' => ['file' => new CURLFile($f, 'image/png', 'x.png')]]);
  $ja = json_decode($r['body'], true);
  check('API: doppione riconosciuto, con link di cancellazione', ($ja['duplicate'] ?? null) === true && in_array($ja['id'] ?? '', [$id, $copy], true) && isset($ja['delete']));

  // f) errori in JSON per il caricatore
  [$r, $je] = upload_json("$TMP/bomb.png", 'image/png');
  check('bomba dal caricatore: 413 con errore JSON leggibile', $r['code'] === 413 && ($je['ok'] ?? null) === false && str_contains($je['error'] ?? '', 'megapixel'), $r['body']);
  [$r, $je] = upload_json("$TMP/finta.jpg", 'image/jpeg');
  check('file non immagine dal caricatore: 415 con errore JSON', $r['code'] === 415 && ($je['ok'] ?? null) === false);
  $r = req('POST', '/gallery/upload.php', ['auth' => true, 'session' => true, 'headers' => ['Accept: application/json'],
    'post' => ['img' => new CURLFile($f, 'image/png', 'x.png')]]);
  check('caricatore senza token CSRF: 419', $r['code'] === 419);

  // g) snippet nella pagina: dati giusti e nessuna iniezione
  $evil_title = '"><img src=x onerror=alert(1)> [a](b)';
  $evil_alt   = "</script><b>alt</b> & ]";
  [$r, $jx] = upload_json(make_img('xss', 333, 111, 'png'), 'image/png', ['title' => $evil_title, 'alt' => $evil_alt, 'folder' => 'Banco']);
  $xid = $jx['id'] ?? '';
  foreach (['/gallery/?f=Banco' => 'galleria', '/gallery/admin/?q=' . rawurlencode("id:$xid") => 'admin'] as $path => $where) {
    $html = req('GET', $path, ['auth' => true])['body'];
    $sn = array_values(array_filter(snips($html), fn($d) => ($d['id'] ?? '') === $xid));
    check("$where: data-snip con titolo e alt esatti, misure e indirizzi",
      $sn && $sn[0]['title'] === $evil_title && $sn[0]['alt'] === $evil_alt && $sn[0]['width'] === 333
      && $sn[0]['url'] === $C['base'] . "/gallery/i/$xid" && $sn[0]['thumb'] === $C['base'] . "/gallery/t/$xid");
    check("$where: nessun tag iniettato nell'HTML", !str_contains($html, '<img src=x') && !str_contains($html, '<b>alt</b>'));
    check("$where: selettore del formato presente", str_contains($html, 'data-snip-format'));
  }
});

/* ======================================================================= */
group('Copia, sposta, modifica, elimina', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $orig = array_key_first($uploaded);
  $fn = row($orig)['filename'];
  $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'copy', 'short' => $orig, 'dest_folder' => 'Copie']);
  $copy = q1("SELECT short FROM images WHERE filename=? AND short<>?", [$fn, $orig]);
  check('copia: nuova riga sullo stesso file', $r['code'] === 302 && $copy && row($copy)['folder'] === 'Copie');
  $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'delete', 'short' => (string)$copy]);
  check('eliminare la copia lascia l\'originale servito e il file su disco',
    row((string)$copy) === null && req('GET', "/gallery/i/$orig")['code'] === 200 && is_file("$WWW/uploads/$fn"));
  $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'move', 'short' => $orig, 'dest_folder' => 'Spostate']);
  check('sposta in altro album', row($orig)['folder'] === 'Spostate');
  $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'meta', 'short' => $orig, 'folder' => 'Spostate', 'title' => 'gabbiano solitario', 'alt' => 'x']);
  $r = req('GET', '/gallery/?q=gabbiano', ['auth' => true]);
  check('titolo modificato e ritrovato dalla ricerca FTS', row($orig)['title'] === 'gabbiano solitario' && str_contains($r['body'], 'gabbiano solitario'));
  $key = row($orig)['delkey'];
  $r = req('GET', "/gallery/delete.php?c=$orig&k=" . str_repeat('0', 16), ['auth' => true]);
  check('delete.php con chiave errata -> 403', $r['code'] === 403);
  $r = req('GET', "/gallery/delete.php?c=$orig&k=$key", ['auth' => true]);
  check('delete.php con chiave giusta: riga, file e miniatura spariscono',
    $r['code'] === 200 && row($orig) === null && !is_file("$WWW/uploads/$fn") && !is_file("$WWW/thumbs/$fn"));
});

/* ======================================================================= */
group('Permessi (simulati sul banco)', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  // 1) Codice non scrivibile, come sul live: l'app deve funzionare lo stesso.
  $writable = ["$WWW/uploads", "$WWW/thumbs"];
  shell_exec('chmod -R a-w ' . escapeshellarg($WWW) . ' && chmod u+w ' . implode(' ', array_map('escapeshellarg', $writable))
    . ' && find ' . implode(' ', array_map('escapeshellarg', $writable)) . ' -type f -exec chmod u+w {} +');
  $f = make_img('ro', 800, 600, 'jpg');
  [$r, $s] = upload($f, 'image/jpeg', ['folder' => 'Banco']);
  check('codice in sola lettura: upload funziona', $r['code'] === 303 && $s && is_file("$WWW/thumbs/" . row($s)['filename']), "HTTP {$r['code']}");
  $r = browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'meta', 'short' => (string)$s, 'folder' => 'Banco', 'title' => 'ro', 'alt' => '']);
  check('codice in sola lettura: modifica da admin funziona', $r['code'] === 302 && row((string)$s)['title'] === 'ro');
  check('codice in sola lettura: /i/ e /t/ funzionano', req('GET', "/gallery/i/$s")['code'] === 200 && req('GET', "/gallery/t/$s")['code'] === 200);
  shell_exec('chmod -R u+w ' . escapeshellarg($WWW));

  // 2) DB non scrivibile: l'upload deve fallire pulito, senza file orfani.
  $n_up = count(files_in("$WWW/uploads")); $n_th = count(files_in("$WWW/thumbs"));
  $db_mode = fn(int $m) => array_map(fn($f) => is_file($f) && chmod($f, $m), [$C['db'], $C['db'] . '-wal', $C['db'] . '-shm']);
  $db_mode(0444);
  $off = log_size();
  [$r] = upload(make_img('rodb', 300, 300, 'png'), 'image/png');
  check('DB in sola lettura: upload -> 500 pulito', $r['code'] === 500, "HTTP {$r['code']}");
  check('DB in sola lettura: nessun file orfano', count(files_in("$WWW/uploads")) === $n_up && count(files_in("$WWW/thumbs")) === $n_th);
  check('DB in sola lettura: errore registrato nel log', str_contains(log_since($off), 'insert fallito'));
  check('DB in sola lettura: la consegna immagini continua (200)', req('GET', "/gallery/i/$first")['code'] === 200);
  $db_mode(0644);
});

/* ======================================================================= */
group('Migrazioni su database diversi', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  // a) installazione nuova: DB che non esiste
  $fresh = $C['bench'] . '/db/nuovo.db';
  use_db($fresh);
  $r = req('GET', '/gallery/', ['auth' => true]);
  check('DB nuovo: la prima pagina risponde 200', $r['code'] === 200, "HTTP {$r['code']}");
  check('DB nuovo: schema creato (images, images_fts, schema_version completa)',
    table_exists('images', $fresh) && table_exists('images_fts', $fresh) && versions($fresh) === $EXPECT);
  [$r, $s] = upload(make_img('nuovo', 200, 100, 'jpg'), 'image/jpeg', ['title' => 'airone cenerino']);
  $r = req('GET', '/gallery/?q=airone', ['auth' => true]);
  check('DB nuovo: upload e ricerca FTS', $s && str_contains($r['body'], 'airone cenerino'));

  // b) DB vecchio: senza colonna folder e senza FTS, con righe gia' presenti
  $old = $C['bench'] . '/db/vecchio.db';
  $o = db_bench($old);
  $o->exec("CREATE TABLE images (id INTEGER PRIMARY KEY, short TEXT UNIQUE NOT NULL, filename TEXT NOT NULL,
    mime TEXT NOT NULL, size INTEGER NOT NULL, width INTEGER, height INTEGER, title TEXT, alt TEXT,
    delkey TEXT NOT NULL, created_at INTEGER NOT NULL)");
  foreach (['tramonto rosso', 'faro di notte', 'porto vecchio'] as $k => $t) {
    $o->prepare("INSERT INTO images(short,filename,mime,size,width,height,title,alt,delkey,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
      ->execute(["old$k", "old$k.jpg", 'image/jpeg', 1, 1, 1, $t, null, 'k', time()]);
  }
  $o = null;
  use_db($old);
  $r = req('GET', '/gallery/?q=tramonto', ['auth' => true]);
  $cols = array_column(db_bench($old)->query("PRAGMA table_info(images)")->fetchAll(), 'name');
  check('DB vecchio: colonna folder aggiunta', in_array('folder', $cols, true));
  check('DB vecchio: indice FTS popolato con le righe esistenti', (int) q1("SELECT COUNT(*) FROM images_fts_docsize", [], $old) === 3);
  check('DB vecchio: la ricerca trova le righe preesistenti', $r['code'] === 200 && str_contains($r['body'], 'tramonto rosso'));
  check('DB vecchio: schema_version completa', versions($old) === $EXPECT);

  // c) richieste concorrenti sul primo avvio: una sola migrazione, nessun errore.
  //    Cinque DB nuovi, 16 richieste insieme ciascuno (le gare sono casuali).
  $bad = []; $multi = [];
  for ($round = 1; $round <= 5; $round++) {
    $conc = $C['bench'] . "/db/concorrente$round.db";
    use_db($conc);
    $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < 16; $i++) {
      $ch = curl_init($C['base'] . ($i % 2 ? '/gallery/' : "/gallery/i/$first"));
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $C['user'] . ':' . $C['pass'], CURLOPT_TIMEOUT => 30]);
      curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1); } while ($running && $st === CURLM_OK);
    foreach ($hs as $ch) {
      $c = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      if ($c >= 500 || $c === 0) $bad[] = "giro $round: HTTP $c";
    }
    if (versions($conc) !== $EXPECT) $multi[] = "giro $round: " . json_encode(versions($conc));
  }
  check('5×16 richieste concorrenti su DB nuovi: nessun errore', !$bad, implode(', ', $bad));
  check('5×16 richieste concorrenti: ogni versione registrata una volta sola', !$multi, implode(', ', $multi));

  use_db($C['db']);
  check('banco riportato sulla copia del DB reale', req('GET', "/gallery/i/$first")['code'] === 200);
});

/* ======================================================================= */
group('Log degli errori PHP', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $log = log_since(0);
  preg_match_all('~^.*PHP (Warning|Notice|Deprecated|Fatal error|Parse error).*$~m', $log, $m);
  check('nessun warning, notice o errore fatale', !$m[0], implode(' | ', array_slice($m[0], 0, 3)));
  $migr = substr_count($log, 'schema portato alla versione');
  $want = 7 + ($copy_migrated ? 1 : 0);   // DB nuovo + DB vecchio + 5 concorrenti (+ la copia, se era indietro)
  info("migrazioni registrate nel log: $migr (attese $want)");
  check('una sola migrazione per database', $migr === $want, "trovate $migr");
});

/* ======================================================================= */
printf("\n%d prove superate, %d fallite\n", $RESULTS['ok'], $RESULTS['fail']);
if ($RESULTS['fail']) { echo "fallite:\n  - " . implode("\n  - ", $RESULTS['failed']) . "\n"; exit(1); }
exit(0);
