<?php
require_once __DIR__."/config.php";
require_once __DIR__."/_archive.php";
$short = get_str('c');
$key   = get_str('k');
if ($short === '' || $key === '') { http_response_code(400); exit("bad req"); }
$q = db()->prepare("SELECT filename,delkey FROM images WHERE short=?");
$q->execute([$short]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r || !hash_equals($r['delkey'],$key)) { http_response_code(403); exit("forbidden"); }

// nel cestino: TRASH_DAYS giorni per ripensarci, poi eliminazione definitiva
trash_images([$short]);
echo "deleted\n";

