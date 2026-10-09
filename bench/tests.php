<?php
/* =========================================================================
 * bench/tests.php — batteria di prove del banco (la lancia bench/run.sh)
 * ---------------------------------------------------------------------------
 * Lavora SOLO sui percorsi del banco descritti in bench.json; si rifiuta di
 * partire se uno di essi esce dal banco o coincide con il DB reale.
 * Gruppi: migrazioni · integrita' dell'archivio copiato · upload e miniature
 * · difese (bombe, CSRF, auth, parametri ostili) · copia/elimina · statistiche
 * d'uso dai log · API, private, link a scadenza · Telegram e screenshot ·
 * permessi · migrazioni su DB nuovi/vecchi/concorrenti · log degli errori.
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
require $WWW . '/_images.php';              // per le prove unitarie: definizioni, nessun accesso al DB
require $WWW . '/_stats.php';               // idem: lettura dei log e classificazione
@mkdir($TMP, 0700, true);
// stesso fuso dell'app (config.php): i giorni dei log finti devono coincidere con i suoi
if (get_cfg_var('date.timezone') === false && ($__tz = @readlink('/etc/localtime')) && preg_match('~zoneinfo/(.+)$~', $__tz, $__m)) {
  date_default_timezone_set($__m[1]);
}

/* ---------- log di Apache finti (statistiche d'uso) ---------- */
const UA_FF = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';
const UA_CH = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
/* Una riga nel formato "combined", come la scrive Apache; $ago = giorni fa. */
function alog(int $ago, string $hms, string $target, int $status = 200, string $ref = '-', string $ua = UA_FF, string $user = '-', string $method = 'GET'): string {
  $t = (new DateTimeImmutable('today'))->modify("-$ago days");
  return sprintf('203.0.113.%d - %s [%s:%s %s] "%s %s HTTP/1.1" %d 1234 "%s" "%s"', random_int(1, 250), $user,
    $t->format('d/M/Y'), $hms, $t->format('O'), $method, $target, $status, $ref, $ua) . "\n";
}

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
/* Campi ids[0], ids[1]... per i POST (curl non accetta array annidati). */
function ids_fields(array $ids, string $key = 'ids'): array {
  $out = [];
  foreach (array_values($ids) as $i => $v) $out["{$key}[$i]"] = $v;
  return $out;
}
/* POST al pannello admin con il token CSRF della sessione del "browser". */
function admin_post(array $fields, string $view = ''): array {
  return browser_post('/gallery/admin/index.php' . ($view !== '' ? "?v=$view" : ''), ['csrf' => csrf()] + $fields);
}
function trash_count_bench(): int { return (int) q1("SELECT COUNT(*) FROM images WHERE deleted_at IS NOT NULL"); }
/* Codici delle immagini mostrate in una pagina (dagli snippet). */
function page_ids(string $path): array {
  return array_column(snips(req('GET', $path, ['auth' => true])['body']), 'id');
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

/* Fabbrica di immagini con metadati noti (orientamento, GPS EXIF, XMP). */
function fx_tiff(int $orientation, bool $gps): string {
  $e = fn($tag, $type, $cnt, $val) => pack('vvV', $tag, $type, $cnt) . $val;
  $n0 = ($orientation ? 1 : 0) + ($gps ? 1 : 0);
  $gps_off = 8 + 2 + 12 * $n0 + 4;
  $t = "II*\0" . pack('V', 8) . pack('v', $n0);
  if ($orientation) $t .= $e(0x0112, 3, 1, pack('vv', $orientation, 0));
  if ($gps) $t .= $e(0x8825, 4, 1, pack('V', $gps_off));
  $t .= pack('V', 0);
  if ($gps) {
    $d = $gps_off + 2 + 12 * 4 + 4;
    $r = fn($a, $b) => pack('VV', $a, $b);
    $t .= pack('v', 4) . $e(1, 2, 2, "N\0\0\0") . $e(2, 5, 3, pack('V', $d)) . $e(3, 2, 2, "E\0\0\0") . $e(4, 5, 3, pack('V', $d + 24)) . pack('V', 0);
    $t .= $r(37, 1) . $r(24, 1) . $r(1234, 100) . $r(14, 1) . $r(55, 1) . $r(120, 100);
  }
  return $t;
}
function fx_xmp(): string {
  return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
       . '<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/"'
       . ' exif:GPSLatitude="37,24.2N" exif:GPSLongitude="14,55.1E" xmp:CreatorTool="Prova">'
       . '<drone-dji:GpsLatitude>37.4034</drone-dji:GpsLatitude><drone-dji:GpsLongitude>14.9200</drone-dji:GpsLongitude>'
       . '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}
/* Immagine di prova: blocco rosso in alto a sinistra, resto blu. */
function fx_gd(int $w, int $h) {
  $im = imagecreatetruecolor($w, $h);
  imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 200));
  imagefilledrectangle($im, 0, 0, intdiv($w, 4), intdiv($h, 4), imagecolorallocate($im, 220, 20, 20));
  return $im;
}
function fx_jpeg(int $w, int $h, int $orientation, bool $gps, bool $xmp, ?string $comment = null): string {
  ob_start(); imagejpeg(fx_gd($w, $h), null, 90); $j = ob_get_clean();
  $seg = fn($m, $data) => "\xFF" . chr($m) . pack('n', strlen($data) + 2) . $data;
  $ins = '';
  if ($orientation || $gps) $ins .= $seg(0xE1, "Exif\0\0" . fx_tiff($orientation, $gps));
  if ($xmp) $ins .= $seg(0xE1, "http://ns.adobe.com/xap/1.0/\0" . fx_xmp());
  if ($comment !== null) $ins .= $seg(0xFE, $comment);
  return substr($j, 0, 2) . $ins . substr($j, 2);
}
function fx_png(int $w, int $h, bool $gps, bool $xmp): string {
  $im = fx_gd($w, $h); imagesavealpha($im, true);
  imagefilledrectangle($im, $w - 10, $h - 10, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
  ob_start(); imagepng($im); $p = ob_get_clean();
  $ch = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
  $ins = '';
  if ($gps) $ins .= $ch('eXIf', fx_tiff(1, true));
  if ($xmp) $ins .= $ch('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . fx_xmp());
  return substr($p, 0, 33) . $ins . substr($p, 33);
}
function fx_webp(int $w, int $h, bool $gps, bool $xmp): string {
  ob_start(); imagewebp(fx_gd($w, $h), null, 85); $b = ob_get_clean();
  $ch = fn($t, $d) => $t . pack('V', strlen($d)) . $d . (strlen($d) & 1 ? "\0" : '');
  if ($gps) $b .= $ch('EXIF', fx_tiff(1, true));
  if ($xmp) $b .= $ch('XMP ', fx_xmp());
  return substr_replace($b, pack('V', strlen($b) - 8), 4, 4);
}
/* CRC di tutti i chunk di un PNG validi? */
function fx_png_crc_ok(string $b): bool {
  $p = 8; $n = strlen($b);
  while ($p + 12 <= $n) {
    $len = unpack('N', substr($b, $p, 4))[1]; $t = substr($b, $p + 4, 4);
    if (unpack('N', substr($b, $p + 8 + $len, 4))[1] !== crc32($t . substr($b, $p + 8, $len))) return false;
    $p += 12 + $len; if ($t === 'IEND') return true;
  }
  return false;
}

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
    check('admin "Rigenera thumb" (320×1)', $r['code'] === 303 && $gi && [$gi[0], $gi[1]] === [320, 1], "HTTP {$r['code']}");

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
    str_starts_with($j['thumb'] ?? '', $C['base'] . '/gallery/t/' . ($j['id'] ?? '') . '?v=') && ($j['width'] ?? 0) === 400 && ($j['height'] ?? 0) === 300
    && str_contains($j['delete'] ?? '', '/delete.php?c=') && ($j['duplicate'] ?? null) === false);
  if (!empty($j['id'])) $api_short = $j['id'];
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'headers' => ['X-Api-Token: sbagliato-sbagliato-sbagliato'], 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('token errato -> 401', $r['code'] === 401);
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('senza token -> 401', $r['code'] === 401);
  $r = req('POST', '/gallery/api/upload.php', ['headers' => ['X-Api-Token: ' . $C['token']], 'post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('token giusto senza password di Apache (regola della Tranche 5): basta il token', $r['code'] === 200 && (json_decode($r['body'], true)['duplicate'] ?? null) === true, "HTTP {$r['code']}");
  $r = req('POST', '/gallery/api/upload.php?token=' . $C['token'], ['post' => ['file' => new CURLFile($f, 'image/jpeg', 'api.jpg')]]);
  check('token nell\'indirizzo (?token=): 401, vale solo l\'intestazione', $r['code'] === 401 && (json_decode($r['body'], true)['ok'] ?? null) === false);
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
    ['/gallery/?tag[]=x', 200], ['/gallery/?v=album', 200], ['/gallery/?v[]=1', 200], ['/gallery/?tag=%3Cscript%3E', 200],
  ['/gallery/admin/?v=cestino&q[]=a', 200], ['/gallery/admin/?v=boh', 200], ['/gallery/admin/?v=album', 200], ['/gallery/admin/?v=etichette', 200],
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
    ($j['url'] ?? '') === $C['base'] . "/gallery/i/$id" && str_starts_with($j['thumb'] ?? '', $C['base'] . "/gallery/t/$id?v=")
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

  // d) un doppione di un'immagine nel cestino la ripristina, con il suo link di prima
  admin_post(['act' => 'delete', 'short' => $id]);
  check('immagine nel cestino: /i/ risponde 404', req('GET', "/gallery/i/$id")['code'] === 404 && row($id)['deleted_at'] !== null);
  [$r, $j4] = upload_json($f, 'image/png', ['folder' => 'Banco']);
  check('  ricaricarla la ripristina: stesso link, restored=true', ($j4['id'] ?? '') === $id && ($j4['restored'] ?? null) === true
    && row($id)['deleted_at'] === null && req('GET', "/gallery/i/$id")['code'] === 200, json_encode($j4));
  admin_post(['act' => 'delete', 'short' => $id]);
  [$r] = upload($f, 'image/png');
  check('  anche dal form classico: ?ok=ID&dup=1&restored=1 e la pagina lo dice', str_contains($r['h']['location'] ?? '', "ok=$id&dup=1&restored=1")
    && str_contains(req('GET', "/gallery/?ok=$id&dup=1&restored=1", ['auth' => true])['body'], 'Era nel cestino'));
  [$r, $j5] = upload_json($f, 'image/png');
  check('  ora e\' visibile: doppione normale, restored=false', ($j5['duplicate'] ?? null) === true && ($j5['restored'] ?? null) === false);

  // e) API: anche li' il doppione, con il link di cancellazione
  $r = req('POST', '/gallery/api/upload.php', ['auth' => true, 'headers' => ['X-Api-Token: ' . $C['token']],
    'post' => ['file' => new CURLFile($f, 'image/png', 'x.png')]]);
  $ja = json_decode($r['body'], true);
  check('API: doppione riconosciuto, con link di cancellazione', ($ja['duplicate'] ?? null) === true && ($ja['id'] ?? '') === $id && isset($ja['delete']));

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
      && $sn[0]['url'] === $C['base'] . "/gallery/i/$xid" && str_starts_with($sn[0]['thumb'], $C['base'] . "/gallery/t/$xid?v="));
    check("$where: nessun tag iniettato nell'HTML", !str_contains($html, '<img src=x') && !str_contains($html, '<b>alt</b>'));
    check("$where: selettore del formato presente", str_contains($html, 'data-snip-format'));
  }
});

