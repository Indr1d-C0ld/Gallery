<?php
/* Rigenera le miniature mancanti (con --all: tutte).
 *   php regen_thumbs.php [--all]
 * Sul live va eseguito come www-data (il DB e thumbs/ sono suoi). */
if (php_sapi_name() !== 'cli') { require_once __DIR__."/config.php"; require_login(); }
else { require_once __DIR__."/config.php"; }
require_once __DIR__ . "/_images.php";

$all = in_array('--all', $argv ?? [], true);
$made = 0; $failed = 0;
foreach (db()->query("SELECT filename,mime FROM images")->fetchAll() as $r) {
  $src = upload_path($r['filename']); $dst = thumb_path($r['filename']);
  if (!is_file($src) || (!$all && is_file($dst))) continue;
  make_thumb($src, $dst, $r['mime']) ? $made++ : $failed++;
}
echo "done: $made generate, $failed non riuscite\n";
