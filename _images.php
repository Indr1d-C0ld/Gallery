<?php
/* =========================================================================
 * Gallery – pipeline unica delle immagini
 * ---------------------------------------------------------------------------
 * Fino al 2026-10 il codice delle miniature esisteva in quattro copie
 * (upload.php, i.php, admin/index.php, regen_thumbs.php) che avevano gia'
 * cominciato a divergere. Ogni lavoro sulle immagini sta QUI:
 *  - miniature (thumbs/FILE) e versioni ridotte in WebP (thumbs/FILE.wNNN.webp);
 *  - orientamento EXIF applicato a tutto cio' che produce GD;
 *  - rimozione dei dati di posizione (GPS) dagli originali;
 *  - un solo lavoro GD alla volta (lock), per non esaurire la memoria;
 *  - indirizzi pubblici e dati per gli snippet.
 * ========================================================================= */
require_once __DIR__ . '/config.php';

/* Versione del modo in cui si producono le immagini ridotte (?w=). Entra
 * negli indirizzi degli snippet: va aumentata quando la produzione cambia,
 * cosi' chi le ha in cache per un anno riceve quelle nuove. */
const IMAGE_PIPELINE = 1;

/* Misure della miniatura: lato lungo <= $max, mai sotto 1 pixel per lato. */
function thumb_size(int $ow, int $oh, int $max): array {
  $scale = min(1.0, $max / max($ow, $oh, 1));
  return [max(1, (int)round($ow * $scale)), max(1, (int)round($oh * $scale))];
}

/* ---------------------------------------------------------------------------
 * Un solo lavoro GD alla volta.
 * Decodificare una foto da 40 megapixel costa ~160 MB fuori dalla
 * contabilita' di PHP (vedi config.php): con le versioni ridotte generate su
 * richiesta da un endpoint pubblico, decine di richieste insieme potrebbero
 * esaurire la memoria del server. Chi trova il lock occupato aspetta fino a
 * $wait secondi, poi rinuncia (restituisce null) e il chiamante ripiega.
 * ------------------------------------------------------------------------- */
function with_gd_lock(callable $fn, float $wait = 8.0) {
  global $THUMBS;
  $fh = @fopen(rtrim($THUMBS, '/') . '/.gd.lock', 'c');
  if (!$fh) return $fn();                    // senza lock piuttosto che niente
  $deadline = microtime(true) + $wait;
  while (!flock($fh, LOCK_EX | LOCK_NB)) {
    if (microtime(true) > $deadline) { fclose($fh); return null; }
    usleep(50000);
  }
  try {
    return $fn();
  } finally {
    flock($fh, LOCK_UN);
    fclose($fh);
  }
}

/* ---------------------------------------------------------------------------
 * Metadati: dove stanno dentro il file.
 * Restituisce blocchi [tipo, inizio, lunghezza]:
 *   'tiff'  dati EXIF (struttura TIFF): JPEG APP1, PNG eXIf, WebP EXIF
 *   'xmp'   pacchetti XMP: JPEG APP1, WebP "XMP "
 *   'png'   chunk testuali dei PNG (tEXt/zTXt/iTXt), interi
 * Solo lettura; i JPEG si fermano all'inizio dei dati compressi.
 * ------------------------------------------------------------------------- */
