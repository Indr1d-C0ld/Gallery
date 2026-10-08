<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_images.php";

/* Chiamata via API con token (api/upload.php): salta login di sessione e CSRF,
   il token è già stato verificato. Altrimenti: richiede auth Apache + CSRF. */
if (!defined('GALLERY_API_CALL')) {
  require_login();
  csrf_check();
}

/* Risposta in JSON per l'API e per il caricatore della pagina (che chiede
 * "Accept: application/json"); per il form classico testo e redirect. */
$JSON = defined('GALLERY_API_CALL') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function upload_fail(int $code, string $msg): void {
  global $JSON;
  http_response_code($code);
  if ($JSON) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  } else {
    echo $msg;
  }
  exit;
}

/* Esito positivo: immagine appena salvata oppure doppione gia' in archivio. */
function upload_done(array $row, bool $duplicate): void {
  global $JSON, $BASE_URL;
  if ($JSON) {
    $out = ['ok' => true, 'duplicate' => $duplicate] + snippet_data($row);
    if (defined('GALLERY_API_CALL')) {
      $out['delete'] = $BASE_URL . '/delete.php?c=' . $row['short'] . '&k=' . $row['delkey'];
    }
    header('Content-Type: application/json');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }
  $target = $BASE_URL . "/?ok=" . rawurlencode($row['short']) . ($duplicate ? '&dup=1' : '');
  header("Location: " . $target, true, 303);
  exit;
}

function norm_folder($s) {
  $s = trim($s ?? '');
  $s = preg_replace('~[^a-zA-Z0-9 _-]~', '', $s);
  return substr($s, 0, 64);
}

if (empty($_FILES['img'])) {
  upload_fail(400, "no file");
}

$f = $_FILES['img'];

/* 1) Upload error dettagliato */
if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
  $map = [
    UPLOAD_ERR_INI_SIZE   => 'INI_SIZE (upload_max_filesize)',
    UPLOAD_ERR_FORM_SIZE  => 'FORM_SIZE (MAX_FILE_SIZE)',
    UPLOAD_ERR_PARTIAL    => 'PARTIAL',
    UPLOAD_ERR_NO_FILE    => 'NO_FILE',
    UPLOAD_ERR_NO_TMP_DIR => 'NO_TMP_DIR',
    UPLOAD_ERR_CANT_WRITE => 'CANT_WRITE',
    UPLOAD_ERR_EXTENSION  => 'EXTENSION'
  ];
  $code = $f['error'] ?? -1;
  $msg  = $map[$code] ?? 'UNKNOWN';
  upload_fail(400, "upload error: $code ($msg)");
}

/* 2) Size */
if (!isset($f['size']) || $f['size'] <= 0) {
  upload_fail(400, "empty upload");
}
if ($f['size'] > $MAX_BYTES) {
  upload_fail(400, "too large (max " . (int)$MAX_BYTES . " bytes)");
}

/* 3) MIME */
$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($fi, $f['tmp_name']);
finfo_close($fi);

if (!isset($ALLOWED[$mime])) {
  upload_fail(415, "unsupported mime: " . $mime);
}
$ext = $ALLOWED[$mime];

/* 3b) Dimensioni in pixel — PRIMA di qualunque decodifica.
 * getimagesize() legge solo l'intestazione: una "decompression bomb" (file
 * piccolo che dichiara decine di migliaia di pixel per lato) verrebbe
 * altrimenti espansa in RAM da GD fino a saturare la memoria della macchina.
 * Il controllo avviene sul file temporaneo: una bomba non tocca mai uploads/.
 */
$gi = @getimagesize($f['tmp_name']);
if (!is_array($gi) || ($gi[0] ?? 0) < 1 || ($gi[1] ?? 0) < 1) {
  upload_fail(415, "immagine non leggibile o corrotta");
}
if ($gi[0] * $gi[1] > $MAX_PIXELS) {
  upload_fail(413, sprintf(
    "immagine troppo grande: %d×%d = %.1f megapixel (max %.0f)",
    $gi[0], $gi[1], ($gi[0] * $gi[1]) / 1e6, $MAX_PIXELS / 1e6
  ));
}

/* 4) Folder (cartella logica in DB) */
$folder = norm_folder(post_str('folder'));

/* 4b) Doppioni: la stessa immagine, byte per byte, e' gia' in archivio?
 * Allora si restituisce quella invece di salvarne un secondo file: lo stesso
 * screenshot incollato due volte da' lo stesso link. Fra piu' righe con lo
 * stesso file (nate da "Copia") si preferisce quella nello stesso album,
 * poi la piu' vecchia. Se il DB non risponde si prosegue: l'inserimento
 * del punto 9 fallira' comunque in modo pulito. */
$sha = hash_file('sha256', $f['tmp_name']);
$existing = null;
try {
  $q = db()->prepare("SELECT * FROM images WHERE sha256=? ORDER BY (COALESCE(folder,'')=?) DESC, id ASC");
  $q->execute([$sha, $folder]);
  foreach ($q->fetchAll() as $r) {
    if (is_file(upload_path($r['filename']))) { $existing = $r; break; }
  }
} catch (Throwable $e) {
  error_log('gallery upload: ricerca doppioni non riuscita — ' . $e->getMessage());
}
if ($existing) {
  upload_done($existing, true);
}

/* 5) Genera identificativi */
$short  = shortcode(7);
$delkey = bin2hex(random_bytes(8));

$fname = $short . "." . $ext;
$dest  = upload_path($fname);

/* 6) Salva file */
if (!is_dir($UPLOADS)) {
  @mkdir($UPLOADS, 0775, true);
}
if (!move_uploaded_file($f['tmp_name'], $dest)) {
  upload_fail(500, "store failed");
}

/* 7) Dati immagine (gia' misurate al punto 3b) */
$w = $gi[0];
$h = $gi[1];

/* 8) Thumbnail (se fallisce, i.php ritenta alla prima richiesta) */
if ($USE_THUMBS) {
  make_thumb($dest, thumb_path($fname), $mime);
}

/* 9) DB insert
 * Il file e' gia' su disco: se l'inserimento fallisce (disco pieno, DB
 * bloccato, collisione di short-code) senza questo blocco resterebbe un file
 * orfano — invisibile dall'interfaccia ma che occupa spazio per sempre.
 */
$row = [
  'short'      => $short,
  'filename'   => $fname,
  'mime'       => $mime,
  'size'       => (int)filesize($dest),
  'width'      => $w,
  'height'     => $h,
  'title'      => post_str('title') ?: null,
  'alt'        => post_str('alt')   ?: null,
  'delkey'     => $delkey,
  'created_at' => time(),
  'folder'     => $folder,
  'sha256'     => $sha,
];
try {
  db()->prepare("INSERT INTO images(" . implode(',', array_keys($row)) . ")
                 VALUES(" . implode(',', array_fill(0, count($row), '?')) . ")")
      ->execute(array_values($row));
} catch (Throwable $e) {
  @unlink($dest);                 // niente riga, niente file
  @unlink(thumb_path($fname));    // e nemmeno la miniatura
  error_log('gallery upload: insert fallito per ' . $fname . ' — ' . $e->getMessage());
  upload_fail(500, "salvataggio non riuscito");
}

/* 10) Risposta */
upload_done($row, false);
