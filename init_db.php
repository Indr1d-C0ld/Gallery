<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("solo CLI – usa: php migrate.php\n"); }
/* Mantenuto per compatibilita': fa esattamente quello che fa migrate.php. */
require __DIR__ . "/migrate.php";
