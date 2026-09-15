<?php
require_once __DIR__ . "/../config.php";

if ($API_TOKEN === 'metti-qui-un-token-lungo' || strlen((string)$API_TOKEN) < 16) {
  http_response_code(503);
  exit("API disabilitata: imposta un API_TOKEN forte in secret.php\n");
}

$sent = get_str('token') ?: (is_string($_SERVER['HTTP_X_API_TOKEN'] ?? null) ? $_SERVER['HTTP_X_API_TOKEN'] : '');
if (!hash_equals((string)$API_TOKEN, (string)$sent)) {
  http_response_code(401);
  exit("no\n");
}

define('GALLERY_API_CALL', true);
$_FILES['img']  = $_FILES['file'] ?? $_FILES['image'] ?? $_FILES['img'] ?? null;
$_POST['title'] = $_POST['title'] ?? null;
$_POST['alt']   = $_POST['alt'] ?? null;

require __DIR__ . "/../upload.php";
