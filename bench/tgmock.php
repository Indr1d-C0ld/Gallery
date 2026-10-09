<?php
/* Finta API dei bot Telegram per il banco (la avvia run.sh con `php -S`).
 * Risponde come api.telegram.org a getMe, setWebhook, deleteWebhook,
 * getWebhookInfo, getFile, sendMessage e serve i file /file/bot<token>/<path>.
 * Stato in TGMOCK_DIR (variabile d'ambiente):
 *   calls.jsonl  una riga per chiamata: {"method":..., "params":...}
 *   files.json   file_id -> {"path": percorso nel mock, "size": n} (li mette tests.php)
 *   files/       i file da scaricare
 *   webhook.json l'ultimo setWebhook */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$dir   = getenv('TGMOCK_DIR');
$token = getenv('TGMOCK_TOKEN');
$path  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');
$out = function (array $r, int $code = 200) { http_response_code($code); echo json_encode($r, JSON_UNESCAPED_SLASHES); return true; };

if (preg_match('~^/file/bot([^/]+)/(.+)$~', $path, $m)) {
  if ($m[1] !== $token) return $out(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
  $f = realpath("$dir/files/" . $m[2]);
  if (!$f || !str_starts_with($f, realpath("$dir/files") . '/')) { http_response_code(404); return true; }
  header('Content-Type: application/octet-stream');
  readfile($f);
  return true;
}
if (!preg_match('~^/bot([^/]+)/(\w+)$~', $path, $m)) return $out(['ok' => false, 'error_code' => 404, 'description' => 'Not Found'], 404);
if ($m[1] !== $token) return $out(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
$method = $m[2];
$params = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
file_put_contents("$dir/calls.jsonl", json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

switch ($method) {
  case 'getMe':
    return $out(['ok' => true, 'result' => ['id' => 12345, 'is_bot' => true, 'first_name' => 'Banco', 'username' => 'banco_gallery_bot']]);
  case 'setWebhook':
    file_put_contents("$dir/webhook.json", json_encode($params));
    return $out(['ok' => true, 'result' => true, 'description' => 'Webhook was set']);
  case 'deleteWebhook':
    @unlink("$dir/webhook.json");
    return $out(['ok' => true, 'result' => true]);
  case 'getWebhookInfo':
    $w = is_file("$dir/webhook.json") ? json_decode(file_get_contents("$dir/webhook.json"), true) : [];
    return $out(['ok' => true, 'result' => ['url' => $w['url'] ?? '', 'pending_update_count' => 0]]);
  case 'getFile':
    $files = is_file("$dir/files.json") ? json_decode(file_get_contents("$dir/files.json"), true) : [];
    $f = $files[$params['file_id'] ?? ''] ?? null;
    if (!$f) return $out(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: invalid file_id'], 400);
    return $out(['ok' => true, 'result' => ['file_id' => $params['file_id'], 'file_size' => $f['size'], 'file_path' => $f['path']]]);
  case 'sendMessage':
    return $out(['ok' => true, 'result' => ['message_id' => random_int(100, 99999), 'text' => $params['text'] ?? '']]);
}
return $out(['ok' => false, 'error_code' => 404, 'description' => 'Not Found: method'], 404);
