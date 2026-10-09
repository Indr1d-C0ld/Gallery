<?php
/* Caricamento via API: token SOLO nell'intestazione X-Api-Token (vedi _api.php).
 * Il resto e' upload.php, che in questo caso risponde in JSON. */
require_once __DIR__ . "/../_api.php";
api_auth();

define('GALLERY_API_CALL', true);
$_FILES['img'] = $_FILES['file'] ?? $_FILES['image'] ?? $_FILES['img'] ?? null;

require __DIR__ . "/../upload.php";
