<?php
/* Consegna per le pagine dietro login (galleria, pannello): anche le
 * immagini private e quelle nel cestino, che /i/ non serve.
 * /gallery/view.php non e' fra gli indirizzi pubblici di gallery.conf:
 * Apache chiede le credenziali, e require_login() le ricontrolla. */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_images.php";

require_login();
$short = preg_replace('~[^A-Za-z0-9_-]~', '', get_str('c'));
if ($short === '') { http_response_code(404); exit; }
$st = db()->prepare("SELECT filename, mime, width FROM images WHERE short=?");
$st->execute([$short]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit; }
send_image($r, get_str('thumb') === '1', get_int('w', 0, 0, 100000), 'private');
