<?php
require_once __DIR__ . "/config.php";
require_login();
header('Content-Type: text/plain; charset=utf-8');
echo "SAPI=" . php_sapi_name() . PHP_EOL;
echo "Loaded INI=" . php_ini_loaded_file() . PHP_EOL;
echo "upload_max_filesize=" . ini_get('upload_max_filesize') . PHP_EOL;
echo "post_max_size=" . ini_get('post_max_size') . PHP_EOL;
echo "max_file_uploads=" . ini_get('max_file_uploads') . PHP_EOL;
echo "memory_limit=" . ini_get('memory_limit') . PHP_EOL;
