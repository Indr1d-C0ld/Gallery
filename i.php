<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_images.php";

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

$short = get_short_from_request();
if (!$short) { http_response_code(404); exit; }

$want_thumb = false;

// thumb=1 esplicito
if (get_str('thumb') === '1') $want_thumb = true;

// se richiesto via path /gallery/t/SHORT (anche senza rewrite)
$uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (preg_match('~^/gallery/t/([A-Za-z0-9_-]+)~', $uri_path)) $want_thumb = true;

$st = db()->prepare("SELECT filename, mime, width, deleted_at, private FROM images WHERE short=?");
$st->execute([$short]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  // codice di una vecchia copia, fusa nell'originale dalla migrazione v4
  $st = db()->prepare("SELECT i.filename, i.mime, i.width, i.deleted_at, i.private FROM short_aliases a
                       JOIN images i ON i.id = a.image_id WHERE a.short=?");
  $st->execute([$short]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
}
$left = null;            // secondi che restano a un link a scadenza
if (!$r && strlen($short) >= 20) {
  // link a scadenza (/i/TOKEN, /t/TOKEN): vale anche per le immagini private.
  // Scaduto: 410, cosi' chi lo apre capisce che non e' un errore di battitura.
  $st = db()->prepare("SELECT i.filename, i.mime, i.width, i.deleted_at, i.private, s.expires_at, s.revoked_at
                       FROM shares s JOIN images i ON i.id = s.image_id WHERE s.token=?");
  $st->execute([$short]);
  if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
    if ($s['revoked_at'] !== null) { http_response_code(404); exit; }
    if ((int) $s['expires_at'] <= time()) { http_response_code(410); exit; }
    $r = $s;
    $left = (int) $s['expires_at'] - time();
  }
}
// nel cestino o privata: non piu' pubblica (i file restano)
if (!$r || $r['deleted_at'] !== null || ($left === null && (int) $r['private'] === 1)) { http_response_code(404); exit; }

send_image($r, $want_thumb, get_int('w', 0, 0, 100000), $left ?? 'public');
