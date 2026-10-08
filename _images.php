<?php
/* =========================================================================
 * Gallery – pipeline unica delle immagini derivate
 * ---------------------------------------------------------------------------
 * Fino al 2026-10 il codice delle miniature esisteva in quattro copie
 * (upload.php, i.php, admin/index.php, regen_thumbs.php) che avevano gia'
 * cominciato a divergere: regen_thumbs.php calcolava le misure senza il
 * minimo di 1 pixel, e un'immagine molto allungata produceva una miniatura
 * alta zero pixel (errore fatale di imagecreatetruecolor).
 * Ogni miglioria futura (orientamento EXIF, formati, misure) va fatta QUI.
 * ========================================================================= */
require_once __DIR__ . '/config.php';

/* Misure della miniatura: lato lungo <= $max, mai sotto 1 pixel per lato. */
function thumb_size(int $ow, int $oh, int $max): array {
  $scale = min(1.0, $max / max($ow, $oh, 1));
  return [max(1, (int)round($ow * $scale)), max(1, (int)round($oh * $scale))];
}

/* Genera la miniatura di $src in $dst nel formato indicato da $mime.
 * - controlla i pixel PRIMA di decodificare (le bombe non arrivano a GD:
 *   vedi upload.php punto 3b; qui e' difesa in profondita' per i file gia'
 *   presenti e per regen/admin, che prima decodificavano senza controllo);
 * - scrive su un file temporaneo nella stessa cartella e poi lo rinomina,
 *   cosi' una richiesta concorrente non serve mai una miniatura a meta';
 * - non lancia mai: un errore di GD (in PHP 8 molti sono ValueError) viene
 *   registrato e diventa "nessuna miniatura". In upload.php un errore fatale
 *   qui lascerebbe l'originale su disco senza la sua riga nel DB.
 * Restituisce true se la miniatura e' stata scritta.
 */
function make_thumb(string $src, string $dst, string $mime): bool {
  try {
    return make_thumb_gd($src, $dst, $mime);
  } catch (Throwable $e) {
    error_log('gallery: miniatura non generata per ' . basename($src) . ' — ' . $e->getMessage());
    return false;
  }
}

function make_thumb_gd(string $src, string $dst, string $mime): bool {
  global $THUMB_MAX_W, $MAX_PIXELS;

  if (!function_exists('imagecreatefromstring') || !is_file($src)) return false;

  $gi = @getimagesize($src);
  if (!is_array($gi) || ($gi[0] ?? 0) < 1 || ($gi[1] ?? 0) < 1) return false;
  if ($gi[0] * $gi[1] > $MAX_PIXELS) return false;

  $img = @imagecreatefromstring((string) @file_get_contents($src));
  if (!$img) return false;

  $ow = imagesx($img); $oh = imagesy($img);
  [$tw, $th] = thumb_size($ow, $oh, $THUMB_MAX_W);

  $thumb = imagecreatetruecolor($tw, $th);
  imagealphablending($thumb, false);
  imagesavealpha($thumb, true);
  imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);
  imagedestroy($img);

  @mkdir(dirname($dst), 0775, true);
  $tmp = $dst . '.tmp-' . bin2hex(random_bytes(4));

  switch ($mime) {
    case 'image/jpeg': $ok = imagejpeg($thumb, $tmp, 85); break;
    case 'image/png':  $ok = imagepng($thumb,  $tmp, 6);  break;
    case 'image/gif':  $ok = imagegif($thumb,  $tmp);     break;
    case 'image/webp': $ok = imagewebp($thumb, $tmp, 85); break;
    default:           $ok = false;
  }
  imagedestroy($thumb);

  if ($ok && @rename($tmp, $dst)) return true;
  @unlink($tmp);
  return false;
}

/* Percorsi canonici di originale e miniatura per un filename del DB. */
function upload_path(string $filename): string {
  global $UPLOADS;
  return rtrim($UPLOADS, '/') . '/' . $filename;
}

function thumb_path(string $filename): string {
  global $THUMBS;
  return rtrim($THUMBS, '/') . '/' . $filename;
}
