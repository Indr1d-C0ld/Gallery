<?php
/* =========================================================================
 * Gallery – bot Telegram (Tranche 5)
 * ---------------------------------------------------------------------------
 * Una foto mandata al bot finisce nell'album $TELEGRAM_ALBUM e torna
 * indietro come link. Il bot e' un webhook (api/telegram.php): Telegram
 * chiama la galleria a ogni messaggio, la richiesta gira come www-data e
 * salva direttamente, con lo stesso percorso degli upload (_ingest.php:
 * posizione GPS tolta, doppioni riconosciuti). Niente processo sempre acceso,
 * niente servizio systemd.
 *
 * Sicurezza:
 *  - Telegram firma ogni chiamata con l'intestazione
 *    X-Telegram-Bot-Api-Secret-Token, fissata quando si collega il webhook:
 *    e' un HMAC del token del bot, quindi non va salvato da nessuna parte;
 *  - solo gli utenti collegati (tabella telegram_users) possono caricare. Ci
 *    si collega mandando al bot "/collega CODICE", con un codice monouso
 *    generato nel pannello (Strumenti), valido 15 minuti.
 * Configurazione in secret.php: TELEGRAM_BOT_TOKEN (da @BotFather) e, se si
 * vuole, TELEGRAM_ALBUM.
 * ========================================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_ingest.php';

const TG_PAIR_TTL = 900;

function tg_enabled(): bool {
  global $TELEGRAM_BOT_TOKEN;
  return (bool) preg_match('~^\d+:[A-Za-z0-9_-]{10,}$~', $TELEGRAM_BOT_TOKEN);
}

function tg_secret(): string {
  global $TELEGRAM_BOT_TOKEN;
  return substr(hash_hmac('sha256', 'gallery-telegram-webhook', $TELEGRAM_BOT_TOKEN), 0, 48);
}

function tg_webhook_url(): string {
  global $BASE_URL;
  return $BASE_URL . '/api/telegram.php';
}

/* Il token del bot sta nell'indirizzo delle chiamate: non deve mai finire
 * in un messaggio d'errore o nel log. */
function tg_mask(string $s): string {
  global $TELEGRAM_BOT_TOKEN;
  return $TELEGRAM_BOT_TOKEN !== '' ? str_replace($TELEGRAM_BOT_TOKEN, '***', $s) : $s;
}

/* Chiamata all'API dei bot. Restituisce sempre ['ok' => bool, ...]. */
function tg_call(string $method, array $params = [], int $timeout = 15): array {
  global $TELEGRAM_API, $TELEGRAM_BOT_TOKEN;
  if (!tg_enabled()) return ['ok' => false, 'description' => 'bot non configurato'];
  $ch = curl_init("$TELEGRAM_API/bot$TELEGRAM_BOT_TOKEN/$method");
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode((object) array_filter($params, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE),
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $res = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($res)) {
    error_log('gallery telegram: ' . $method . ' senza risposta valida — ' . tg_mask($err));
    return ['ok' => false, 'description' => 'nessuna risposta da Telegram' . ($err !== '' ? ': ' . tg_mask($err) : '')];
  }
  return $res;
}

/* Scarica un file del bot (getFile -> file_path) in $dest, al massimo $max byte. */
function tg_download(string $filePath, string $dest, int $max): bool {
  global $TELEGRAM_API, $TELEGRAM_BOT_TOKEN;
  if (!preg_match('~^[A-Za-z0-9_./-]+$~', $filePath) || str_contains($filePath, '..')) return false;
  $fh = fopen($dest, 'wb');
  if (!$fh) return false;
  $ch = curl_init("$TELEGRAM_API/file/bot$TELEGRAM_BOT_TOKEN/$filePath");
  curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5,
                          CURLOPT_FAILONERROR => true, CURLOPT_MAXFILESIZE_LARGE => $max]);
  $ok = curl_exec($ch) !== false;
  if (!$ok) error_log('gallery telegram: download non riuscito — ' . tg_mask(curl_error($ch)));
  curl_close($ch);
  fclose($fh);
  return $ok && filesize($dest) > 0 && filesize($dest) <= $max;
}