function meta_blocks(string $b, string $mime): array {
  $out = []; $n = strlen($b);
  if ($mime === 'image/jpeg') {
    if (substr($b, 0, 2) !== "\xFF\xD8") return $out;
    $p = 2;
    while ($p + 4 <= $n && $b[$p] === "\xFF") {
      $m = ord($b[$p + 1]);
      if ($m === 0xFF) { $p++; continue; }                        // byte di riempimento
      if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD8)) { $p += 2; continue; }
      if ($m === 0xDA || $m === 0xD9) break;                      // dati compressi / fine
      $len = unpack('n', substr($b, $p + 2, 2))[1];
      if ($len < 2 || $p + 2 + $len > $n) break;
      $seg = $p + 4; $slen = $len - 2;
      if ($m === 0xE1) {
        if (substr($b, $seg, 6) === "Exif\0\0") $out[] = ['tiff', $seg + 6, $slen - 6];
        elseif (str_starts_with(substr($b, $seg, 34), 'http://ns.adobe.com/')) $out[] = ['xmp', $seg, $slen];
      }
      $p += 2 + $len;
    }
  } elseif ($mime === 'image/png') {
    if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return $out;
    $p = 8;
    while ($p + 12 <= $n) {
      $len = unpack('N', substr($b, $p, 4))[1]; $type = substr($b, $p + 4, 4);
      if ($p + 12 + $len > $n) break;
      if ($type === 'eXIf') $out[] = ['tiff', $p + 8, $len];
      elseif (in_array($type, ['tEXt', 'zTXt', 'iTXt'], true)) $out[] = ['png', $p, 12 + $len];
      if ($type === 'IEND') break;
      $p += 12 + $len;
    }
  } elseif ($mime === 'image/webp') {
    if (substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') return $out;
    $p = 12;
    while ($p + 8 <= $n) {
      $type = substr($b, $p, 4); $len = unpack('V', substr($b, $p + 4, 4))[1];
      if ($p + 8 + $len > $n) break;
      if ($type === 'EXIF') {
        $d = $p + 8; $l = $len;
        if (substr($b, $d, 6) === "Exif\0\0") { $d += 6; $l -= 6; }
        $out[] = ['tiff', $d, $l];
      } elseif ($type === 'XMP ') {
        $out[] = ['xmp', $p + 8, $len];
      }
      $p += 8 + $len + ($len & 1);
    }
  }
  return $out;
}

/* ---- struttura TIFF (EXIF) ---- */
const TIFF_TYPE_SIZE = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

function tiff_le(string $b, int $t, int $len): ?bool {
  if ($len < 8) return null;
  $bo = substr($b, $t, 2);
  return $bo === 'II' ? true : ($bo === 'MM' ? false : null);
}
function tiff_u16(string $b, int $at, bool $le): int { return unpack($le ? 'v' : 'n', substr($b, $at, 2))[1]; }
function tiff_u32(string $b, int $at, bool $le): int { return unpack($le ? 'V' : 'N', substr($b, $at, 4))[1]; }

/* Voci di un IFD: [tag, tipo, conteggio, posizione assoluta della voce]. */
function tiff_entries(string $b, int $t, int $len, bool $le, int $ifd): array {
  if ($ifd < 8 || $ifd + 2 > $len) return [];
  $count = tiff_u16($b, $t + $ifd, $le);
  if ($ifd + 2 + 12 * $count > $len) return [];
  $e = [];
  for ($i = 0; $i < $count; $i++) {
    $at = $t + $ifd + 2 + 12 * $i;
    $e[] = [tiff_u16($b, $at, $le), tiff_u16($b, $at + 2, $le), tiff_u32($b, $at + 4, $le), $at];
  }
  return $e;
}

/* Posizione dell'IFD GPS (tag 0x8825 nell'IFD0), o null. */
function tiff_gps_ifd(string $b, int $t, int $len, bool $le): ?int {
  foreach (tiff_entries($b, $t, $len, $le, tiff_u32($b, $t + 4, $le)) as [$tag, , , $at]) {
    if ($tag === 0x8825) return tiff_u32($b, $at + 8, $le);
  }
  return null;
}

function tiff_gps_count(string $b, int $t, int $len): int {
  $le = tiff_le($b, $t, $len);
  if ($le === null || ($gps = tiff_gps_ifd($b, $t, $len, $le)) === null) return 0;
  return count(tiff_entries($b, $t, $len, $le, $gps));
}

function tiff_orientation(string $b, int $t, int $len): int {
  $le = tiff_le($b, $t, $len);
  if ($le === null) return 1;
  foreach (tiff_entries($b, $t, $len, $le, tiff_u32($b, $t + 4, $le)) as [$tag, $type, , $at]) {
    if ($tag === 0x0112 && $type === 3) {
      $o = tiff_u16($b, $at + 8, $le);
      return ($o >= 1 && $o <= 8) ? $o : 1;
    }
  }
  return 1;
}

/* Svuota l'IFD GPS sul posto: valori azzerati, voci azzerate, conteggio a
 * zero. La lunghezza del file non cambia, quindi nessun altro offset si
 * sposta e l'immagine resta identica byte per byte fuori da quei punti.
 * Orientamento e resto dell'EXIF restano. */