/* ======================================================================= */
group('Posizione GPS, orientamento, versioni ridotte, cache', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $corner = function ($im): array {       // dove sta il blocco rosso
    $w = imagesx($im); $h = imagesy($im); $c = [];
    foreach (['TL' => [1, 1], 'TR' => [$w - 2, 1], 'BL' => [1, $h - 2], 'BR' => [$w - 2, $h - 2]] as $k => [$x, $y]) {
      $rgb = imagecolorat($im, $x, $y);
      if (($rgb >> 16 & 255) > 150 && ($rgb & 255) < 120) $c[] = $k;
    }
    return $c;
  };
  $stored = fn(string $id) => "$WWW/uploads/" . row($id)['filename'];

  // a) orientamento: le 8 trasformazioni EXIF, codice dell'app
  $bad = [];
  foreach ([1 => 'TL', 2 => 'TR', 3 => 'BR', 4 => 'BL', 5 => 'TL', 6 => 'TR', 7 => 'BR', 8 => 'BL'] as $o => $exp) {
    $im = orient_gd(fx_gd(40, 20), $o);
    $dims = $o >= 5 ? [20, 40] : [40, 20];
    if ($corner($im) !== [$exp] || [imagesx($im), imagesy($im)] !== $dims) $bad[] = $o;
  }
  check('orientamento EXIF 1-8 applicato correttamente', !$bad, 'sbagliati: ' . implode(',', $bad));

  // b) JPEG da telefono: GPS in EXIF e XMP (anche stile drone DJI), foto verticale salvata coricata
  $src = fx_jpeg(1600, 800, 6, true, true);
  file_put_contents("$TMP/telefono.jpg", $src);
  check('  (il file di prova ha davvero GPS e orientamento 6)', has_location("$TMP/telefono.jpg", 'image/jpeg') && image_orientation("$TMP/telefono.jpg", 'image/jpeg') === 6);
  [$r, $j] = upload_json("$TMP/telefono.jpg", 'image/jpeg', ['folder' => 'Banco']);
  $gid = $j['id'] ?? '';
  check('JPEG con GPS: caricato, location_removed=true', ($j['ok'] ?? false) === true && ($j['location_removed'] ?? null) === true, $r['body']);
  $out = (string) @file_get_contents($stored($gid));
  $ex = @exif_read_data($stored($gid), null, true);
  check('  originale salvato senza posizione (EXIF e XMP)', !has_location($stored($gid), 'image/jpeg') && empty($ex['GPS']['GPSLatitude'])
    && !preg_match('~gps(latitude|longitude)~i', $out), json_encode($ex['GPS'] ?? null));
  check('  orientamento e resto del file intatti (stessa lunghezza, dati compressi identici)',
    ($ex['IFD0']['Orientation'] ?? 0) === 6 && strlen($out) === strlen($src)
    && substr($out, strpos($out, "\xFF\xDA")) === substr($src, strpos($src, "\xFF\xDA")));
  check('  impronta del DB = file salvato (non quello inviato)', row($gid)['sha256'] === hash('sha256', $out));
  check('  misure registrate come le mostra il browser: 800×1600', (int) row($gid)['width'] === 800 && (int) row($gid)['height'] === 1600);
  $th = @imagecreatefromstring((string) @file_get_contents("$WWW/thumbs/" . row($gid)['filename']));
  check('  miniatura dritta: 160×320, angolo rosso in alto a destra', $th && [imagesx($th), imagesy($th)] === [160, 320] && $corner($th) === ['TR']);
  $d = req('GET', "/gallery/i/$gid?w=640");
  $dim = @imagecreatefromstring($d['body']);
  check('  versione ridotta dritta: WebP 640×1280, angolo in alto a destra', ($d['h']['content-type'] ?? '') === 'image/webp' && $dim
    && [imagesx($dim), imagesy($dim)] === [640, 1280] && $corner($dim) === ['TR']);
  [$r, $j2] = upload_json("$TMP/telefono.jpg", 'image/jpeg', ['folder' => 'Banco']);
  check('  lo stesso scatto ricaricato e\' riconosciuto come doppione', ($j2['duplicate'] ?? null) === true && ($j2['id'] ?? '') === $gid);
  check('  JSON con larghezze ridotte e versione della pipeline', ($j['sizes'] ?? null) === [480, 640] && ($j['pv'] ?? null) === IMAGE_PIPELINE, json_encode([$j['sizes'] ?? null, $j['pv'] ?? null]));

  // c) PNG con eXIf GPS e XMP: pixel identici, CRC validi
  $png = fx_png(700, 400, true, true);
  file_put_contents("$TMP/gps.png", $png);
  [$r, $jp] = upload_json("$TMP/gps.png", 'image/png', ['folder' => 'Banco']);
  $sp = (string) @file_get_contents($stored($jp['id'] ?? ''));
  $px = function (string $bin) { $im = @imagecreatefromstring($bin); if (!$im) return null; imagesavealpha($im, true); ob_start(); imagepng($im); return sha1(ob_get_clean()); };
  check('PNG con GPS: posizione tolta, CRC validi, pixel identici', ($jp['location_removed'] ?? null) === true
    && !has_location($stored($jp['id']), 'image/png') && fx_png_crc_ok($sp) && $px($sp) !== null && $px($sp) === $px($png));

  // d) WebP con EXIF GPS e XMP
  file_put_contents("$TMP/gps.webp", fx_webp(800, 500, true, true));
  [$r, $jw] = upload_json("$TMP/gps.webp", 'image/webp', ['folder' => 'Banco']);
  $sw = (string) @file_get_contents($stored($jw['id'] ?? ''));
  check('WebP con GPS: posizione tolta sul posto, immagine valida', ($jw['location_removed'] ?? null) === true
    && !has_location($stored($jw['id']), 'image/webp') && strlen($sw) === filesize("$TMP/gps.webp") && @imagecreatefromstring($sw));

  // e) posizione in una forma sconosciuta (commento JPEG): ultima risorsa, immagine risalvata
  file_put_contents("$TMP/commento.jpg", fx_jpeg(600, 300, 0, false, false, 'nota exif:GPSLatitude="37,24N"'));
  [$r, $jc] = upload_json("$TMP/commento.jpg", 'image/jpeg', ['folder' => 'Banco']);
  check('posizione in forma sconosciuta: immagine risalvata senza metadati', ($jc['location_removed'] ?? null) === true
    && !has_location($stored($jc['id'] ?? ''), 'image/jpeg') && @imagecreatefromstring((string) file_get_contents($stored($jc['id']))));

  // f) file senza posizione: non si tocca nulla
  file_put_contents("$TMP/pulita.jpg", fx_jpeg(500, 300, 0, false, false));
  [$r, $jn] = upload_json("$TMP/pulita.jpg", 'image/jpeg', ['folder' => 'Banco']);
  check('immagine senza posizione: salvata identica, location_removed=false', ($jn['location_removed'] ?? null) === false
    && sha1_file($stored($jn['id'] ?? '')) === sha1_file("$TMP/pulita.jpg"));

  // g) versioni ridotte su richiesta
  $big = null; $alpha = null; $gif = null; $mid = null;
  foreach ($uploaded as $id => $u) {
    if ($u['label'] === 'jpg 1600×1200') $big = $id;
    if ($u['label'] === 'png 640×480') $alpha = $id;
    if ($u['label'] === 'gif 300×200') $gif = $id;
    if ($u['label'] === 'webp 500×500') $mid = $id;
  }
  $fn = row($big)['filename'];
  $d = req('GET', "/gallery/i/$big?w=640");
  $im = @imagecreatefromstring($d['body']);
  check('?w=640 su 1600×1200: WebP 640×480', ($d['h']['content-type'] ?? '') === 'image/webp' && $im && [imagesx($im), imagesy($im)] === [640, 480]);
  check('  salvata come thumbs/FILE.w640.webp, nome scaricato .w640.webp',
    is_file("$WWW/thumbs/$fn.w640.webp") && str_contains($d['h']['content-disposition'] ?? '', '.w640.webp'));
  clearstatcache(); $m0 = filemtime("$WWW/thumbs/$fn.w640.webp");
  sleep(1);
  req('GET', "/gallery/i/$big?w=640");
  clearstatcache();
  check('  seconda richiesta servita dal disco, non rigenerata', filemtime("$WWW/thumbs/$fn.w640.webp") === $m0);
  $im = @imagecreatefromstring(req('GET', "/gallery/i/$big?w=700")['body']);
  check('?w=700 prende la larghezza ammessa successiva (960×720)', $im && [imagesx($im), imagesy($im)] === [960, 720]);
  $orig = (string) file_get_contents("$WWW/uploads/$fn");
  foreach (['?w=5000' => 'oltre la larghezza massima', '?w=abc' => 'non numerico', '?w[]=1' => 'array', '?w=-5' => 'negativo', '?w=0' => 'zero'] as $q => $why) {
    $d = req('GET', "/gallery/i/$big$q");
    check("?w $why ($q): l'originale", $d['code'] === 200 && $d['body'] === $orig && ($d['h']['content-type'] ?? '') === 'image/jpeg');
  }
  $d = req('GET', "/gallery/i/$gif?w=100");
  check('GIF: sempre l\'originale (GD perderebbe l\'animazione)', ($d['h']['content-type'] ?? '') === 'image/gif');
  check('immagine piu\' stretta della larghezza chiesta (500 px, ?w=640): l\'originale',
    req('GET', "/gallery/i/$mid?w=640")['body'] === file_get_contents("$WWW/uploads/" . row($mid)['filename']));
  $im = @imagecreatefromstring(req('GET', "/gallery/i/$mid?w=480")['body']);
  check('  ma 480 su 500 px si', $im && imagesx($im) === 480);
  $im = @imagecreatefromstring(req('GET', "/gallery/i/$alpha?w=480")['body']);
  check('PNG trasparente -> WebP con trasparenza', $im && ((imagecolorat($im, 400, 300) >> 24) & 0x7F) > 0);
  $t = req('GET', "/gallery/t/$big?w=640");
  check('/t/ ignora ?w: resta la miniatura', ($t['h']['content-type'] ?? '') === 'image/jpeg' && max(getimagesizefromstring($t['body'])[0], getimagesizefromstring($t['body'])[1]) <= 320);
  check('nessun file temporaneo in thumbs/', !glob("$WWW/thumbs/*.tmp-*") && !glob("$WWW/thumbs/*.clean*"));

  // h) cache: immutabile solo cio' che non puo' cambiare o porta una versione
  $cc = fn(string $p) => req('GET', $p)['h']['cache-control'] ?? '';
  check('cache: originale /i/ immutabile', str_contains($cc("/gallery/i/$big"), 'immutable'));
  check('cache: /t/ senza versione un giorno, con ?v= immutabile',
    $cc("/gallery/t/$big") === 'public, max-age=86400' && str_contains($cc("/gallery/t/$big?v=123"), 'immutable'));
  check('cache: ?w= senza versione un giorno, con &v= immutabile',
    $cc("/gallery/i/$big?w=640") === 'public, max-age=86400' && str_contains($cc("/gallery/i/$big?w=640&v=1"), 'immutable'));

  // i) snippet: miniatura con versione, che cambia quando la si rigenera
  $snipOf = function (string $id) {
    $html = req('GET', '/gallery/?q=' . rawurlencode("id:$id"), ['auth' => true])['body'];
    return array_values(array_filter(snips($html), fn($d) => ($d['id'] ?? '') === $id))[0] ?? null;
  };
  $s1 = $snipOf($big);
  clearstatcache();
  check('snippet: miniatura con ?v=data della miniatura, larghezze ridotte giuste',
    $s1 && str_ends_with($s1['thumb'], '?v=' . filemtime("$WWW/thumbs/$fn")) && $s1['sizes'] === [480, 640, 960, 1280], json_encode($s1));
  sleep(1);
  browser_post('/gallery/admin/index.php', ['csrf' => csrf(), 'act' => 'retthumb', 'short' => $big]);
  $s2 = $snipOf($big);
  check('  dopo "Rigenera thumb" l\'indirizzo della miniatura cambia', $s1 && $s2 && $s1['thumb'] !== $s2['thumb'], ($s1['thumb'] ?? '') . ' / ' . ($s2['thumb'] ?? ''));

  // j) cestino: i file restano finche' non si elimina per sempre, poi spariscono tutti
  $gfn = row($gid)['filename'];
  req('GET', "/gallery/delete.php?c=$gid&k=" . row($gid)['delkey'], ['auth' => true]);
  check('link di cancellazione dell\'API: nel cestino, /i/ /t/ ?w= rispondono 404, i file restano',
    req('GET', "/gallery/i/$gid")['code'] === 404 && req('GET', "/gallery/t/$gid")['code'] === 404 && req('GET', "/gallery/i/$gid?w=640")['code'] === 404
    && is_file("$WWW/uploads/$gfn") && is_file("$WWW/thumbs/$gfn") && is_file("$WWW/thumbs/$gfn.w640.webp"));
  admin_post(['act' => 'purge', 'short' => $gid], 'cestino');
  check('  eliminata per sempre: riga, originale, miniatura e versioni ridotte spariscono',
    row($gid) === null && !is_file("$WWW/uploads/$gfn") && !is_file("$WWW/thumbs/$gfn") && !glob("$WWW/thumbs/$gfn.w*"));

  // k) tante richieste insieme di versioni non ancora fatte: lock, nessun errore
  $cand = db_bench()->query("SELECT short FROM images WHERE width > 480 AND mime <> 'image/gif' ORDER BY id LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
  $mh = curl_multi_init(); $hs = [];
  foreach ($cand as $sh) {
    $ch = curl_init($C['base'] . "/gallery/i/$sh?w=480");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
  }
  do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1); } while ($running && $st === CURLM_OK);
  $codes = array_map(fn($ch) => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs);
  check(count($cand) . ' versioni ridotte chieste insieme: tutte 200', count(array_filter($codes, fn($c) => $c === 200)) === count($cand), json_encode(array_count_values($codes)));
  check('  lock presente, nessun file temporaneo', is_file("$WWW/thumbs/.gd.lock") && !glob("$WWW/thumbs/*.tmp-*"));

  // l) l'archivio copiato: ogni immagine a ?w=640
  $bad = []; $made = 0;
  foreach (db_bench()->query("SELECT short, mime, width FROM images WHERE short IN ('" . implode("','", $live_shorts) . "')") as $row) {
    $d = req('GET', "/gallery/i/{$row['short']}?w=640");
    $ct = $d['h']['content-type'] ?? '';
    $wantDerived = (int) $row['width'] > 640 && $row['mime'] !== 'image/gif';
    if ($wantDerived) {
      $gi = @getimagesizefromstring($d['body']);
      if ($ct !== 'image/webp' || !$gi || $gi[0] !== 640) $bad[] = $row['short']; else $made++;
    } elseif ($ct !== $row['mime']) {
      $bad[] = $row['short'];
    }
  }
  check("archivio: ogni immagine a ?w=640 giusta ($made ridotte, le altre originali)", !$bad, implode(',', array_slice($bad, 0, 5)));

  // m) regen_thumbs --all toglie le versioni ridotte (si rifanno su richiesta)
  $n = count(glob("$WWW/thumbs/*.w*.webp"));
  $out = trim((string) shell_exec('cd ' . escapeshellarg($WWW) . ' && php -d error_log=' . escapeshellarg($C['log']) . ' regen_thumbs.php --all 2>&1'));
  check("regen_thumbs.php --all toglie le $n versioni ridotte", $n > 0 && !glob("$WWW/thumbs/*.w*.webp") && str_contains($out, "$n versioni ridotte tolte"), $out);
});

