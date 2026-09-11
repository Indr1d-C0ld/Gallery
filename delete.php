<?php
require_once __DIR__."/config.php";
$short = $_GET['c'] ?? null;
$key   = $_GET['k'] ?? null;
if (!$short || !$key) { http_response_code(400); exit("bad req"); }
$q = db()->prepare("SELECT filename,delkey FROM images WHERE short=?");
$q->execute([$short]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r || !hash_equals($r['delkey'],$key)) { http_response_code(403); exit("forbidden"); }

@unlink($UPLOADS."/".$r['filename']);
@unlink($THUMBS."/".$r['filename']);
db()->prepare("DELETE FROM images WHERE short=?")->execute([$short]);
echo "deleted\n";