function tiff_strip_gps(string &$b, int $t, int $len): bool {
  $le = tiff_le($b, $t, $len);
  if ($le === null || ($gps = tiff_gps_ifd($b, $t, $len, $le)) === null) return false;
  $entries = tiff_entries($b, $t, $len, $le, $gps);
  if (!$entries) return false;
  foreach ($entries as [, $type, $cnt, $at]) {
    $size = (TIFF_TYPE_SIZE[$type] ?? 1) * $cnt;
    if ($size > 4) {
      $off = tiff_u32($b, $at + 8, $le);
      if ($off >= 8 && $off + $size <= $len) $b = substr_replace($b, str_repeat("\0", $size), $t + $off, $size);
    }
    $b = substr_replace($b, str_repeat("\0", 12), $at, 12);
  }
  $b = substr_replace($b, "\0\0", $t + $gps, 2);
  return true;
}

/* ---- XMP: coordinate come attributi o elementi (exif:GPS..., drone-dji:Gps...) ---- */
const XMP_GPS_PATTERNS = [
  '~\s[A-Za-z][\w.-]*:gps\w*\s*=\s*"[^"]*"~i',
  "~\\s[A-Za-z][\\w.-]*:gps\\w*\\s*=\\s*'[^']*'~i",
  '~<([A-Za-z][\w.-]*:gps\w*)\b[^>]*>.*?</\1\s*>~is',
  '~<[A-Za-z][\w.-]*:gps\w*\b[^>]*/>~i',
];
const GPS_TEXT_HINT = '~[A-Za-z][\w.-]*:gps(latitude|longitude|position|coordinates)~i';

/* Sostituisce con spazi (stessa lunghezza: XML ancora valido). */
function xmp_strip_gps(string &$b, int $off, int $len): bool {
  $x = substr($b, $off, $len);
  $y = preg_replace_callback(XMP_GPS_PATTERNS, fn($m) => str_repeat(' ', strlen($m[0])), $x);
  if ($y === null || $y === $x) return false;
  $b = substr_replace($b, $y, $off, $len);
  return true;
}

/* Testo di un chunk tEXt/zTXt/iTXt dei PNG, decompresso se serve. */
function png_text(string $type, string $data): string {
  $nul = strpos($data, "\0");
  if ($nul === false) return $data;
  $key = substr($data, 0, $nul); $rest = substr($data, $nul + 1);
  if ($type === 'zTXt') return $key . "\0" . (string) @gzuncompress(substr($rest, 1));
  if ($type === 'iTXt') {
    $compressed = ($rest[0] ?? "\0") === "\1";
    $parts = explode("\0", substr($rest, 2), 3);          // lingua, parola tradotta, testo
    $text = $parts[2] ?? '';
    return $key . "\0" . ($compressed ? (string) @gzuncompress($text) : $text);
  }
  return $data;
}

/* Un chunk testuale porta una posizione? XMP con GPS, oppure un intero
 * EXIF in esadecimale ("Raw profile type exif", lo scrive ImageMagick). */
function png_text_has_gps(string $type, string $data): bool {
  $t = png_text($type, $data);
  if (preg_match(GPS_TEXT_HINT, $t)) return true;
  if (stripos($t, 'Raw profile type') !== 0) return false;
  // "Raw profile type exif\0" + "\nexif\n   LUNGHEZZA\n" + righe esadecimali
  $lines = explode("\n", substr($t, (int) strpos($t, "\0") + 1));
  $hex = preg_replace('~[^0-9a-f]~i', '', implode('', array_slice($lines, 3)));
  $raw = @hex2bin(strlen($hex) % 2 ? substr($hex, 0, -1) : $hex);
  if (!is_string($raw) || $raw === '') return false;
  $i = strpos($raw, "Exif\0\0");
  $tiff = $i !== false ? substr($raw, $i + 6) : $raw;
  return tiff_gps_count($tiff, 0, strlen($tiff)) > 0;
}

/* C'e' una posizione nel file? EXIF GPS non vuoto, XMP con GPS, chunk PNG,
 * e come rete di sicurezza qualunque nome di tag GPS in chiaro nel file. */