/* ======================================================================= */
group('Album, etichette, azioni multiple, cestino', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $tagsOf = fn(string $sh) => db_bench()->query("SELECT t.name FROM image_tags it JOIN tags t ON t.id=it.tag_id JOIN images i ON i.id=it.image_id
                                                WHERE i.short=" . db_bench()->quote($sh) . " ORDER BY t.name COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
  $ids = [];
  foreach ([1, 2, 3, 4] as $k) {
    [, $j] = upload_json(make_img("alb$k", 300 + $k, 200, 'png'), 'image/png', ['folder' => 'Uno', 'title' => "album prova $k"]);
    $ids[] = $j['id'] ?? '';
  }
  check('preparazione: 4 immagini nell\'album Uno', count(array_filter($ids)) === 4);

  // --- azioni multiple
  $r = admin_post(['act' => 'bulk', 'op' => 'tag', 'value' => 'Panorami'] + ids_fields([$ids[0], $ids[1], $ids[2], 'nonesiste']));
  check('multiple: etichetta aggiunta a 3 immagini (codice inesistente ignorato)', $r['code'] === 303
    && $tagsOf($ids[0]) === ['Panorami'] && $tagsOf($ids[2]) === ['Panorami'] && $tagsOf($ids[3]) === []);
  $g = page_ids('/gallery/?tag=Panorami');
  check('  galleria ?tag=: esattamente quelle 3', count($g) === 3 && !array_diff([$ids[0], $ids[1], $ids[2]], $g));
  check('  ricerca tag:panorami (maiuscole indifferenti): le stesse 3', count(page_ids('/gallery/?q=tag:panorami')) === 3);
  $home = req('GET', '/gallery/?f=Uno', ['auth' => true])['body'];
  check('  etichetta visibile tra i filtri e sulle schede', str_contains($home, 'class="chip') && substr_count($home, '>Panorami</a>') >= 3);
  admin_post(['act' => 'bulk', 'op' => 'untag', 'value' => 'panorami'] + ids_fields([$ids[0]]));
  check('multiple: etichetta tolta da una', $tagsOf($ids[0]) === [] && $tagsOf($ids[1]) === ['Panorami']);
  admin_post(['act' => 'bulk', 'op' => 'move', 'value' => 'Due'] + ids_fields([$ids[0], $ids[1]]));
  check('multiple: due spostate nell\'album Due', row($ids[0])['folder'] === 'Due' && row($ids[1])['folder'] === 'Due' && row($ids[2])['folder'] === 'Uno');
  $r = browser_post('/gallery/admin/index.php', ['act' => 'bulk', 'op' => 'trash'] + ids_fields([$ids[3]]));
  check('multiple senza token CSRF: 419, nulla cambia', $r['code'] === 419 && row($ids[3])['deleted_at'] === null);
  admin_post(['act' => 'bulk', 'op' => 'trash', 'ids' => $ids[3]]);
  check('multiple con ids non in forma di lista: ignorato', row($ids[3])['deleted_at'] === null);

  // --- etichette dal foglio di lavoro
  admin_post(['act' => 'meta', 'short' => $ids[3], 'folder' => 'Uno', 'title' => 'x', 'alt' => '', 'tags' => 'città, <b>x</b>, Città ,mare']);
  check('etichette dal modulo: normalizzate, accenti tenuti, doppioni e HTML via', $tagsOf($ids[3]) === ['bxb', 'città', 'mare'], json_encode($tagsOf($ids[3])));
  admin_post(['act' => 'meta', 'short' => $ids[3], 'folder' => 'Uno', 'title' => 'x', 'alt' => '', 'tags' => '']);
  check('  togliendole tutte, le etichette rimaste senza immagini spariscono', !q1("SELECT 1 FROM tags WHERE name='bxb'") && !q1("SELECT 1 FROM tags WHERE name='città'"));
  admin_post(['act' => 'tag_rename', 'from' => 'Panorami', 'to' => 'paesaggi'], 'etichette');
  check('etichetta rinominata', $tagsOf($ids[1]) === ['paesaggi']);
  admin_post(['act' => 'bulk', 'op' => 'tag', 'value' => 'vedute'] + ids_fields([$ids[2], $ids[3]]));
  admin_post(['act' => 'tag_rename', 'from' => 'paesaggi', 'to' => 'Vedute'], 'etichette');
  check('  rinominata con un nome esistente: unite', $tagsOf($ids[1]) === ['vedute'] && $tagsOf($ids[2]) === ['vedute'] && !q1("SELECT 1 FROM tags WHERE name='paesaggi'"));
  admin_post(['act' => 'tag_rename', 'from' => 'vedute', 'to' => ''], 'etichette');
  check('  nome vuoto: eliminata, le immagini restano', $tagsOf($ids[2]) === [] && row($ids[2]) !== null);

  // --- album: descrizione, copertina, ordine, rinomina, unione
  admin_post(['act' => 'album_meta', 'name' => 'Uno', 'description' => "Foto di prova <script>alert(1)</script>\nseconda riga", 'cover' => $ids[3], 'position' => '1'], 'album');
  $al = db_bench()->query("SELECT * FROM albums WHERE name='Uno'")->fetch();
  check('album: descrizione, copertina e posizione salvate', $al && $al['cover'] === $ids[3] && (int) $al['position'] === 1);
  $page = req('GET', '/gallery/?f=Uno', ['auth' => true])['body'];
  check('  descrizione mostrata nell\'album, con escape', str_contains($page, 'Foto di prova &lt;script&gt;') && !str_contains($page, '<script>alert(1)'));
  $home = req('GET', '/gallery/', ['auth' => true])['body'];
  check('  linguette nell\'ordine scelto: Uno prima di Blog', strpos($home, '?f=Uno&') !== false && strpos($home, '?f=Uno&') < strpos($home, '?f=Blog&'));
  $ov = req('GET', '/gallery/?v=album', ['auth' => true])['body'];
  check('  panoramica: copertina scelta', str_contains($ov, 'i.php?c=' . $ids[3] . '&amp;thumb=1'));
  admin_post(['act' => 'album_meta', 'name' => 'Uno', 'description' => 'd', 'cover' => $ids[0], 'position' => 'abc'], 'album');
  $al = db_bench()->query("SELECT * FROM albums WHERE name='Uno'")->fetch();
  check('  copertina di un altro album e posizione non numerica: rifiutate', $al['cover'] === null && $al['position'] === null);
  admin_post(['act' => 'album_meta', 'name' => 'Uno', 'description' => 'Album uno', 'cover' => '', 'position' => '1'], 'album');
  admin_post(['act' => 'album_rename', 'from' => 'Uno', 'to' => 'Uno bis'], 'album');
  check('rinomina: immagini e descrizione passano al nuovo nome', row($ids[2])['folder'] === 'Uno bis'
    && (string) q1("SELECT description FROM albums WHERE name='Uno bis'") === 'Album uno' && !q1("SELECT 1 FROM albums WHERE name='Uno'"));
  admin_post(['act' => 'album_rename', 'from' => 'Due', 'to' => 'Uno bis'], 'album');
  check('rinomina verso un album esistente: uniti', row($ids[0])['folder'] === 'Uno bis' && row($ids[1])['folder'] === 'Uno bis'
    && !in_array('Due', array_column(db_bench()->query("SELECT DISTINCT folder FROM images")->fetchAll(), 'folder'), true));
  check('  la descrizione del destinatario resta', (string) q1("SELECT description FROM albums WHERE name='Uno bis'") === 'Album uno');
  check('  il pannello lo dice: «Due» unito a «Uno bis»', str_contains(browser_get('/gallery/admin/?v=album')['body'], 'Album «Due» unito a «Uno bis»'));
  admin_post(['act' => 'album_rename', 'from' => 'Uno bis', 'to' => ''], 'album');
  check('nome vuoto: immagini senza album, scheda dell\'album tolta', row($ids[0])['folder'] === '' && !q1("SELECT 1 FROM albums WHERE name='Uno bis'"));

  // --- cestino
  $nAll = (int) preg_match('~tutti<span class="n">(\d+)~', req('GET', '/gallery/', ['auth' => true])['body'], $m) ? (int) $m[1] : -1;
  admin_post(['act' => 'bulk', 'op' => 'trash'] + ids_fields([$ids[0], $ids[1]]));
  $home = req('GET', '/gallery/', ['auth' => true])['body'];
  check('cestino: le immagini spariscono da galleria, conteggi e ricerca',
    preg_match('~tutti<span class="n">(\d+)~', $home, $m) && (int) $m[1] === $nAll - 2
    && !array_intersect([$ids[0], $ids[1]], page_ids('/gallery/?f=')) && !array_intersect([$ids[0], $ids[1]], page_ids('/gallery/?q=' . rawurlencode('album prova'))));
  check('  e i loro indirizzi pubblici rispondono 404', req('GET', "/gallery/i/{$ids[0]}")['code'] === 404 && req('GET', "/gallery/t/{$ids[1]}")['code'] === 404);
  db_bench()->prepare("UPDATE images SET deleted_at=? WHERE short=?")->execute([time() - 31 * 86400, $ids[0]]);
  $fn0 = row($ids[0])['filename'];
  $adm = req('GET', '/gallery/admin/', ['auth' => true, 'session' => true])['body'];
  check('dopo 30 giorni l\'apertura del pannello la elimina per sempre', row($ids[0]) === null && !is_file("$WWW/uploads/$fn0") && str_contains($adm, 'oltre 30 giorni'));
  check('  quella entrata ieri resta', row($ids[1])['deleted_at'] !== null);
  admin_post(['act' => 'purge_all'], 'cestino');
  check('"Svuota il cestino": eliminate tutte', trash_count_bench() === 0 && row($ids[1]) === null);
});