/* ---- piccoli valori dell'app (tabella settings) ------------------------- */
function setting_get(string $k): ?string {
  $q = db()->prepare("SELECT v FROM settings WHERE k=?");
  $q->execute([$k]);
  $v = $q->fetchColumn();
  return $v === false ? null : (string) $v;
}
function setting_set(string $k, ?string $v): void {
  if ($v === null) { db()->prepare("DELETE FROM settings WHERE k=?")->execute([$k]); return; }
  db()->prepare("INSERT INTO settings(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]);
}

/* ---- utenti collegati ---------------------------------------------------- */
function tg_users(): array {
  return db()->query("SELECT tg_id, name, added_at FROM telegram_users ORDER BY added_at")->fetchAll();
}
function tg_allowed(int $id): bool {
  $q = db()->prepare("SELECT 1 FROM telegram_users WHERE tg_id=?");
  $q->execute([$id]);
  return (bool) $q->fetchColumn();
}
function tg_user_remove(int $id): bool {
  $q = db()->prepare("DELETE FROM telegram_users WHERE tg_id=?");
  $q->execute([$id]);
  return $q->rowCount() > 0;
}

/* Nuovo codice di collegamento (8 caratteri, monouso, TG_PAIR_TTL secondi).
 * Nel DB solo la sua impronta. */
function tg_pair_new(): string {
  $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';      // niente 0/O, 1/I
  $code = '';
  for ($i = 0; $i < 8; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
  setting_set('tg_pair', hash('sha256', $code) . ':' . (time() + TG_PAIR_TTL));
  return $code;
}
function tg_pair_try(string $code, int $id, string $name): bool {
  $v = setting_get('tg_pair');
  if ($v === null || !str_contains($v, ':')) return false;
  [$h, $exp] = explode(':', $v, 2);
  if ((int) $exp < time() || !hash_equals($h, hash('sha256', strtoupper(trim($code))))) return false;
  setting_set('tg_pair', null);                       // monouso
  db()->prepare("INSERT INTO telegram_users(tg_id, name, added_at) VALUES(?,?,?)
                 ON CONFLICT(tg_id) DO UPDATE SET name=excluded.name")->execute([$id, mb_substr($name, 0, 80), time()]);
  return true;
}

/* ---- un aggiornamento da Telegram --------------------------------------- */
function tg_handle_update(array $u): void {
  global $TELEGRAM_ALBUM, $MAX_BYTES, $BASE_URL;
  $m = $u['message'] ?? null;
  if (!is_array($m) || !isset($m['chat']['id'], $m['from']['id'])) return;
  $chat = (int) $m['chat']['id'];
  $from = (int) $m['from']['id'];
  $name = trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? '')) ?: (string) ($m['from']['username'] ?? '');
  $reply = function (string $text) use ($chat, $m): void {
    tg_call('sendMessage', ['chat_id' => $chat, 'text' => $text, 'reply_to_message_id' => $m['message_id'] ?? null]);
  };
  $text = trim((string) ($m['text'] ?? ''));

  if (preg_match('~^/collega(?:@\w+)?\s+(\S+)~i', $text, $mm)) {
    $reply(tg_pair_try($mm[1], $from, $name)
      ? "Collegato. Le foto che mi mandi finiscono nell'album «{$TELEGRAM_ALBUM}» della Gallery e ti rispondo con il link."
      : "Codice non valido o scaduto: generane uno nuovo nel pannello (Strumenti → Telegram).");
    return;
  }
  if (!tg_allowed($from)) {
    $reply("Questo bot carica le foto nella Gallery di chi l'ha configurato. Se sei tu: nel pannello, Strumenti → Telegram, genera un codice e mandami /collega CODICE.");
    return;
  }

  // la foto: compressa da Telegram (senza metadati); come documento: l'originale
  $file = null;
  if (!empty($m['photo']) && is_array($m['photo'])) {
    $p = end($m['photo']);
    $file = ['id' => (string) ($p['file_id'] ?? ''), 'size' => (int) ($p['file_size'] ?? 0)];
  } elseif (!empty($m['document']) && is_array($m['document'])) {
    if (!str_starts_with((string) ($m['document']['mime_type'] ?? ''), 'image/')) {
      $reply("Mandami un'immagine (JPEG, PNG, GIF o WebP), come foto o come file.");
      return;
    }
    $file = ['id' => (string) ($m['document']['file_id'] ?? ''), 'size' => (int) ($m['document']['file_size'] ?? 0)];
  }
  if (!$file || $file['id'] === '') {
    if ($text !== '') $reply("Mandami una foto (o un'immagine come file): la carico nell'album «{$TELEGRAM_ALBUM}» e ti rispondo con il link. La didascalia diventa il titolo.");
    return;
  }
  if ($file['size'] > $MAX_BYTES) { $reply("Troppo grande: al massimo " . round($MAX_BYTES / 1048576) . " MB."); return; }

  $gf = tg_call('getFile', ['file_id' => $file['id']]);
  $path = (string) ($gf['result']['file_path'] ?? '');
  if (empty($gf['ok']) || $path === '') { $reply("Non riesco a scaricare l'immagine da Telegram, riprova."); return; }
  $tmp = tempnam(sys_get_temp_dir(), 'gallery-tg-');
  try {
    if (!tg_download($path, $tmp, $MAX_BYTES)) { $reply("Non riesco a scaricare l'immagine da Telegram, riprova."); return; }
    $res = ingest_image($tmp, ['folder' => $TELEGRAM_ALBUM, 'title' => mb_substr(trim((string) ($m['caption'] ?? '')), 0, 200)]);
  } finally {
    if (is_file($tmp)) @unlink($tmp);
  }
  if (!$res['ok']) { $reply("Non caricata: " . $res['error']); return; }
  $row = $res['row'];
  $notes = [];
  if ($res['duplicate']) $notes[] = $res['restored'] ? 'era nel cestino: ripristinata, stesso link di prima' : 'era già in archivio: ecco il link esistente';
  if (!empty($row['private'])) $notes[] = 'è privata: il link pubblico non funziona, crea un link a scadenza dal pannello';
  if ($res['location_removed']) $notes[] = 'posizione GPS tolta';
  $reply($BASE_URL . '/i/' . $row['short'] . ($notes ? "\n" . implode("\n", $notes) : ''));
}