function has_location(string $path, string $mime): bool {
  $b = @file_get_contents($path);
  if ($b === false) return false;
  foreach (meta_blocks($b, $mime) as [$kind, $off, $len]) {
    if ($kind === 'tiff' && tiff_gps_count($b, $off, $len) > 0) return true;
    if ($kind === 'xmp' && preg_match(GPS_TEXT_HINT, substr($b, $off, $len))) return true;
    if ($kind === 'png' && png_text_has_gps(substr($b, $off + 4, 4), substr($b, $off + 8, $len - 12))) return true;
  }
  return (bool) preg_match(GPS_TEXT_HINT, $b);
}

/* PNG: eXIf svuotato del GPS (con CRC ricalcolato), chunk testuali con
 * posizione tolti per intero. I chunk sono indipendenti: nessun offset da
 * correggere. */
function png_strip_gps(string $b): string {
  $out = substr($b, 0, 8); $p = 8; $n = strlen($b);
  while ($p + 12 <= $n) {
    $len = unpack('N', substr($b, $p, 4))[1]; $type = substr($b, $p + 4, 4);
    if ($p + 12 + $len > $n) { $out .= substr($b, $p); break; }
    $data = substr($b, $p + 8, $len);
    if ($type === 'eXIf' && tiff_strip_gps($data, 0, $len)) {
      $out .= pack('N', $len) . $type . $data . pack('N', crc32($type . $data));
    } elseif (in_array($type, ['tEXt', 'zTXt', 'iTXt'], true) && png_text_has_gps($type, $data)) {
      // via il chunk
    } else {
      $out .= substr($b, $p, 12 + $len);
    }
    $p += 12 + $len;
    if ($type === 'IEND') break;
  }
  return $out;
}

/* Toglie i dati di posizione dal file, sul posto. Esito:
 *   0  non c'erano;
 *   1  tolti senza toccare l'immagine (stessi pixel, stessa compressione);
 *   2  ultima risorsa: immagine risalvata senza metadati (orientamento
 *      applicato ai pixel), perche' la posizione era in una forma sconosciuta;
 *  -1  impossibile toglierla.
 */
function strip_location(string $path, string $mime): int {
  if (!has_location($path, $mime)) return 0;
  $b = (string) file_get_contents($path);
  if ($mime === 'image/png') {
    $b = png_strip_gps($b);
  } else {
    // dal fondo verso l'inizio: gli interventi non spostano nulla, ma cosi'
    // resta vero anche se un giorno uno dovesse farlo
    foreach (array_reverse(meta_blocks($b, $mime)) as [$kind, $off, $len]) {
      if ($kind === 'tiff') tiff_strip_gps($b, $off, $len);
      elseif ($kind === 'xmp') xmp_strip_gps($b, $off, $len);
    }
  }
  file_put_contents($path, $b);
  if (!has_location($path, $mime)) return 1;
  return (reencode_plain($path, $mime) && !has_location($path, $mime)) ? 2 : -1;
}

/* ---------------------------------------------------------------------------
 * Orientamento EXIF.
 * I telefoni salvano molte foto verticali come orizzontali piu' un'etichetta
 * che dice di ruotarle: i browser la rispettano, GD no. Tutto cio' che GD
 * produce (miniature, versioni ridotte) va quindi ruotato qui.
 * ------------------------------------------------------------------------- */
function orientation_of(string $bin, string $mime): int {
  foreach (meta_blocks($bin, $mime) as [$kind, $off, $len]) {
    if ($kind === 'tiff') return tiff_orientation($bin, $off, $len);
  }
  return 1;
}

function image_orientation(string $path, string $mime): int {
  $b = @file_get_contents($path);
  return $b === false ? 1 : orientation_of($b, $mime);
}

/* Applica l'orientamento (1-8) a un'immagine GD; restituisce quella giusta. */
function orient_gd($img, int $o) {
  switch ($o) {
    case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
    case 3: return imagerotate($img, 180, 0);
    case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
    case 5: imageflip($img, IMG_FLIP_HORIZONTAL); return imagerotate($img, 90, 0);
    case 6: return imagerotate($img, -90, 0);
    case 7: imageflip($img, IMG_FLIP_HORIZONTAL); return imagerotate($img, -90, 0);
    case 8: return imagerotate($img, 90, 0);
  }
  return $img;
}

