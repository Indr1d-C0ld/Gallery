<?php
require_once __DIR__."/config.php";
$short = get_str('c');
$key   = get_str('k');
if ($short === '' || $key === '') { http_response_code(400); exit("bad req"); }
$q = db()->prepare("SELECT filename,delkey FROM images WHERE short=?");
$q->execute([$short]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r || !hash_equals($r['delkey'],$key)) { http_response_code(403); exit("forbidden"); }

delete_image($short);   // rimuove i file solo se nessun'altra copia li usa
echo "deleted\n";

