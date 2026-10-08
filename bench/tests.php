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
require $WWW . '/_images.php';              // per le prove unitarie: definizioni, nessun accesso al DB
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
