<?php
if (php_sapi_name() !== 'cli') { require_once __DIR__."/config.php"; require_login(); }
else { require_once __DIR__."/config.php"; }
$rows = db()->query("SELECT filename,mime FROM images")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
  $src=$UPLOADS.'/'.$r['filename']; $dst=$THUMBS.'/'.$r['filename'];
  if (!is_file($src) || is_file($dst)) continue;
  $img=@imagecreatefromstring(file_get_contents($src)); if(!$img) continue;
  $ow=imagesx($img); $oh=imagesy($img);
  $scale=min(1.0,$THUMB_MAX_W/max($ow,$oh));
  $tw=(int)($ow*$scale); $th=(int)($oh*$scale);
  $thumb=imagecreatetruecolor($tw,$th);
  imagealphablending($thumb,false); imagesavealpha($thumb,true);
  imagecopyresampled($thumb,$img,0,0,0,0,$tw,$th,$ow,$oh);
  switch($r['mime']){
    case 'image/jpeg': imagejpeg($thumb,$dst,85); break;
    case 'image/png':  imagepng($thumb,$dst,6);   break;
    case 'image/gif':  imagegif($thumb,$dst);     break;
    case 'image/webp': imagewebp($thumb,$dst,85); break;
  }
  imagedestroy($thumb); imagedestroy($img);
}
echo "done\n";

