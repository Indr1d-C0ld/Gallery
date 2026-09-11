<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("solo CLI – usa: php migrate.php\n"); }
require_once __DIR__."/config.php";
$sql = file_get_contents(__DIR__."/schema.sql");
db()->exec($sql);
@mkdir($UPLOADS, 0775, true);
@mkdir($THUMBS, 0775, true);
echo "OK\n";

