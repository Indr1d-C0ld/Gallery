<?php
/* Webhook del bot Telegram (vedi _telegram.php). Pubblico per Apache come
 * il resto di api/, ma risponde solo a chi presenta il segreto fissato
 * quando il webhook e' stato collegato dal pannello. Agli aggiornamenti
 * validi risponde sempre 200, anche se qualcosa va storto: altrimenti
 * Telegram ritenterebbe lo stesso messaggio per ore. */
require_once __DIR__ . "/../_telegram.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!tg_enabled()) { http_response_code(503); exit('{"ok":false}'); }
$sent = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($sent) || !hash_equals(tg_secret(), $sent)) {
  http_response_code(403);
  exit('{"ok":false}');
}
$u = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
if (is_array($u)) {
  try { tg_handle_update($u); }
  catch (Throwable $e) { error_log('gallery telegram: ' . tg_mask($e->getMessage())); }
}
echo '{"ok":true}';