/* ---------------------------------------------------------------------------
 * Il lavoro vero: decodifica, orienta, ridimensiona, salva.
 * - controlla i pixel PRIMA di decodificare (difesa in profondita' oltre a
 *   upload.php punto 3b);
 * - scrive su un file temporaneo e poi lo rinomina: una richiesta
 *   concorrente non trova mai un file a meta'.
 * ------------------------------------------------------------------------- */
function render_scaled(string $src, string $dst, string $mime, string $out, int $maxW, int $maxH, int $quality): bool {
  global $MAX_PIXELS;
  if (!function_exists('imagecreatefromstring') || !is_file($src)) return false;

  $gi = @getimagesize($src);
  if (!is_array($gi) || ($gi[0] ?? 0) < 1 || ($gi[1] ?? 0) < 1) return false;
  if ($gi[0] * $gi[1] > $MAX_PIXELS) return false;

  $bin = (string) @file_get_contents($src);
  $img = @imagecreatefromstring($bin);
  if (!$img) return false;
  $img = orient_gd($img, orientation_of($bin, $mime));
  unset($bin);

  $ow = imagesx($img); $oh = imagesy($img);
  $scale = min(1.0, $maxW / $ow, $maxH / $oh);
  $tw = max(1, (int)round($ow * $scale));
  $th = max(1, (int)round($oh * $scale));

  $res = imagecreatetruecolor($tw, $th);
  imagealphablending($res, false);
  imagesavealpha($res, true);
  imagecopyresampled($res, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);
  imagedestroy($img);

  @mkdir(dirname($dst), 0775, true);
  $tmp = $dst . '.tmp-' . bin2hex(random_bytes(4));
  switch ($out) {
    case 'image/jpeg': $ok = imagejpeg($res, $tmp, $quality); break;
    case 'image/png':  $ok = imagepng($res,  $tmp, 6);        break;
    case 'image/gif':  $ok = imagegif($res,  $tmp);           break;
    case 'image/webp': $ok = imagewebp($res, $tmp, $quality); break;
    default:           $ok = false;
  }
  imagedestroy($res);

  if ($ok && @rename($tmp, $dst)) return true;
  @unlink($tmp);
  return false;
}

/* Esegue un lavoro GD sotto lock e senza mai lanciare: un errore di GD (in
 * PHP 8 molti sono ValueError) viene registrato e diventa "non fatto". In
 * upload.php un errore fatale lascerebbe l'originale senza la sua riga. */
function gd_job(string $what, callable $fn): bool {
  try {
    return (bool) with_gd_lock($fn);
  } catch (Throwable $e) {
    error_log("gallery: $what non riuscita — " . $e->getMessage());
    return false;
  }
}

/* Miniatura: lato lungo $THUMB_MAX_W, stesso formato dell'originale. */
function make_thumb(string $src, string $dst, string $mime): bool {
  global $THUMB_MAX_W;
  return gd_job('miniatura di ' . basename($src),
    fn() => render_scaled($src, $dst, $mime, $mime, $THUMB_MAX_W, $THUMB_MAX_W, 85));
}

/* Versione ridotta in WebP, larga $w (vedi derived_width()). */
function make_derived(string $src, string $dst, string $mime, int $w): bool {
  return gd_job("versione da $w px di " . basename($src),
    fn() => is_file($dst) || render_scaled($src, $dst, $mime, 'image/webp', $w, PHP_INT_MAX, 82));
}

/* Ultima risorsa di strip_location(): immagine risalvata senza metadati,
 * con l'orientamento applicato ai pixel. Per JPEG e WebP comporta una
 * nuova compressione. */
function reencode_plain(string $path, string $mime): bool {
  return gd_job('pulizia dei metadati di ' . basename($path), function () use ($path, $mime) {
    $gi = @getimagesize($path);
    if (!is_array($gi)) return false;
    $tmp = $path . '.clean';
    $ok = render_scaled($path, $tmp, $mime, $mime, PHP_INT_MAX, PHP_INT_MAX, 92);
    return $ok && @rename($tmp, $path);
  });
}

