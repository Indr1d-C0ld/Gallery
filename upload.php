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

/* 4) Folder (cartella logica in DB) */
$folder = norm_folder($_POST['folder'] ?? '');

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

/* 7) Dati immagine */
$w = null; $h = null;
$gi = @getimagesize($dest);
if (is_array($gi)) { $w = $gi[0] ?? null; $h = $gi[1] ?? null; }

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

/* 9) DB insert */
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
  $_POST['title'] ?? null,
  $_POST['alt'] ?? null,
  $delkey,
  time(),
  $folder
]);

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

