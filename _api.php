<?php
/* =========================================================================
 * Gallery – API JSON (Tranche 5)
 * ---------------------------------------------------------------------------
 * Il token si manda SOLO nell'intestazione X-Api-Token, mai nell'indirizzo:
 * gli indirizzi finiscono nei log di Apache, e con loro il token.
 * Con la regola di gallery.conf installata da apply_root_tasks.sh,
 * /gallery/api/{upload,images,image,telegram}.php non chiedono la Basic
 * Auth: il token (o, per Telegram, il segreto del webhook) e' l'unica chiave.
 *
 *   POST   api/upload.php                     carica (campo img; folder, title, alt, private=1)
 *   GET    api/images.php?q=&folder=&tag=&page=&per=&trash=1&private=0|1
 *   GET    api/image.php?c=CODICE             dettaglio: etichette, uso, link a scadenza
 *   POST   api/image.php?c=CODICE  action=update   title, alt, folder, tags, private
 *                                  action=trash    (anche DELETE)   force=1 se in uso
 *                                  action=restore
 *                                  action=share    seconds (60 s – 90 giorni), note
 *                                  action=unshare  token
 * ========================================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_images.php';
require_once __DIR__ . '/_archive.php';
require_once __DIR__ . '/_stats.php';

function api_out(int $code, array $data): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
  exit;
}

function api_fail(int $code, string $msg, array $extra = []): void {
  api_out($code, ['ok' => false, 'error' => $msg] + $extra);
}

function api_token_ok(): bool {
  global $API_TOKEN;
  return $API_TOKEN !== 'metti-qui-un-token-lungo' && strlen((string) $API_TOKEN) >= 16;
}

function api_auth(): void {
  global $API_TOKEN;
  if (!api_token_ok()) api_fail(503, 'API disabilitata: imposta un API_TOKEN forte in secret.php');
  $sent = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
  if (!is_string($sent) || $sent === '' || !hash_equals((string) $API_TOKEN, $sent)) {
    api_fail(401, "token mancante o errato: va nell'intestazione X-Api-Token");
  }
}

function api_find(string $short): ?array {
  $q = db()->prepare("SELECT * FROM images WHERE short=?");
  $q->execute([$short]);
  $r = $q->fetch();
  if (!$r) return null;
  $r['folder'] = (string) ($r['folder'] ?? '');
  return $r;
}

/* Un'immagine in JSON: gli stessi dati degli snippet piu' stato ed etichette;
 * con $detail anche l'uso (statistiche dai log) e i link a scadenza. */
function api_image(array $r, bool $detail = false): array {
  global $BASE_URL;
  $out = snippet_data($r) + [
    'mime'       => (string) $r['mime'],
    'size'       => (int) $r['size'],
    'created_at' => date('c', (int) $r['created_at']),
    'public'     => empty($r['private']) && $r['deleted_at'] === null,
    'private'    => !empty($r['private']),
    'trashed'    => $r['deleted_at'] !== null,
    'tags'       => $r['tags'] ?? (tags_for([(int) $r['id']])[(int) $r['id']] ?? []),
    'delete'     => $BASE_URL . '/delete.php?c=' . $r['short'] . '&k=' . $r['delkey'],
  ];
  unset($out['pv']);
  if ($detail) {
    $u = usage_by_image(USAGE_DAYS)[$r['short']] ?? null;
    $out['usage'] = ['stats' => stats_state()['ok'], 'days' => USAGE_DAYS, 'views' => $u['n'] ?? 0, 'sources' => (object) ($u['by'] ?? [])];
    $out['shares'] = array_map(fn($s) => [
      'token' => $s['token'], 'url' => $s['url'], 'state' => $s['state'],
      'expires_at' => date('c', (int) $s['expires_at']), 'note' => $s['note'],
    ], shares_list((string) $r['short']));
  }
  return $out;
}

/* Come tratta Apache /gallery/api/? Una richiesta senza credenziali dal
 * server verso se stesso (127.0.0.1, come live_check.sh):
 *   'basic' = chiede ancora la password (manca la regola di gallery.conf),
 *   'token' = risponde l'API (401 in JSON: serve solo il token),
 *   '?'     = non verificabile. */
function api_apache_state(): string {
  global $BASE_URL;
  $p = parse_url($BASE_URL);
  $host = $p['host'] ?? 'localhost';
  $port = $p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
  $ch = curl_init($BASE_URL . '/api/images.php');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 4,
                          CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_RESOLVE => ["$host:$port:127.0.0.1"]]);
  $raw = (string) curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($code === 401 && preg_match('~^www-authenticate:\s*basic~im', $raw)) return 'basic';
  if ($code === 401 && str_contains($raw, '"ok":false')) return 'token';
  return '?';
}
