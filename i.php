<?php
require_once __DIR__ . "/config.php";

function get_short_from_request(): ?string {
  // 1) query string ?c=SHORT
  $c = get_str('c');
  if ($c !== '') return preg_replace('~[^A-Za-z0-9_-]~', '', $c);

  // 2) path /gallery/i/SHORT o /gallery/t/SHORT (se rewrite attiva)
  $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  $parts = explode('/', trim($uri, '/'));
  // atteso: [gallery, i|t|i.php, SHORT]
  if (count($parts) >= 3 && $parts[0] === 'gallery' && ($parts[1] === 'i' || $parts[1] === 't')) {
    return preg_replace('~[^A-Za-z0-9_-]~', '', (string)$parts[2]);
  }
  return null;
}

function ensure_thumb(string $src, string $dst, string $mime): bool {
  global $THUMB_MAX_W;

  if (!function_exists('imagecreatefromstring')) return false;
  if (!is_file($src)) return false;

  $img = @imagecreatefromstring(@file_get_contents($src));
  if (!$img) return false;

  $ow = imagesx($img); $oh = imagesy($img);
  $scale = min(1.0, $THUMB_MAX_W / max($ow, $oh));
  $tw = max(1, (int)round($ow * $scale));
  $th = max(1, (int)round($oh * $scale));

  $thumb = imagecreatetruecolor($tw, $th);
  imagealphablending($thumb, false);
  imagesavealpha($thumb, true);
  imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);

  $ok = false;
  @mkdir(dirname($dst), 0775, true);

  switch ($mime) {
    case 'image/jpeg': $ok = imagejpeg($thumb, $dst, 85); break;
    case 'image/png':  $ok = imagepng($thumb,  $dst, 6);  break;
    case 'image/gif':  $ok = imagegif($thumb,  $dst);     break;
    case 'image/webp': $ok = imagewebp($thumb, $dst, 85); break;
    default: $ok = false; break;
  }

  imagedestroy($thumb);
  imagedestroy($img);
  return (bool)$ok;
}

$short = get_short_from_request();
if (!$short) { http_response_code(404); exit; }

$want_thumb = false;

// thumb=1 esplicito
if (get_str('thumb') === '1') $want_thumb = true;

// se richiesto via path /gallery/t/SHORT (anche senza rewrite)
$uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (preg_match('~^/gallery/t/([A-Za-z0-9_-]+)~', $uri_path)) $want_thumb = true;

$st = db()->prepare("SELECT filename, mime FROM images WHERE short=?");
$st->execute([$short]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit; }

$fname = $r['filename'];
$mime  = $r['mime'];

$full_path  = rtrim($UPLOADS, "/") . "/" . $fname;
$thumb_path = rtrim($THUMBS,  "/") . "/" . $fname;

if (!is_file($full_path)) { http_response_code(404); exit; }

$serve_path = $full_path;

// Se voglio la thumb: prova a servirla, se manca prova a generarla, altrimenti fallback su full
if ($want_thumb && $USE_THUMBS) {
  if (!is_file($thumb_path)) {
    // tenta rigenerazione al volo
    ensure_thumb($full_path, $thumb_path, $mime);
  }
  if (is_file($thumb_path)) {
    $serve_path = $thumb_path;
  }
}

// Header corretti
header("Content-Type: " . $mime);
header('Content-Disposition: inline; filename="' . preg_replace('~[^A-Za-z0-9._-]~', '', $fname) . '"');
header("X-Content-Type-Options: nosniff");
// endpoint pubblico per hotlink: consente l'embed cross-origin
header("Cross-Origin-Resource-Policy: cross-origin");
header("Access-Control-Allow-Origin: *");

// Cache: thumb e full possono essere cacheate a lungo (sono immutabili per SHORT)
header("Cache-Control: public, max-age=31536000, immutable");

// ETag/Last-Modified per cache efficiente
$mtime = @filemtime($serve_path) ?: time();
$etag  = '"' . sha1($serve_path . '|' . $mtime . '|' . filesize($serve_path)) . '"';
header("ETag: " . $etag);
header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");

// 304 support
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
  http_response_code(304);
  exit;
}

header("Content-Length: " . filesize($serve_path));
readfile($serve_path);
exit;