/* ======================================================================= */
group('Modifica, cestino, eliminazione definitiva', function () use (&$live_shorts, &$first, &$uploaded, &$n_img, &$copy_migrated, $C, $WWW, $TMP, $EXPECT) {
  $orig = array_key_first($uploaded);
  $fn = row($orig)['filename'];
  $r = admin_post(['act' => 'meta', 'short' => $orig, 'folder' => 'Spostate', 'title' => 'gabbiano solitario', 'alt' => 'x', 'tags' => 'mare, Gabbiani,mare']);
  check('modifica: album, titolo ed etichette salvati', $r['code'] === 303 && row($orig)['folder'] === 'Spostate'
    && db_bench()->query("SELECT group_concat(t.name, ',') FROM image_tags it JOIN tags t ON t.id=it.tag_id JOIN images i ON i.id=it.image_id
                          WHERE i.short='$orig' ORDER BY t.name")->fetchColumn() !== false);
  $r = req('GET', '/gallery/?q=gabbiano', ['auth' => true]);
  check('titolo modificato e ritrovato dalla ricerca FTS', row($orig)['title'] === 'gabbiano solitario' && str_contains($r['body'], 'gabbiano solitario'));
  $key = row($orig)['delkey'];
  $r = req('GET', "/gallery/delete.php?c=$orig&k=" . str_repeat('0', 16), ['auth' => true]);
  check('delete.php con chiave errata -> 403', $r['code'] === 403);
  $r = req('GET', "/gallery/delete.php?c=$orig&k=$key", ['auth' => true]);
  check('delete.php con chiave giusta: nel cestino (riga e file restano, /i/ 404)',
    $r['code'] === 200 && row($orig)['deleted_at'] !== null && is_file("$WWW/uploads/$fn") && req('GET', "/gallery/i/$orig")['code'] === 404);
  $trash = req('GET', '/gallery/admin/index.php?v=cestino', ['auth' => true])['body'];
  check('  il cestino la elenca, con i giorni rimasti e la miniatura', str_contains($trash, ">$orig<") && str_contains($trash, 'ancora 30 giorni') && str_contains($trash, 'src="data:image/'));
  admin_post(['act' => 'restore', 'short' => $orig], 'cestino');
  check('  ripristinata: di nuovo pubblica', row($orig)['deleted_at'] === null && req('GET', "/gallery/i/$orig")['code'] === 200);
  admin_post(['act' => 'purge', 'short' => $orig]);
  check('  "elimina per sempre" non tocca un\'immagine visibile', row($orig) !== null && is_file("$WWW/uploads/$fn"));
  admin_post(['act' => 'delete', 'short' => $orig]);
  admin_post(['act' => 'purge', 'short' => $orig], 'cestino');
  check('  dal cestino, eliminata per sempre: riga, file e miniatura spariscono',
    row($orig) === null && !is_file("$WWW/uploads/$fn") && !is_file("$WWW/thumbs/$fn"));
});

/* ======================================================================= */
group('Statistiche d\'uso: lettura dei log (prove unitarie)', function () {
  $own = ['example.test'];
  $n = fn(string $raw) => stats_norm_ref($raw)['ref'] ?? null;
  check('provenienza: sid, utm e frammento tolti, il resto resta',
    $n('https://forum.example.org/viewtopic.php?f=2&t=12&sid=0123abcd&utm_source=x#p5') === 'forum.example.org/viewtopic.php?f=2&t=12');
  check('  token, chiavi, sessioni ed email tolti',
    $n('https://a.example/p?id=7&token=abc&api_key=k&email=x%40y.z&Session_Id=1&k=del') === 'a.example/p?id=7', (string) $n('https://a.example/p?id=7&token=abc&api_key=k&email=x%40y.z&Session_Id=1&k=del'));
  check('  "https://a, https://b": vale la prima', $n('https://google.com, https:/') === 'google.com/');
  check('  schema non web conservato, porta conservata, host in minuscolo',
    $n('android-app://com.google.android.gm/') === 'android-app://com.google.android.gm/' && $n('http://Host.Example:8080/x') === 'host.example:8080/x');
  check('  assente o illeggibile: nessuna provenienza', $n('-') === null && $n('') === null && $n('nonunurl') === null);
  check('bot: Googlebot, curl, user agent vuoto sì; CUBOT (telefono) e Firefox no',
    stats_is_bot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)') && stats_is_bot('curl/8.14.1') && stats_is_bot('-')
    && !stats_is_bot('Mozilla/5.0 (Linux; Android 10; CUBOT KINGKONG 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36')
    && !stats_is_bot(UA_FF) && !stats_is_bot(UA_CH));
  check('servizi: TelegramBot, WhatsApp, proxy di Gmail; un browser no',
    stats_service('TelegramBot (like TwitterBot)') === 'Telegram' && stats_service('WhatsApp/2.23.20.0 A') === 'WhatsApp'
    && stats_service('Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)') === 'Gmail'
    && stats_service(UA_FF) === null);
  check('codice: /i/ e /t/ anche con ?w= e ?v=, i.php?c=; il resto no',
    stats_request_short('/gallery/i/AbC-_9') === 'AbC-_9' && stats_request_short('/gallery/t/AbC?v=12') === 'AbC'
    && stats_request_short('/gallery/i/AbC?w=640&v=3') === 'AbC' && stats_request_short('/gallery/i.php?thumb=1&c=Xy1') === 'Xy1'
    && stats_request_short('/gallery/i.php?c[]=x') === null && stats_request_short('/gallery/index.php') === null
    && stats_request_short('/gallery/i/a/b') === null && stats_request_short('/gallery/upload.php?c=x') === null);
  $L = fn(...$a) => stats_parse_line(alog(...$a), $own);
  check('riga: HEAD, 500 e pagine che non sono immagini ignorate',
    $L(1, '10:00:00', '/gallery/i/X', 200, '-', UA_FF, '-', 'HEAD') === null && $L(1, '10:00:00', '/gallery/i/X', 500) === null
    && $L(1, '10:00:00', '/gallery/admin/', 200) === null && stats_parse_line("riga illeggibile /gallery/i/X\n", $own) === null);
  $p = $L(1, '10:00:00', '/gallery/i/X', 404, 'https://example.test/forum/viewtopic.php?t=3');
  check('riga: 404 da una pagina dello stesso host fuori da /gallery: pagina, non trovata, giorno giusto',
    $p === [stats_day_ago(2), 'X', 'pagina', 'example.test/forum/viewtopic.php?t=3', 0], json_encode($p));
  check('riga: dalle pagine della galleria, o da te senza provenienza: interno',
    ($L(1, '10:00:00', '/gallery/i/X', 200, 'https://example.test/gallery/admin/?q=x')[2] ?? '') === 'interno'
    && ($L(1, '10:00:00', '/gallery/t/X', 304, '-', UA_FF, 'admin')[2] ?? '') === 'interno');
  $p = $L(1, '10:00:00', '/gallery/i/X', 200, 'https://forum.example.org/viewtopic.php?t=9', UA_FF, 'admin');
  check('riga: tu che leggi un post del forum: pagina (è lì che l\'immagine è incollata)', ($p[2] ?? '') === 'pagina');
  check('riga: virgolette con escape nello user agent', ($L(1, '10:00:00', '/gallery/i/X', 200, '-', 'Mozilla/5.0 \"strano\" Firefox/1')[2] ?? '') === 'diretto');
});

/* ======================================================================= */
group('Statistiche d\'uso: job notturno, cruscotto, avviso prima di eliminare', function () use (&$first, $C, $WWW) {
  $sd = $C['stats_db']; $ap = $C['apache'];
  $host = parse_url($C['base'], PHP_URL_HOST) . ':' . parse_url($C['base'], PHP_URL_PORT);
  $run = function (string ...$args) use ($WWW): array {
    exec('umask 002; php ' . escapeshellarg("$WWW/stats_update.php") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
  };
  $sq = function (string $sql, array $args = []) use ($sd) {
    $p = new PDO('sqlite:' . $sd, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $st = $p->prepare($sql); $st->execute($args); return $st->fetchColumn();
  };
  $rw = fn() => new PDO('sqlite:' . $sd, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $digest = fn() => sha1((string) $sq("SELECT group_concat(day||'|'||short||'|'||src||'|'||ref||'|'||ok||'|'||n, ';') FROM (SELECT * FROM hits ORDER BY day, short, src, ref, ok)"));
  $d = fn(int $ago) => stats_day_ago($ago + 1);
  $adm = fn(string $qs = '') => req('GET', '/gallery/admin/index.php' . ($qs !== '' ? "?$qs" : ''), ['auth' => true, 'session' => true])['body'];

  // --- statistiche reali copiate dal live
  if ($C['stats_copied'] && is_file($sd)) {
    $r = req('GET', '/gallery/admin/?v=cruscotto', ['auth' => true]);
    check('statistiche reali copiate: il cruscotto risponde 200 e le mostra', $r['code'] === 200 && str_contains($r['body'], 'Più viste'), "HTTP {$r['code']}");
  } else {
    info('nessuna statistica reale da copiare: si prova con i log finti');
  }

  // --- senza statistiche: tutto funziona, nessun dato d'uso
  @rename($sd, "$sd.reale");
  $ws = $adm(); $dash = req('GET', '/gallery/admin/?v=cruscotto', ['auth' => true]);
  check('senza file delle statistiche: foglio di lavoro e cruscotto rispondono, con l\'avviso',
    str_contains($ws, 'Statistiche d\'uso non ancora disponibili') && !str_contains($ws, 'class="use') && $dash['code'] === 200
    && str_contains($dash['body'], 'Spazio per album') && !str_contains($dash['body'], 'Più viste'));

  // --- immagini e log finti
  $ids = [];
  foreach (['usoA', 'usoB', 'usoC', 'usoD', 'usoE'] as $k => $name) {
    [, $j] = upload_json(make_img($name, 271 + $k, 181, 'png'), 'image/png', ['folder' => 'Uso', 'title' => "uso $name"]);
    $ids[] = $j['id'] ?? '';
  }
  [$A, $B, $Cc, $D, $E] = $ids;
  check('preparazione: 5 immagini', count(array_filter($ids)) === 5);
  db_bench()->prepare("INSERT INTO short_aliases(short, image_id, created_at) SELECT ?, id, ? FROM images WHERE short=?")->execute(['aliasUSO01', time(), $A]);

  $F = 'https://forum.example.org/viewtopic.php?f=2&t=12&sid=0123456789abcdef';
  $G = "http://$host/gallery/admin/";
  $l1 = '';                                                    // access.log.1: ieri, e la sera dell'altro ieri
  for ($i = 0; $i < 5; $i++) $l1 .= alog(1, "10:0$i:00", "/gallery/i/$A", 200, $F);
  for ($i = 0; $i < 3; $i++) $l1 .= alog(1, "11:0$i:00", "/gallery/t/$A?v=123", 200, '-', 'TelegramBot (like TwitterBot)');
  for ($i = 0; $i < 2; $i++) $l1 .= alog(1, "12:0$i:00", "/gallery/i/$A?w=640", 200, '-', UA_CH);
  for ($i = 0; $i < 4; $i++) $l1 .= alog(1, "13:0$i:00", "/gallery/i.php?c=$A&thumb=1&v=1", 200, $G);
  for ($i = 0; $i < 2; $i++) $l1 .= alog(1, "14:0$i:00", "/gallery/i/$A", 304, '-', UA_FF, 'bench');
  $l1 .= alog(1, '14:30:00', "/gallery/i/$A", 200, 'https://forum.example.org/viewtopic.php?t=99', UA_FF, 'bench');
  for ($i = 0; $i < 3; $i++) $l1 .= alog(1, "15:0$i:00", "/gallery/i/$A", 200, '-', 'curl/8.14.1');
  $l1 .= alog(1, '15:30:00', "/gallery/i/$A", 200, '-', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
  $l1 .= alog(1, '16:00:00', "/gallery/i/$A", 200, $F, UA_FF, '-', 'HEAD');
  $l1 .= alog(1, '16:01:00', "/gallery/i/$A", 500, $F);
  for ($i = 0; $i < 2; $i++) $l1 .= alog(1, "16:1$i:00", '/gallery/i/aliasUSO01', 200, 'https://blog.example.net/post/1#commenti');
  $l1 .= alog(1, '17:00:00', "/gallery/i/$A", 200, '-', 'Mozilla/5.0 \"strano\" Firefox/1');
  $l1 .= alog(1, '17:01:00', "/gallery/i/$A", 200, 'https://google.com, https:/');
  $l1 .= alog(1, '17:02:00', "/gallery/i/$A", 200, '-', 'Mozilla/5.0 (Linux; Android 10; CUBOT KINGKONG 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36');
  $l1 .= alog(1, '17:03:00', "/gallery/i/$A", 200, 'https://evil.example/<script>alert(1)</script>');
  for ($i = 0; $i < 3; $i++) $l1 .= alog(1, "18:0$i:00", "/gallery/i/$B", 404, 'https://forum.example.org/viewtopic.php?t=50');
  for ($i = 0; $i < 3; $i++) $l1 .= alog(1, "18:1$i:00", "/gallery/i/$Cc", 200, $G);
  for ($i = 0; $i < 2; $i++) $l1 .= alog(1, "18:2$i:00", "/gallery/i/$D", 404, '-', UA_CH);
  $l1 .= alog(1, '19:00:00', '/forum/index.php', 200) . alog(1, '19:00:01', '/gallery/', 200, '-', UA_FF, 'bench') . "riga illeggibile /gallery/i/$A\n";
  for ($i = 0; $i < 3; $i++) $l1 .= alog(2, "20:0$i:00", "/gallery/i/$A", 200, 'https://forum.example.org/viewtopic.php?t=7');
  $l2 = '';                                                    // access.log.2.gz: mattina dell'altro ieri e giorni prima
  for ($i = 0; $i < 2; $i++) $l2 .= alog(2, "08:0$i:00", "/gallery/i/$A", 200, 'https://forum.example.org/viewtopic.php?t=7');
  for ($i = 0; $i < 2; $i++) $l2 .= alog(3, "09:0$i:00", "/gallery/i/$B", 200, 'https://forum.example.org/viewtopic.php?t=50');
  for ($i = 0; $i < 7; $i++) $l2 .= alog(40, "09:0$i:00", "/gallery/i/$A", 200, 'https://old.example.com/');
  file_put_contents("$ap/access.log.1", $l1);
  file_put_contents("$ap/access.log.2.gz", gzencode($l2));
  file_put_contents("$ap/access.log", alog(0, '00:30:00', "/gallery/i/$A", 200, $F));

  // --- il job
  [$rc, $out] = $run('--quiet');
  check('stats_update.php: esito 0, una riga di riepilogo', $rc === 0 && str_contains($out, ' ok: 3 log'), $out);
  $lk = dirname($sd) . '/.lock';
  check('  stats.db e .lock con permessi 640, anche con umask 002 (il server web legge, non scrive)',
    is_file($sd) && (fileperms($sd) & 0777) === 0640 && (fileperms($lk) & 0777) === 0640, sprintf('%o %o', @fileperms($sd) & 0777, @fileperms($lk) & 0777));
  check('  nessun file temporaneo rimasto', !glob(dirname($sd) . '/.stats.db.tmp-*'));
  $hit = fn(int $ago, string $short, string $src, string $ref = '', int $ok = 1) => (int) $sq("SELECT n FROM hits WHERE day=? AND short=? AND src=? AND ref=? AND ok=?", [$d($ago), $short, $src, $ref, $ok]);
  check('conteggi: pagina del forum senza sid (5), Telegram (3), senza provenienza (4)',
    $hit(1, $A, 'pagina', 'forum.example.org/viewtopic.php?f=2&t=12') === 5 && $hit(1, $A, 'servizio', 'Telegram') === 3 && $hit(1, $A, 'diretto') === 4,
    $hit(1, $A, 'pagina', 'forum.example.org/viewtopic.php?f=2&t=12') . '/' . $hit(1, $A, 'servizio', 'Telegram') . '/' . $hit(1, $A, 'diretto'));
  check('  interno: dalle pagine della galleria (4) e da te senza provenienza (2)', $hit(1, $A, 'interno') === 6);
  check('  tu dal forum: pagina; bot e curl a parte; HEAD e 500 non contano',
    $hit(1, $A, 'pagina', 'forum.example.org/viewtopic.php?t=99') === 1 && $hit(1, $A, 'bot') === 4 && (int) $sq("SELECT lines FROM days WHERE day=?", [$d(1)]) === 35,
    'righe di ieri: ' . $sq("SELECT lines FROM days WHERE day=?", [$d(1)]));
  check('  nessun sid salvato, frammento tolto, alias contato a parte',
    !$sq("SELECT 1 FROM hits WHERE ref LIKE '%sid=%' OR ref LIKE '%#%'") && $hit(1, 'aliasUSO01', 'pagina', 'blog.example.net/post/1') === 2);
  check('  un giorno diviso fra due log (uno compresso) contato per intero', $hit(2, $A, 'pagina', 'forum.example.org/viewtopic.php?t=7') === 5);
  check('  404 a un\'immagine: contate come "non trovata"', $hit(1, $B, 'pagina', 'forum.example.org/viewtopic.php?t=50', 0) === 3);
  check('  dati dal giorno della prima riga del log più vecchio', $sq("SELECT v FROM meta WHERE k='since'") === $d(40));

  $dg = $digest();
  [$rc] = $run('--quiet');
  check('rilanciato: stesso risultato (idempotente)', $rc === 0 && $digest() === $dg);

  copy("$ap/access.log.1", "$ap/access.log.9");                  // lo stesso log con un altro nome
  [$rc, $out] = $run();
  check('stesso log sotto due nomi (rotazione durante la lettura): contato una volta', $rc === 0 && str_contains($out, 'saltato') && $digest() === $dg, $out);
  unlink("$ap/access.log.9");

  file_put_contents("$ap/access.log.5.gz", gzencode(alog(5, '10:00:00', "/gallery/i/$A", 200, $F)));
  chmod("$ap/access.log.5.gz", 0);
  [$rc, $out] = $run();
  check('un log non leggibile: avviso, gli altri vengono letti', $rc === 0 && str_contains($out, 'non leggibile') && $digest() === $dg, $out);
  unlink("$ap/access.log.5.gz");

  $mt = filemtime($sd);
  [$rc, $out] = $run('--logs', "$ap/nessuno*");
  clearstatcache();
  check('nessun log: esito 1, statistiche intatte', $rc === 1 && str_contains($out, 'nessun log trovato') && filemtime($sd) === $mt && $digest() === $dg, $out);
  file_put_contents("$ap/altro.log", alog(1, '10:00:00', '/forum/index.php', 200) . alog(1, '10:00:01', '/gallery/admin/', 200, '-', UA_FF, 'bench'));
  [$rc, $out] = $run('--logs', "$ap/altro.log");
  check('log senza immagini riconosciute: avviso sul formato, statistiche intatte', $rc === 0 && str_contains($out, 'formato del log') && $digest() === $dg, $out);
  unlink("$ap/altro.log");

  // --- fusione: un giorno completo non viene sostituito da uno parziale
  rename("$ap/access.log.2.gz", "$ap/vecchio.gz");               // il log piu' vecchio esce dalla rotazione
  [$rc, $out] = $run();
  check('log più vecchio uscito: il giorno diviso resta intero, quelli precedenti restano',
    $rc === 0 && $hit(2, $A, 'pagina', 'forum.example.org/viewtopic.php?t=7') === 5 && $hit(40, $A, 'pagina', 'old.example.com/') === 7
    && str_contains($out, 'tenuti dallo storico'), $out);
  file_put_contents("$ap/access.log", alog(0, '00:40:00', "/gallery/i/$A", 200, $F) . alog(0, '00:41:00', "/gallery/i/$A", 200, $F), FILE_APPEND);
  $run('--quiet');
  check('il giorno in corso si completa al giro dopo', $hit(0, $A, 'pagina', 'forum.example.org/viewtopic.php?f=2&t=12') === 3);
  $w = $rw(); $w->prepare("UPDATE days SET lines=1 WHERE day=?")->execute([$d(1)]); $w->prepare("UPDATE hits SET n=999 WHERE day=? AND src='servizio'")->execute([$d(1)]); $w = null;
  $run('--quiet');
  check('un giorno con meno righe di quelle nei log viene ricalcolato', $hit(1, $A, 'servizio', 'Telegram') === 3);
  rename("$ap/vecchio.gz", "$ap/access.log.2.gz");

  // --- file rovinato: il pannello va avanti, il job lo mette da parte e riparte
  copy($sd, "$sd.buono");
  file_put_contents($sd, str_repeat("non sono un database\n", 200));
  $ws = $adm();
  check('statistiche rovinate: il pannello risponde e lo dice', str_contains($ws, 'non si legge') && !str_contains($ws, 'class="use'));
  [$rc, $out] = $run('--quiet');
  check('  il job le mette da parte e le ricostruisce dai log', $rc === 0 && str_contains($out, 'illeggibili') && glob("$sd.rovinato-*")
    && $hit(1, $A, 'servizio', 'Telegram') === 3, $out);
  array_map('unlink', glob("$sd.rovinato-*"));
  rename("$sd.buono", $sd);
  @chmod($sd, 0640);

  // --- pannello: uso nel foglio di lavoro e filtro (A: 3 oggi + 17 ieri + 5 l'altro ieri = 25)
  $ws = $adm('uso=in');
  check('foglio di lavoro: «25 viste · 30 g» sull\'immagine usata, con le provenienze',
    str_contains($ws, '>25 viste · 30 g<') && str_contains($ws, 'forum.example.org/viewtopic.php?f=2&amp;t=12 (8)'));
  $in = page_ids('/gallery/admin/index.php?uso=in'); sort($in); $exp = [$A, $B, $D]; sort($exp);
  check('  filtro «in uso»: le tre richieste da fuori (alias compreso), non quella vista solo dalla galleria', $in === $exp, json_encode($in));
  $mai = page_ids('/gallery/admin/index.php?uso=mai');
  check('  filtro «mai viste»: c\'è quella vista solo dalla galleria, non quella usata', in_array($Cc, $mai, true) && !in_array($A, $mai, true));
  check('  l\'immagine vista solo dalla galleria è segnata «mai vista»',
    (bool) preg_match('~<code>' . preg_quote($Cc, '~') . '</code><br>\s*<span style="color:var\(--muted\)">[^<]*</span>\s*<br><span class="use off"~', $adm()));
  check('  il pulsante Cestina chiede conferma con l\'uso', str_contains($ws, 'data-confirm="«' . $A . '» è ancora in uso: 25 viste') && str_contains($ws, 'data-in-use="25 viste'));

  // --- avviso prima di eliminare, anche senza JavaScript
  admin_post(['act' => 'delete', 'short' => $A]);
  $ws = $adm();
  check('Cestina su un\'immagine in uso senza conferma: non succede nulla, il pannello mostra l\'avviso',
    row($A)['deleted_at'] === null && str_contains($ws, 'class="warnbox"') && str_contains($ws, 'Sposta comunque nel cestino') && str_contains($ws, '25 viste'));
  check('  l\'avviso si mostra una volta sola', !str_contains($adm(), 'Sposta comunque nel cestino'));
  admin_post(['act' => 'delete', 'short' => $A, 'in_use_ok' => '1']);
  check('  con la conferma: nel cestino', row($A)['deleted_at'] !== null);
  admin_post(['act' => 'restore', 'short' => $A], 'cestino');
  admin_post(['act' => 'delete', 'short' => $Cc]);
  check('  un\'immagine non in uso va nel cestino subito', row($Cc)['deleted_at'] !== null);
  admin_post(['act' => 'restore', 'short' => $Cc], 'cestino');
  admin_post(['act' => 'bulk', 'op' => 'trash'] + ids_fields([$A, $Cc]));
  check('multiple con una in uso, senza conferma: nessuna spostata', row($A)['deleted_at'] === null && row($Cc)['deleted_at'] === null && str_contains($adm(), 'Sposta comunque nel cestino'));
  admin_post(['act' => 'bulk', 'op' => 'trash', 'in_use_ok' => '1'] + ids_fields([$A, $Cc]));
  check('  con la conferma: tutte e due', row($A)['deleted_at'] !== null && row($Cc)['deleted_at'] !== null);
  admin_post(['act' => 'restore'] + ids_fields([$A, $Cc]), 'cestino');

  // --- cestino: immagini ancora richieste
  admin_post(['act' => 'delete', 'short' => $B, 'in_use_ok' => '1']);
  $tr = $adm('v=cestino');
  check('cestino: l\'immagine ancora richiesta è segnata, la conferma lo dice', str_contains($tr, 'ancora richiesta: 5 richieste') && str_contains($tr, 'Chi la cerca troverà un&#039;immagine mancante'));
  admin_post(['act' => 'purge', 'short' => $B], 'cestino');
  check('  elimina per sempre senza conferma: resta, con l\'avviso', row($B) !== null && str_contains($adm('v=cestino'), 'Elimina comunque per sempre'));
  admin_post(['act' => 'purge', 'short' => $B, 'in_use_ok' => '1'], 'cestino');
  check('  con la conferma: eliminata', row($B) === null);
  admin_post(['act' => 'delete', 'short' => $D, 'in_use_ok' => '1']);
  admin_post(['act' => 'delete', 'short' => $E]);
  db_bench()->prepare("UPDATE images SET deleted_at=? WHERE short IN (?,?)")->execute([time() - 31 * 86400, $D, $E]);
  $tr = $adm('v=cestino');
  check('dopo 30 giorni: eliminata quella mai richiesta, tenuta quella ancora richiesta', row($E) === null && row($D) !== null && str_contains($tr, 'tenuta: ancora richiesta'));
  admin_post(['act' => 'purge_all'], 'cestino');
  check('  svuota il cestino senza conferma: resta, con l\'avviso', row($D) !== null && str_contains($adm('v=cestino'), 'Svuota comunque il cestino'));

  // --- cruscotto
  $r = req('GET', '/gallery/admin/index.php?v=cruscotto', ['auth' => true]);
  $b = $r['body'];
  check('cruscotto: 200, le sezioni ci sono', $r['code'] === 200 && str_contains($b, 'Più viste') && str_contains($b, 'Viste al giorno')
    && str_contains($b, 'Da dove arrivano') && str_contains($b, 'Spazio per album') && str_contains($b, 'Caricamenti nel tempo'));
  check('  la più vista con le sue 25 viste', (bool) preg_match('~<code>' . preg_quote($A, '~') . '</code>.*?<td class="num">25</td>~s', $b));
  check('  provenienze: il forum con collegamento (senza referrer), Telegram',
    str_contains($b, 'href="https://forum.example.org/viewtopic.php?f=2&amp;t=12" target="_blank" rel="noopener noreferrer"') && str_contains($b, '>Telegram<'));
  check('  richieste a immagini che non ci sono più: eliminata e nel cestino', str_contains($b, 'Richieste a immagini che non ci sono più')
    && (bool) preg_match('~<code>' . preg_quote($B, '~') . '</code></td>\s*<td>eliminata~', $b) && str_contains($b, '>nel cestino</a>'));
  check('  una provenienza con HTML esce come testo', !str_contains($b, '<script>alert(1)') && str_contains($b, '&lt;script&gt;alert(1)'));
  $w = $rw(); $w->exec("UPDATE meta SET v='" . (time() - 3 * 86400) . "' WHERE k='updated_at'"); $w = null;
  check('statistiche vecchie di 3 giorni: il pannello avvisa che il job non gira', str_contains(req('GET', '/gallery/admin/?v=cruscotto', ['auth' => true])['body'], 'Statistiche d\'uso ferme al'));
  $run('--quiet');

  // --- senza statistiche non si blocca nulla
  rename($sd, "$sd.via");
  admin_post(['act' => 'delete', 'short' => $A]);
  check('senza statistiche Cestina non chiede nulla (nessun dato, nessun blocco)', row($A)['deleted_at'] !== null);
  admin_post(['act' => 'restore', 'short' => $A], 'cestino');
  rename("$sd.via", $sd);

  // --- dal web: niente
  check('stats/ e stats.db non si servono, _stats.php nemmeno', in_array(req('GET', '/gallery/stats/stats.db')['code'], [401, 404], true)
    && req('GET', '/gallery/stats/stats.db', ['auth' => true])['code'] === 404 && req('GET', '/gallery/stats/', ['auth' => true])['code'] === 404
    && req('GET', '/gallery/_stats.php', ['auth' => true])['code'] === 403);
  $r = req('GET', '/gallery/stats_update.php', ['auth' => true]);
  check('stats_update.php dal web: 403, solo da riga di comando', $r['code'] === 403 && str_contains($r['body'], 'solo CLI'));

  // --- pulizia: torna la copia delle statistiche reali (per --serve e per i gruppi seguenti)
  if (is_file("$sd.reale")) rename("$sd.reale", $sd); else @unlink($sd);
});

/* ======================================================================= */
group('API completa, immagini private, link a scadenza', function () use (&$first, $C, $WWW) {
  $api = function (string $method, string $path, $post = null, bool $token = true) use ($C): array {
    $o = ['headers' => $token ? ['X-Api-Token: ' . $C['token']] : []];
    if ($post !== null) $o['post'] = $post;
    $r = req($method, '/gallery/api/' . $path, $o);
    $r['json'] = json_decode($r['body'], true);
    return $r;
  };
  $adm = fn(string $qs = '') => req('GET', '/gallery/admin/index.php' . ($qs !== '' ? "?$qs" : ''), ['auth' => true, 'session' => true])['body'];

  // --- elenco e ricerca
  $r = $api('GET', 'images.php', null, false);
  check('senza token: 401 in JSON dall\'app (Apache non chiede la password)', $r['code'] === 401 && ($r['json']['ok'] ?? null) === false
    && !isset($r['h']['www-authenticate']));
  $r = $api('GET', 'images.php?per=2');
  check('elenco: 200, 2 per pagina, totale e pagine, campi della risposta', $r['code'] === 200 && count($r['json']['images'] ?? []) === 2
    && ($r['json']['total'] ?? 0) > 2 && ($r['json']['pages'] ?? 0) >= 2
    && !array_diff(['id', 'url', 'thumb', 'width', 'height', 'mime', 'size', 'public', 'private', 'trashed', 'tags', 'delete'], array_keys($r['json']['images'][0] ?? [])),
    substr($r['body'], 0, 120));
  $r = $api('GET', 'images.php?q=' . rawurlencode("id:$first"));
  check('ricerca id:CODICE: proprio quella', ($r['json']['images'][0]['id'] ?? '') === $first && ($r['json']['total'] ?? 0) === 1);
  check('elenco: POST rifiutato (405)', $api('POST', 'images.php', ['x' => '1'])['code'] === 405);

  // --- dettaglio, modifica, cestino, ripristino
  [, $j] = upload_json(make_img('api5', 901, 601, 'jpg'), 'image/jpeg', ['folder' => 'Banco']);
  $S = $j['id'] ?? '';
  check('preparazione: immagine larga 901 px', $S !== '');
  check('dettaglio di un codice inesistente: 404 in JSON', $api('GET', 'image.php?c=nonesiste99')['code'] === 404);
  $r = $api('GET', "image.php?c=$S");
  check('dettaglio: uso, link a scadenza, etichette', $r['code'] === 200 && isset($r['json']['image']['usage'], $r['json']['image']['shares'])
    && ($r['json']['image']['public'] ?? null) === true);
  $r = $api('POST', "image.php?c=$S", ['action' => 'update', 'title' => 'molo di sera', 'folder' => 'API Prova', 'tags' => 'mare, sera']);
  check('modifica: titolo, album, etichette', $r['code'] === 200 && row($S)['title'] === 'molo di sera' && row($S)['folder'] === 'API Prova'
    && ($r['json']['image']['tags'] ?? []) === ['mare', 'sera'], json_encode($r['json']['image']['tags'] ?? null));
  $r = $api('POST', "image.php?c=$S", ['action' => 'update', 'alt' => 'solo alt']);
  check('  i campi non mandati restano', row($S)['title'] === 'molo di sera' && row($S)['alt'] === 'solo alt');
  $api('POST', "image.php?c=$S", ['action' => 'update', 'private' => '1']);
  check('privata via API: /i/ e /t/ rispondono 404', (int) row($S)['private'] === 1 && req('GET', "/gallery/i/$S")['code'] === 404 && req('GET', "/gallery/t/$S")['code'] === 404);
  check('  view.php (dietro login) la mostra; senza login 401', req('GET', "/gallery/view.php?c=$S", ['auth' => true])['code'] === 200
    && req('GET', "/gallery/view.php?c=$S&thumb=1", ['auth' => true])['code'] === 200 && req('GET', "/gallery/view.php?c=$S")['code'] === 401);
  check('  private=1 nel filtro dell\'elenco', in_array($S, array_column($api('GET', 'images.php?private=1&per=100')['json']['images'] ?? [], 'id'), true));
  $api('POST', "image.php?c=$S", ['action' => 'update', 'private' => '0']);
  check('di nuovo pubblica: /i/ 200', req('GET', "/gallery/i/$S")['code'] === 200);
  $r = $api('DELETE', "image.php?c=$S");
  check('DELETE: nel cestino, /i/ 404', $r['code'] === 200 && ($r['json']['image']['trashed'] ?? null) === true && req('GET', "/gallery/i/$S")['code'] === 404);
  check('  trash=1 la elenca', in_array($S, array_column($api('GET', 'images.php?trash=1&per=100')['json']['images'] ?? [], 'id'), true));
  $api('POST', "image.php?c=$S", ['action' => 'restore']);
  check('  action=restore: di nuovo pubblica', req('GET', "/gallery/i/$S")['code'] === 200);
  check('azione sconosciuta: 400', $api('POST', "image.php?c=$S", ['action' => 'boh'])['code'] === 400);

  // --- link a scadenza via API
  $r = $api('POST', "image.php?c=$S", ['action' => 'share', 'seconds' => '120']);
  $tok = basename((string) ($r['json']['share']['url'] ?? ''));
  check('link a scadenza: 201, /i/TOKEN di 22 caratteri', $r['code'] === 201 && strlen($tok) === 22, substr($r['body'], 0, 120));
  $api('POST', "image.php?c=$S", ['action' => 'update', 'private' => '1']);
  $g = req('GET', "/gallery/i/$tok");
  check('  funziona anche con l\'immagine privata, senza login', $g['code'] === 200 && str_starts_with($g['h']['content-type'] ?? '', 'image/'));
  preg_match('~max-age=(\d+)~', $g['h']['cache-control'] ?? '', $m);
  check('  cache privata e non oltre la scadenza; noindex', str_starts_with($g['h']['cache-control'] ?? '', 'private') && (int) ($m[1] ?? 999) <= 120
    && str_contains($g['h']['x-robots-tag'] ?? '', 'noindex'), $g['h']['cache-control'] ?? '');
  check('  /t/TOKEN e la versione ridotta ?w=480 (WebP)', req('GET', "/gallery/t/$tok")['code'] === 200
    && (req('GET', "/gallery/i/$tok?w=480")['h']['content-type'] ?? '') === 'image/webp');
  check('  il codice dell\'immagine privata resta 404', req('GET', "/gallery/i/$S")['code'] === 404);
  check('  compare nel dettaglio', in_array($tok, array_column($api('GET', "image.php?c=$S")['json']['image']['shares'] ?? [], 'token'), true));
  db_bench()->prepare("UPDATE shares SET expires_at=? WHERE token=?")->execute([time() - 5, $tok]);
  check('scaduto: 410', req('GET', "/gallery/i/$tok")['code'] === 410);
  $r = $api('POST', "image.php?c=$S", ['action' => 'share', 'seconds' => '999999999']);
  $tok2 = basename((string) ($r['json']['share']['url'] ?? ''));
  check('durata oltre 90 giorni: ridotta a 90', (int) q1("SELECT expires_at - created_at FROM shares WHERE token=?", [$tok2]) === 90 * 86400);
  $api('POST', "image.php?c=$S", ['action' => 'unshare', 'token' => $tok2]);
  check('unshare: revocato, 404', req('GET', "/gallery/i/$tok2")['code'] === 404);
  check('  unshare di un token di un\'altra immagine: 404', $api('POST', "image.php?c=$first", ['action' => 'unshare', 'token' => $tok2])['code'] === 404);
  $api('DELETE', "image.php?c=$S");
  check('nessun link per un\'immagine nel cestino (409)', $api('POST', "image.php?c=$S", ['action' => 'share'])['code'] === 409);
  $api('POST', "image.php?c=$S", ['action' => 'restore']);

  // --- avviso "in uso" anche via API (statistiche finte: una pagina la mostra)
  $sd = $C['stats_db']; $ap = $C['apache'];
  if (is_file($sd)) rename($sd, "$sd.t5");
  $api('POST', "image.php?c=$S", ['action' => 'update', 'private' => '0']);
  $r = $api('POST', "image.php?c=$S", ['action' => 'share', 'seconds' => '3600']);
  $tok3 = basename((string) ($r['json']['share']['url'] ?? ''));
  file_put_contents("$ap/access.log", alog(1, '10:00:00', "/gallery/i/$S", 200, 'https://forum.example.org/viewtopic.php?t=5')
    . alog(1, '10:05:00', "/gallery/i/$tok3", 200, 'https://chat.example.net/stanza'));
  exec('php ' . escapeshellarg("$WWW/stats_update.php") . ' --quiet 2>&1', $out, $rc);
  $r = $api('GET', "image.php?c=$S");
  check('le viste di un link a scadenza contano per la sua immagine', ($r['json']['image']['usage']['views'] ?? 0) === 2, json_encode($r['json']['image']['usage'] ?? null));
  $r = $api('DELETE', "image.php?c=$S");
  check('DELETE di un\'immagine in uso: 409 con la spiegazione, resta', $r['code'] === 409 && str_contains($r['json']['in_use'] ?? '', '2 viste') && row($S)['deleted_at'] === null);
  $r = $api('POST', "image.php?c=$S", ['action' => 'update', 'private' => '1']);
  check('  anche renderla privata: 409', $r['code'] === 409 && (int) row($S)['private'] === 0);
  admin_post(['act' => 'private', 'short' => $S, 'on' => '1']);
  check('  e dal pannello: avviso, nulla cambia', (int) row($S)['private'] === 0 && str_contains($adm(), 'Rendi comunque privata'));
  $r = $api('DELETE', "image.php?c=$S&force=1");
  check('  con force=1: nel cestino', $r['code'] === 200 && row($S)['deleted_at'] !== null);
  $api('POST', "image.php?c=$S", ['action' => 'restore']);
  @unlink($sd); @unlink("$ap/access.log");
  if (is_file("$sd.t5")) rename("$sd.t5", $sd);

  // --- private e link dal pannello
  [, $j] = upload_json(make_img('priv5', 333, 222, 'png'), 'image/png', ['folder' => 'Banco', 'private' => '1']);
  $P = $j['id'] ?? '';
  check('caricata come privata: JSON lo dice, /i/ 404', $P !== '' && ($j['private'] ?? null) === true && (int) row($P)['private'] === 1 && req('GET', "/gallery/i/$P")['code'] === 404);
  $ws = $adm('q=' . rawurlencode("id:$P"));
  check('foglio di lavoro: «privata», provino da view.php, nessuno snippet pubblico',
    str_contains($ws, 'class="use priv"') && str_contains($ws, "view.php?c=$P&amp;thumb=1") && str_contains($ws, 'data-private="' . $P . '"')
    && !str_contains($ws, '&quot;id&quot;:&quot;' . $P . '&quot;') && str_contains($ws, 'Rendi pubblica'));
  $gal = req('GET', '/gallery/?q=' . rawurlencode("id:$P"), ['auth' => true])['body'];
  check('galleria: «privata», provino e immagine piena da view.php', str_contains($gal, 'class="use priv"') && str_contains($gal, "data-full=\"{$C['base']}/gallery/view.php?c=$P\""));
  admin_post(['act' => 'share', 'short' => $P, 'dur' => '3600']);
  $ws = $adm();
  check('Link a scadenza dal pannello: il link compare una volta, da copiare', (bool) preg_match('~<code class="sharelink">' . preg_quote($C['base'], '~') . '/gallery/i/([A-Za-z0-9_-]{22})</code>~', $ws, $mm)
    && str_contains($ws, 'data-copy="') && !str_contains($adm(), 'class="flash sharebox"'));
  $ptok = $mm[1] ?? '';
  check('  la privata si apre con il link, senza login', req('GET', "/gallery/i/$ptok")['code'] === 200);
  check('  durata non prevista: rifiutata', (admin_post(['act' => 'share', 'short' => $P, 'dur' => '42']) || true) && (int) q1("SELECT COUNT(*) FROM shares s JOIN images i ON i.id=s.image_id WHERE i.short=?", [$P]) === 1);
  $lk = $adm('v=link');
  check('vista Link: il link attivo, con l\'immagine', str_contains($lk, ">$P</code>") && str_contains($lk, "/gallery/i/$ptok") && str_contains($lk, '>attivo · fra'));
  admin_post(['act' => 'share_revoke', 'token' => $ptok], 'link');
  check('  revocato: 404 e «revocato» nella vista', req('GET', "/gallery/i/$ptok")['code'] === 404 && str_contains($adm('v=link'), '>revocato<'));
  db_bench()->prepare("UPDATE shares SET revoked_at=? WHERE token=?")->execute([time() - 31 * 86400, $ptok]);
  $adm('v=link');
  check('  revocato da oltre 30 giorni: sparisce', !q1("SELECT 1 FROM shares WHERE token=?", [$ptok]));
  admin_post(['act' => 'private', 'short' => $P, 'on' => '0']);
  check('Rendi pubblica: /i/ 200', (int) row($P)['private'] === 0 && req('GET', "/gallery/i/$P")['code'] === 200);
  admin_post(['act' => 'bulk', 'op' => 'private'] + ids_fields([$P, $S]));
  check('multiple: rese private (nessuna in uso: niente avviso)', (int) row($P)['private'] === 1 && (int) row($S)['private'] === 1);
  admin_post(['act' => 'bulk', 'op' => 'public'] + ids_fields([$P, $S]));
  check('  rese pubbliche', (int) row($P)['private'] === 0 && (int) row($S)['private'] === 0);
  $r = $api('POST', "image.php?c=$P", ['action' => 'share', 'seconds' => '600']);
  admin_post(['act' => 'delete', 'short' => $P]);
  admin_post(['act' => 'purge', 'short' => $P], 'cestino');
  check('eliminata per sempre: i suoi link se ne vanno con lei', row($P) === null && !q1("SELECT COUNT(*) FROM shares WHERE token=?", [basename((string) ($r['json']['share']['url'] ?? 'x'))]));
});

/* ======================================================================= */
group('Telegram, screenshot dal desktop', function () use (&$first, $C, $WWW, $TMP) {
  $tgd = $C['tg_dir'];
  $secret = substr(hash_hmac('sha256', 'gallery-telegram-webhook', $C['tg_token']), 0, 48);
  $tg = fn(array $u, ?string $sec = null) => req('POST', '/gallery/api/telegram.php', ['post' => json_encode($u),
          'headers' => array_merge(['Content-Type: application/json'], $sec === '' ? [] : ['X-Telegram-Bot-Api-Secret-Token: ' . ($sec ?? $secret)])]);
  $msg = fn(int $from, array $extra) => ['update_id' => random_int(1, 1 << 30), 'message' => ['message_id' => random_int(1, 1 << 20),
          'from' => ['id' => $from, 'first_name' => 'Prova', 'last_name' => 'Banco'], 'chat' => ['id' => $from, 'type' => 'private'], 'date' => time()] + $extra];
  $calls = fn() => array_map(fn($l) => json_decode($l, true), is_file("$tgd/calls.jsonl") ? file("$tgd/calls.jsonl", FILE_IGNORE_NEW_LINES) : []);
  $reply = function () use ($calls): string {
    foreach (array_reverse($calls()) as $c) if ($c['method'] === 'sendMessage') return (string) ($c['params']['text'] ?? '');
    return '';
  };
  $files = [];
  $addFile = function (string $id, string $src) use ($tgd, &$files): void {
    @mkdir("$tgd/files/photos", 0700, true);
    copy($src, "$tgd/files/photos/$id.bin");
    $files[$id] = ['path' => "photos/$id.bin", 'size' => filesize($src)];
    file_put_contents("$tgd/files.json", json_encode($files));
  };
  $adm = fn(string $qs = '') => req('GET', '/gallery/admin/index.php' . ($qs !== '' ? "?$qs" : ''), ['auth' => true, 'session' => true])['body'];
  $count = fn() => (int) q1("SELECT COUNT(*) FROM images");
  $ME = 777001;

  // --- webhook: solo con il segreto giusto
  check('webhook senza segreto: 403', $tg($msg($ME, ['text' => 'ciao']), '')['code'] === 403);
  check('  segreto sbagliato: 403; GET: 403', $tg($msg($ME, ['text' => 'ciao']), str_repeat('x', 48))['code'] === 403
    && req('GET', '/gallery/api/telegram.php')['code'] === 403);
  check('  nessuna risposta mandata a Telegram per richieste non firmate', !array_filter($calls(), fn($c) => $c['method'] === 'sendMessage'));

  // --- pannello: stato, collegamento del webhook
  $st = $adm('v=strumenti');
  check('Strumenti: bot riconosciuto, webhook non collegato, API col solo token', str_contains($st, '@banco_gallery_bot')
    && str_contains($st, 'Webhook <b>non collegato</b>') && str_contains($st, "L'API risponde con il solo token"));
  admin_post(['act' => 'tg_webhook', 'on' => '1'], 'strumenti');
  $w = json_decode((string) @file_get_contents("$tgd/webhook.json"), true) ?: [];
  check('Collega il webhook: indirizzo, segreto, solo messaggi', ($w['url'] ?? '') === $C['base'] . '/gallery/api/telegram.php'
    && ($w['secret_token'] ?? '') === $secret && ($w['allowed_updates'] ?? []) === ['message'], json_encode($w));
  check('  Strumenti lo mostra collegato', str_contains($adm('v=strumenti'), 'Webhook collegato'));

  // --- sconosciuti e collegamento con codice
  $n0 = $count();
  $tg($msg($ME, ['photo' => [['file_id' => 'nessuno', 'file_size' => 10]]]));
  check('utente non collegato: risposta con le istruzioni, niente caricato', str_contains($reply(), '/collega CODICE') && $count() === $n0);
  admin_post(['act' => 'tg_pair'], 'strumenti');
  $st = $adm('v=strumenti');
  check('codice di collegamento: mostrato una volta', (bool) preg_match('~/collega ([A-Z2-9]{8})</code>~', $st, $m) && !str_contains($adm('v=strumenti'), '/collega ' . $m[1]));
  $code = $m[1] ?? '';
  $tg($msg($ME, ['text' => '/collega SBAGLIAT']));
  check('  codice sbagliato: rifiutato', str_contains($reply(), 'Codice non valido') && !q1("SELECT 1 FROM telegram_users WHERE tg_id=?", [$ME]));
  $tg($msg($ME, ['text' => '/collega ' . strtolower($code)]));
  check('  codice giusto (anche minuscolo): collegato', str_contains($reply(), 'Collegato') && q1("SELECT name FROM telegram_users WHERE tg_id=?", [$ME]) === 'Prova Banco');
  $tg($msg(777002, ['text' => "/collega $code"]));
  check('  monouso: un secondo utente con lo stesso codice no', str_contains($reply(), 'Codice non valido') && !q1("SELECT 1 FROM telegram_users WHERE tg_id=777002"));
  admin_post(['act' => 'tg_pair'], 'strumenti');
  preg_match('~/collega ([A-Z2-9]{8})</code>~', $adm('v=strumenti'), $m2);
  db_bench()->exec("UPDATE settings SET v = substr(v, 1, instr(v, ':')) || '" . (time() - 1) . "' WHERE k='tg_pair'");
  $tg($msg(777003, ['text' => '/collega ' . ($m2[1] ?? '')]));
  check('  codice scaduto: rifiutato', str_contains($reply(), 'Codice non valido') && !q1("SELECT 1 FROM telegram_users WHERE tg_id=777003"));
  check('  Strumenti elenca chi è collegato', str_contains($adm('v=strumenti'), 'Prova Banco <span class="note">id 777001'));

  // --- foto
  $addFile('foto1', make_img('tgfoto', 640, 480, 'jpg'));
  $addFile('foto1s', make_img('tgfotos', 90, 67, 'jpg'));
  $n0 = $count();
  $tg($msg($ME, ['photo' => [['file_id' => 'foto1s', 'file_size' => 900], ['file_id' => 'foto1', 'file_size' => 9000]], 'caption' => 'Tramonto dal molo']));
  $new = db_bench()->query("SELECT * FROM images ORDER BY id DESC LIMIT 1")->fetch();
  check('foto: caricata (la misura più grande) nell\'album Telegram, didascalia come titolo', $count() === $n0 + 1 && $new['folder'] === 'Telegram'
    && $new['title'] === 'Tramonto dal molo' && (int) $new['width'] === 640, json_encode([$new['folder'] ?? null, $new['title'] ?? null, $new['width'] ?? null]));
  check('  risposta: il link', $reply() === $C['base'] . '/gallery/i/' . $new['short'], $reply());
  check('  file leggibile dal backup (non 0600 come un temporaneo)', (fileperms("$WWW/uploads/{$new['filename']}") & 0044) === 0044
    && req('GET', "/gallery/i/{$new['short']}")['code'] === 200);
  $tg($msg($ME, ['photo' => [['file_id' => 'foto1', 'file_size' => 9000]]]));
  check('  la stessa foto di nuovo: link esistente, nessun doppione', $count() === $n0 + 1 && str_contains($reply(), 'già in archivio') && str_contains($reply(), $new['short']));
  $gps = "$TMP/tg-gps.jpg";
  file_put_contents($gps, fx_jpeg(500, 400, 1, true, true));
  $addFile('doc1', $gps);
  $tg($msg($ME, ['document' => ['file_id' => 'doc1', 'mime_type' => 'image/jpeg', 'file_size' => filesize($gps), 'file_name' => 'scatto.jpg']]));
  $doc = db_bench()->query("SELECT * FROM images ORDER BY id DESC LIMIT 1")->fetch();
  check('immagine come file (originale): posizione GPS tolta, e lo dice', $count() === $n0 + 2 && !has_location("$WWW/uploads/{$doc['filename']}", 'image/jpeg')
    && str_contains($reply(), 'posizione GPS tolta'));
  $tg($msg($ME, ['document' => ['file_id' => 'pdf1', 'mime_type' => 'application/pdf', 'file_size' => 1000]]));
  check('un PDF: «mandami un\'immagine», niente caricato', str_contains($reply(), "Mandami un'immagine") && $count() === $n0 + 2);
  $tg($msg($ME, ['photo' => [['file_id' => 'grande', 'file_size' => 30 * 1048576]]]));
  check('troppo grande: rifiutata prima di scaricarla', str_starts_with($reply(), 'Troppo grande') && !array_filter($calls(), fn($c) => ($c['params']['file_id'] ?? '') === 'grande'));
  $tg($msg($ME, ['photo' => [['file_id' => 'sparito', 'file_size' => 100]]]));
  check('file che Telegram non trova: «non riesco a scaricare»', str_contains($reply(), 'Non riesco a scaricare'));
  file_put_contents("$TMP/finto.jpg", 'non sono un JPEG');
  $addFile('rotto', "$TMP/finto.jpg");
  $tg($msg($ME, ['photo' => [['file_id' => 'rotto', 'file_size' => 16]]]));
  check('file che non è un\'immagine: «non caricata» con il motivo', str_starts_with($reply(), 'Non caricata: unsupported mime') && $count() === $n0 + 2, $reply());
  $tg($msg($ME, ['text' => 'ciao']));
  check('un messaggio di testo: le istruzioni', str_contains($reply(), 'Mandami una foto'));
  check('nessuna risposta contiene il token del bot', !array_filter($calls(), fn($c) => str_contains(json_encode($c['params'] ?? []), $C['tg_token'])));

  // --- scollegare
  admin_post(['act' => 'tg_user_remove', 'tg_id' => (string) $ME], 'strumenti');
  $tg($msg($ME, ['photo' => [['file_id' => 'foto1', 'file_size' => 9000]]]));
  check('utente scollegato: torna a ricevere le istruzioni', str_contains($reply(), '/collega CODICE'));
  admin_post(['act' => 'tg_webhook', 'on' => '0'], 'strumenti');
  check('Scollega il webhook', !is_file("$tgd/webhook.json"));
  $sec = file_get_contents("$WWW/secret.php");
  file_put_contents("$WWW/secret.php", preg_replace("~\n\s*'TELEGRAM_BOT_TOKEN'[^\n]*~", '', $sec));
  check('senza token del bot: webhook 503, Strumenti spiega come accenderlo', $tg($msg($ME, ['text' => 'x']))['code'] === 503
    && str_contains($adm('v=strumenti'), '@BotFather'));
  file_put_contents("$WWW/secret.php", $sec);

  // --- ShareX: il file scaricato, usato come lo userebbe ShareX
  check('configurazioni: solo dietro login', req('GET', '/gallery/admin/tools.php?f=sharex')['code'] === 401);
  $r = req('GET', '/gallery/admin/tools.php?f=sharex&album=Desktop', ['auth' => true]);
  $sx = json_decode($r['body'], true);
  check('ShareX (.sxcu): indirizzo, token in intestazione, campo img, album', ($sx['RequestURL'] ?? '') === $C['base'] . '/gallery/api/upload.php'
    && ($sx['Headers']['X-Api-Token'] ?? '') === $C['token'] && ($sx['FileFormName'] ?? '') === 'img' && ($sx['Arguments']['folder'] ?? '') === 'Desktop'
    && ($sx['URL'] ?? '') === '{json:url}' && str_contains($r['h']['content-disposition'] ?? '', 'Gallery.sxcu') && ($r['h']['cache-control'] ?? '') === 'no-store');
  $h = []; foreach ($sx['Headers'] ?? [] as $k => $v) $h[] = "$k: $v";
  $up = req('POST', '/gallery/api/upload.php', ['headers' => $h, 'post' => ['img' => new CURLFile(make_img('sharex', 222, 111, 'png'), 'image/png', 'Screenshot.png')] + ($sx['Arguments'] ?? [])]);
  $uj = json_decode($up['body'], true);
  check('  caricamento come ShareX (solo token, niente password): link e cancellazione', $up['code'] === 200 && str_starts_with($uj['url'] ?? '', $C['base'] . '/gallery/i/')
    && isset($uj['thumb'], $uj['delete']) && row($uj['id'])['folder'] === 'Desktop', substr($up['body'], 0, 100));

  // --- Flameshot: lo script scaricato, con flameshot e gli appunti finti
  $r = req('GET', '/gallery/admin/tools.php?f=flameshot&album=Desktop', ['auth' => true]);
  $sh = "$TMP/gallery-screenshot.sh";
  file_put_contents($sh, $r['body']);
  exec('bash -n ' . escapeshellarg($sh) . ' 2>&1', $o, $rc);
  check('Flameshot: script valido, con indirizzo e token', $rc === 0 && str_contains($r['body'], $C['base'] . '/gallery/api/upload.php') && str_contains($r['body'], $C['token']));
  $bin = "$TMP/finti-bin"; @mkdir($bin);
  $png = make_img('flameshot', 321, 123, 'png');
  file_put_contents("$bin/flameshot", "#!/bin/sh\n[ \"\$FINTO_ESC\" = 1 ] && exit 1\ncat " . escapeshellarg($png) . "\n");
  file_put_contents("$bin/xclip", "#!/bin/sh\ncat > " . escapeshellarg("$TMP/appunti.txt") . "\n");
  file_put_contents("$bin/notify-send", "#!/bin/sh\nexit 0\n");
  array_map(fn($f) => chmod("$bin/$f", 0755), ['flameshot', 'xclip', 'notify-send']);
  @unlink("$TMP/appunti.txt");
  $env = 'env -u WAYLAND_DISPLAY PATH=' . escapeshellarg("$bin:" . getenv('PATH')) . ' ';
  exec($env . 'bash ' . escapeshellarg($sh) . ' 2>&1', $o2, $rc);
  $clip = (string) @file_get_contents("$TMP/appunti.txt");
  check('  cattura → caricamento → link negli appunti', $rc === 0 && str_starts_with($clip, $C['base'] . '/gallery/i/')
    && row(basename($clip))['folder'] === 'Desktop', "rc=$rc " . implode(' ', $o2));
  $n0 = $count();
  exec($env . 'FINTO_ESC=1 bash ' . escapeshellarg($sh) . ' 2>&1', $o3, $rc);
  check('  cattura annullata (Esc): esce senza caricare nulla', $rc === 0 && $count() === $n0);
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
  check('codice in sola lettura: modifica da admin funziona', $r['code'] === 303 && row((string)$s)['title'] === 'ro');
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

  // d) DB con copie (prima della v4): una riga sola, album delle copie -> etichette,
  //    codici delle copie -> alias che continuano a funzionare
  $cp = $C['bench'] . '/db/copie.db';
  $o = db_bench($cp);
  $o->exec("CREATE TABLE images (id INTEGER PRIMARY KEY, short TEXT UNIQUE NOT NULL, filename TEXT NOT NULL,
    mime TEXT NOT NULL, size INTEGER NOT NULL, width INTEGER, height INTEGER, title TEXT, alt TEXT,
    delkey TEXT NOT NULL, created_at INTEGER NOT NULL, folder TEXT DEFAULT '')");
  copy("$WWW/uploads/" . row($first)['filename'], "$WWW/uploads/copiaA.jpg");
  copy("$WWW/uploads/" . row($first)['filename'], "$WWW/uploads/unico.jpg");
  $ins = $o->prepare("INSERT INTO images(short,filename,mime,size,width,height,title,alt,delkey,created_at,folder) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  foreach ([['copiaAAA1', 'copiaA.jpg', 'Blog', 1000], ['copiaBBB2', 'copiaA.jpg', 'Forum', 2000],
            ['copiaCCC3', 'copiaA.jpg', 'Blog', 3000], ['unicoDDD4', 'unico.jpg', '', 4000]] as [$sh, $fn, $fo, $t]) {
    $ins->execute([$sh, $fn, row($first)['mime'], 1, 10, 10, "t $sh", null, 'k', $t, $fo]);
  }
  $o = null;
  use_db($cp);
  $r = req('GET', '/gallery/', ['auth' => true]);
  check('DB con copie: migrato, pagina 200', $r['code'] === 200 && versions($cp) === $EXPECT);
  $left = db_bench($cp)->query("SELECT short FROM images ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  check('  copie fuse: resta una riga per file (la piu\' vecchia)', $left === ['copiaAAA1', 'unicoDDD4'], json_encode($left));
  check('  i codici delle copie sono alias dell\'originale',
    db_bench($cp)->query("SELECT group_concat(short) FROM (SELECT short FROM short_aliases ORDER BY short)")->fetchColumn() === 'copiaBBB2,copiaCCC3');
  check('  l\'album diverso della copia e\' diventato un\'etichetta',
    db_bench($cp)->query("SELECT group_concat(t.name) FROM image_tags it JOIN tags t ON t.id=it.tag_id")->fetchColumn() === 'Forum');
  $a = req('GET', '/gallery/i/copiaAAA1'); $b = req('GET', '/gallery/i/copiaBBB2');
  check('  /i/ con il codice di una copia: stessa immagine; /t/ anche', $b['code'] === 200 && $b['body'] === $a['body'] && req('GET', '/gallery/t/copiaCCC3')['code'] === 200);
  admin_post(['act' => 'delete', 'short' => 'copiaAAA1']);
  check('  originale nel cestino: anche gli alias rispondono 404', req('GET', '/gallery/i/copiaBBB2')['code'] === 404);
  admin_post(['act' => 'purge', 'short' => 'copiaAAA1'], 'cestino');
  check('  eliminata per sempre: alias ed etichette se ne vanno con lei',
    !db_bench($cp)->query("SELECT COUNT(*) FROM short_aliases")->fetchColumn() && !db_bench($cp)->query("SELECT COUNT(*) FROM image_tags")->fetchColumn()
    && !is_file("$WWW/uploads/copiaA.jpg") && is_file("$WWW/uploads/unico.jpg"));

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
  $want = 8 + ($copy_migrated ? 1 : 0);   // DB nuovo, vecchio, con copie + 5 concorrenti (+ la copia, se era indietro)
  info("migrazioni registrate nel log: $migr (attese $want)");
  check('una sola migrazione per database', $migr === $want, "trovate $migr");
});

/* ======================================================================= */
printf("\n%d prove superate, %d fallite\n", $RESULTS['ok'], $RESULTS['fail']);
if ($RESULTS['fail']) { echo "fallite:\n  - " . implode("\n  - ", $RESULTS['failed']) . "\n"; exit(1); }
exit(0);