/* ---------------------------------------------------------------------------
 * Versioni ridotte (?w=) e percorsi.
 * ------------------------------------------------------------------------- */

/* Larghezza da servire per una richiesta ?w=: la prima fra $DERIVED_WIDTHS
 * che basta, purche' piu' stretta dell'originale; 0 = servi l'originale.
 * Solo larghezze fisse: niente disco riempito chiedendo ?w=1, ?w=2, ...
 * Le GIF restano originali (GD perderebbe l'animazione). */
function derived_width(int $want, int $origW, string $mime): int {
  global $DERIVED_WIDTHS;
  if ($want <= 0 || $mime === 'image/gif') return 0;
  foreach ($DERIVED_WIDTHS as $w) {
    if ($w >= $want) return $w < $origW ? $w : 0;
  }
  return 0;
}

/* Le larghezze ridotte che hanno senso per un'immagine (per srcset). */
function derived_widths_for(int $origW, string $mime): array {
  global $DERIVED_WIDTHS;
  if ($mime === 'image/gif') return [];
  return array_values(array_filter($DERIVED_WIDTHS, fn($w) => $w < $origW));
}

function upload_path(string $filename): string {
  global $UPLOADS;
  return rtrim($UPLOADS, '/') . '/' . $filename;
}

function thumb_path(string $filename): string {
  global $THUMBS;
  return rtrim($THUMBS, '/') . '/' . $filename;
}

function derived_path(string $filename, int $w): string {
  return thumb_path($filename) . '.w' . $w . '.webp';
}

/* Toglie le versioni ridotte di un file (si rigenerano alla richiesta). */
function remove_derived(string $filename): int {
  $n = 0;
  foreach (glob(thumb_path($filename) . '.w*.webp') ?: [] as $f) $n += (int) @unlink($f);
  return $n;
}

/* ---------------------------------------------------------------------------
 * Indirizzi pubblici e snippet.
 * ------------------------------------------------------------------------- */

/* Indirizzi pubblici (hotlink) di originale e miniatura. */
function public_urls(string $short): array {
  global $BASE_URL;
  return ['url' => $BASE_URL . '/i/' . $short, 'thumb' => $BASE_URL . '/t/' . $short];
}

/* Versione della miniatura: cambia quando la si rigenera, cosi' l'indirizzo
 * negli snippet cambia e non resta in cache per un anno. */
function thumb_version(string $filename): int {
  $t = thumb_path($filename);
  return is_file($t) ? (int) filemtime($t) : 0;
}

/* Dati da cui nascono gli snippet (URL, Markdown, BBCode, HTML, Hugo...).
 * I formati veri e propri sono definiti in un solo posto, il JavaScript di
 * _theme.php: qui si preparano solo i dati, per la pagina e per le risposte
 * JSON di upload.php. */
function snippet_data(array $r): array {
  $u = public_urls((string) $r['short']);
  $u['thumb'] .= '?v=' . thumb_version((string) ($r['filename'] ?? ''));
  return ['id' => (string) $r['short']] + $u + [
    'width'  => (int) ($r['width'] ?? 0),
    'height' => (int) ($r['height'] ?? 0),
    'title'  => (string) ($r['title'] ?? ''),
    'alt'    => (string) ($r['alt'] ?? ''),
    'folder' => (string) ($r['folder'] ?? ''),
    'sizes'  => derived_widths_for((int) ($r['width'] ?? 0), (string) ($r['mime'] ?? '')),
    'pv'     => IMAGE_PIPELINE,
  ];
}

/* Attributo data-snip pronto per l'HTML (JSON con escape per attributo). */
function snippet_attr(array $r): string {
  $json = json_encode(snippet_data($r), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return 'data-snip="' . htmlspecialchars((string) $json, ENT_QUOTES) . '"';
}

/* Il componente "copia": pulsante nel formato in uso + tutti gli altri.
 * I pulsanti li riempie il JavaScript del tema. */
function snippet_box(array $r, string $label = 'link &amp; embed'): string {
  return '<div class="snip" ' . snippet_attr($r) . '>'
       . '<button type="button" class="snip-copy" data-snip-copy>copia</button>'
       . '<details><summary>' . $label . '</summary><div class="copies" data-snip-all></div></details>'
       . '</div>';
}
