<?php
require_once __DIR__ . "/config.php";

/* Chiamata via API con token (api/upload.php): salta login di sessione e CSRF,
   il token è già stato verificato. Altrimenti: richiede auth Apache + CSRF. */
if (!defined('GALLERY_API_CALL')) {
  require_login();
  csrf_check();
}

function norm_folder($s) {
  $s = trim($s ?? '');
  $s = preg_replace('~[^a-zA-Z0-9 _-]~', '', $s);
  return substr($s, 0, 64);
}

if (empty($_FILES['img'])) {
  http_response_code(400);
  exit("no file");
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
  http_response_code(400);
  exit("upload error: $code ($msg)");
}

/* 2) Size */
if (!isset($f['size']) || $f['size'] <= 0) {
  http_response_code(400);
  exit("empty upload");
}
if ($f['size'] > $MAX_BYTES) {
  http_response_code(400);
  exit("too large (max ".(int)$MAX_BYTES." bytes)");
}

/* 3) MIME */
$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($fi, $f['tmp_name']);
finfo_close($fi);

if (!isset($ALLOWED[$mime])) {
  http_response_code(415);
  exit("unsupported mime: " . $mime);
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
  http_response_code(415);
  exit("immagine non leggibile o corrotta");
}
if ($gi[0] * $gi[1] > $MAX_PIXELS) {
  http_response_code(413);
  exit(sprintf(
    "immagine troppo grande: %d×%d = %.1f megapixel (max %.0f)",
    $gi[0], $gi[1], ($gi[0] * $gi[1]) / 1e6, $MAX_PIXELS / 1e6
  ));
}

/* 4) Folder (cartella logica in DB) */
$folder = norm_folder(post_str('folder'));

/* 5) Genera identificativi */
$short  = shortcode(7);
$delkey = bin2hex(random_bytes(8));

$fname = $short . "." . $ext;
$dest  = rtrim($UPLOADS, "/") . "/" . $fname;

/* 6) Salva file */
if (!is_dir($UPLOADS)) {
  @mkdir($UPLOADS, 0775, true);
}
if (!move_uploaded_file($f['tmp_name'], $dest)) {
  http_response_code(500);
  exit("store failed");
}

/* 7) Dati immagine (gia' misurate al punto 3b) */
$w = $gi[0];
$h = $gi[1];

/* 8) Thumbnail */
if ($USE_THUMBS) {
  if (!is_dir($THUMBS)) {
    @mkdir($THUMBS, 0775, true);
  }

  // genera thumb solo se GD disponibile
  if (function_exists('imagecreatefromstring')) {
    $img = @imagecreatefromstring(@file_get_contents($dest));
    if ($img) {
      $ow = imagesx($img);
      $oh = imagesy($img);

      $scale = min(1.0, $THUMB_MAX_W / max($ow, $oh));
      $tw = max(1, (int)round($ow * $scale));
      $th = max(1, (int)round($oh * $scale));

      $thumb = imagecreatetruecolor($tw, $th);
      imagealphablending($thumb, false);
      imagesavealpha($thumb, true);

      imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);

      $tpath = rtrim($THUMBS, "/") . "/" . $fname;

      switch ($mime) {
        case 'image/jpeg': imagejpeg($thumb, $tpath, 85); break;
        case 'image/png':  imagepng($thumb,  $tpath, 6);  break;
        case 'image/gif':  imagegif($thumb,  $tpath);     break;
        case 'image/webp': imagewebp($thumb, $tpath, 85); break;
      }

      imagedestroy($thumb);
      imagedestroy($img);
    }
  }
}

/* 9) DB insert
 * Il file e' gia' su disco: se l'inserimento fallisce (disco pieno, DB
 * bloccato, collisione di short-code) senza questo blocco resterebbe un file
 * orfano — invisibile dall'interfaccia ma che occupa spazio per sempre.
 */
try {
  $stmt = db()->prepare("
    INSERT INTO images(short, filename, mime, size, width, height, title, alt, delkey, created_at, folder)
    VALUES(?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmt->execute([
    $short,
    $fname,
    $mime,
    (int)filesize($dest),
    $w, $h,
    post_str('title') ?: null,
    post_str('alt')   ?: null,
    $delkey,
    time(),
    $folder
  ]);
} catch (Throwable $e) {
  @unlink($dest);                                  // niente riga, niente file
  @unlink(rtrim($THUMBS, "/") . "/" . $fname);     // e nemmeno la miniatura
  error_log('gallery upload: insert fallito per ' . $fname . ' — ' . $e->getMessage());
  http_response_code(500);
  exit("salvataggio non riuscito");
}

/* 10) Risposta */
if (defined('GALLERY_API_CALL')) {
  header('Content-Type: application/json');
  echo json_encode([
    'ok'    => true,
    'id'    => $short,
    'url'   => $BASE_URL . '/i/' . $short,
    'thumb' => $BASE_URL . '/i.php?c=' . $short . '&thumb=1',
    'delete'=> $BASE_URL . '/delete.php?c=' . $short . '&k=' . $delkey,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
$target = $BASE_URL . "/?ok=" . rawurlencode($short);
header("Location: " . $target, true, 303);
exit;

