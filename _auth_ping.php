<?php
/* Ritorna 204 se la richiesta è autenticata (Basic Auth Apache), 401 altrimenti.
   Usato per rilevamento login lato client. */
require_once __DIR__ . "/config.php";
header('Cache-Control: no-store');
if (current_user() === null) {
  header('WWW-Authenticate: Basic realm="Gallery - Accesso riservato"');
  http_response_code(401);
  exit;
}
http_response_code(204);
