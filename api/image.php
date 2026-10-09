<?php
/* Una sola immagine: GET dettaglio, DELETE nel cestino, POST action=...
 * (vedi l'elenco in _api.php). Cestinare o rendere privata un'immagine
 * ancora in uso (statistiche dai log) risponde 409 senza force=1: e'
 * l'avviso del pannello, per i programmi. */
require_once __DIR__ . "/../_api.php";
api_auth();

$short = preg_replace('~[^A-Za-z0-9_-]~', '', get_str('c') !== '' ? get_str('c') : post_str('c'));
$r = $short !== '' ? api_find($short) : null;
if (!$r) api_fail(404, 'immagine non trovata');

$method = $_SERVER['REQUEST_METHOD'];
$act    = $method === 'GET' ? 'get' : ($method === 'DELETE' ? 'trash' : post_str('action'));
$force  = get_str('force') === '1' || post_str('force') === '1';
$busy   = fn() => $force ? [] : in_use_notes([$short]);

switch ($act) {
  case 'get':
    api_out(200, ['ok' => true, 'image' => api_image($r, true)]);

  case 'update':
    $set = []; $args = [];
    foreach (['title', 'alt'] as $k) if (array_key_exists($k, $_POST)) { $set[] = "$k=?"; $args[] = post_str($k) !== '' ? post_str($k) : null; }
    if (array_key_exists('folder', $_POST)) { $set[] = "folder=?"; $args[] = norm_folder(post_str('folder')); }
    $priv = array_key_exists('private', $_POST) ? post_str('private') === '1' : null;
    if ($priv === true && empty($r['private']) && ($n = $busy())) {
      api_fail(409, "immagine in uso: renderla privata rompe i link pubblici (force=1 per farlo comunque)", ['in_use' => reset($n)]);
    }
    if ($set) db()->prepare("UPDATE images SET " . implode(', ', $set) . " WHERE id=?")->execute(array_merge($args, [$r['id']]));
    if (array_key_exists('tags', $_POST)) image_set_tags((int) $r['id'], parse_tags(post_str('tags')));
    if ($priv !== null) set_private([$short], $priv);
    api_out(200, ['ok' => true, 'image' => api_image(api_find($short), true)]);

  case 'trash':
    if ($r['deleted_at'] === null) {
      if ($n = $busy()) api_fail(409, "immagine in uso: force=1 per spostarla comunque nel cestino", ['in_use' => reset($n)]);
      trash_images([$short]);
    }
    api_out(200, ['ok' => true, 'image' => api_image(api_find($short))]);

  case 'restore':
    restore_images([$short]);
    api_out(200, ['ok' => true, 'image' => api_image(api_find($short))]);

  case 'share':
    $sec = (int) (post_str('seconds') !== '' ? post_str('seconds') : 86400);
    $s = share_create($short, $sec, post_str('note'));
    if (!$s) api_fail(409, "immagine nel cestino: niente link");
    api_out(201, ['ok' => true, 'share' => ['token' => $s['token'], 'url' => $s['url'], 'thumb' => $s['thumb'],
                                              'expires_at' => date('c', $s['expires_at'])]]);

  case 'unshare':
    $tok = post_str('token');
    $mine = in_array($tok, array_column(shares_list($short), 'token'), true);
    if (!$mine || !share_revoke($tok)) api_fail(404, 'link non trovato (o gia\' revocato) per questa immagine');
    api_out(200, ['ok' => true]);

  default:
    api_fail(400, 'azione sconosciuta: GET, DELETE oppure POST action=update|trash|restore|share|unshare');
}
