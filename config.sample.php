<?php
/* Evita doppia inclusione (es. api/upload.php -> upload.php) */
if (defined('GALLERY_CONFIG_LOADED')) return;
define('GALLERY_CONFIG_LOADED', 1);

/* =========================================================================
 * Gallery – configurazione globale
 * ---------------------------------------------------------------------------
 * Modifiche 2026-09-10:
 *  - host validato contro allowlist (niente più cache/link poisoning via Host)
 *  - API token letto da secret.php / env, con rifiuto se placeholder
 *  - PDO hardening (WAL, busy_timeout, foreign_keys, fetch assoc)
 *  - helper: require_login(), csrf_token(), csrf_field(), csrf_check()
 * ========================================================================= */

/* --- Segreti fuori dal file versionato (opzionale) -------------------------
 * Crea gallery/secret.php con:
 *   <?php return ['API_TOKEN' => 'xxxxx', 'ALLOWED_HOSTS' => ['tuo.dominio']];
 * secret.php è già negato da .htaccess.
 */
$__secret = [];
if (is_file(__DIR__ . '/secret.php')) {
  $__secret = (array) (include __DIR__ . '/secret.php');
}

/* --- Host / Base URL ------------------------------------------------------- */
$ALLOWED_HOSTS = $__secret['ALLOWED_HOSTS'] ?? ['tuo-dominio.tld'];

$reqHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$reqHost = strtolower(preg_replace('~[^a-z0-9.\-:]~i', '', $reqHost));
$host    = in_array($reqHost, $ALLOWED_HOSTS, true) ? $reqHost : ($ALLOWED_HOSTS[0] ?? 'localhost');

$scheme = 'https';
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
  $scheme = 'http';
}
// dietro un eventuale reverse proxy TLS
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
  $scheme = 'https';
}

$BASE_URL = $scheme . '://' . $host . '/gallery';

/* --- Percorsi ------------------------------------------------------------- */
$DB_PATH = $__secret['DB_PATH'] ?? (getenv('GALLERY_DB') ?: __DIR__ . '/gallery.db');
$UPLOADS = __DIR__ . '/uploads';
$THUMBS  = __DIR__ . '/thumbs';

/* --- Upload ------------------------------------------------------------- */
$USE_THUMBS  = true;
$THUMB_MAX_W = 320;

$ALLOWED = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  'image/webp' => 'webp',
];
$MAX_BYTES  = 20 * 1024 * 1024;  // 20 MB sul file compresso
$MAX_PIXELS = 40 * 1000 * 1000;  // 40 megapixel: tetto sull'immagine DECOMPRESSA

/* Tetto di memoria per le sole richieste di Gallery (php.ini qui e' illimitato).
 *
 * ATTENZIONE, misurato il 2026-09-14: questo NON protegge da immagini-bomba.
 * libgd alloca fuori dalla contabilita' di PHP, quindi memory_limit non la
 * vincola: un PNG di 107 KB che dichiara 30000x30000 ha portato il processo
 * a 1754 MB di RSS pur avendo memory_limit=256M. L'unica difesa reale e' il
 * controllo sui pixel PRIMA della decodifica ($MAX_PIXELS, upload.php:3b).
 * Questo tetto resta utile per le allocazioni lato PHP (lettura file, stringhe,
 * risultati di query) e come rete in caso di codice che sfugga di mano.
 */
if ((int)ini_get('memory_limit') === -1 || (int)ini_get('memory_limit') > 512) {
  @ini_set('memory_limit', '512M');
}

/* --- API token --------------------------------------------------------- */
$API_TOKEN = $__secret['API_TOKEN']
  ?? getenv('GALLERY_API_TOKEN')
  ?: 'metti-qui-un-token-lungo';

/* --- Difesa in profondità: richiede che Apache abbia già autenticato ---- */
$ENFORCE_PHP_AUTH = true;   // metti false solo se PHP_AUTH_USER non arriva

function current_user(): ?string {
  foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $k) {
    if (!empty($_SERVER[$k])) return (string) $_SERVER[$k];
  }
  return null;
}

function require_login(): void {
  global $ENFORCE_PHP_AUTH;
  if (!$ENFORCE_PHP_AUTH) return;
  if (current_user() === null) {
    header('WWW-Authenticate: Basic realm="Gallery - Accesso riservato"');
    http_response_code(401);
    exit('auth required');
  }
}

/* --- CSRF ------------------------------------------------------------- */
function csrf_token(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/gallery',
      'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
      'httponly' => true,
      'samesite' => 'Strict',
    ]);
    session_start();
  }
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_check(): void {
  $sent = post_str('csrf');
  if (!hash_equals(csrf_token(), $sent)) {
    http_response_code(419);
    exit('CSRF token non valido: ricarica la pagina.');
  }
}

/* --- DB ------------------------------------------------------------- */
function db(): PDO {
  global $DB_PATH;
  static $pdo = null;
  if (!$pdo) {
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
  }
  return $pdo;
}

function shortcode(int $len = 7): string {
  return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '=');
}

/* --- Lettura parametri in ingresso ----------------------------------------
 * PHP trasforma ?q[]=a in un array: passandolo a trim()/preg_replace() si
 * ottiene un TypeError non gestito (500) o un warning silenzioso. Questi
 * aiutanti garantiscono sempre il tipo atteso, qualunque cosa arrivi.
 */
function in_str(array $src, string $key, string $default = ''): string {
  $v = $src[$key] ?? null;
  if (is_string($v))               return $v;
  if (is_int($v) || is_float($v))  return (string) $v;
  return $default;                 // array, null, bool, oggetto -> default
}

function get_str(string $key, string $default = ''): string {
  return in_str($_GET, $key, $default);
}

function post_str(string $key, string $default = ''): string {
  return in_str($_POST, $key, $default);
}

function get_int(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int {
  $n = (int) in_str($_GET, $key, (string) $default);
  return max($min, min($max, $n));
}

/* Limita la pagina richiesta a quelle realmente esistenti e restituisce
 * l'OFFSET da usare. Senza questo, ?p=99999999 produce un OFFSET enorme e
 * SQLite scorre l'intera tabella per restituire zero righe.
 * $page viene corretto per riferimento, cosi' la paginazione mostra il numero
 * giusto invece di una pagina fantasma.
 */
function page_offset(int &$page, int $total, int $per): int {
  $pages = max(1, (int) ceil($total / $per));
  $page  = max(1, min($page, $pages));
  return ($page - 1) * $per;
}

/* --- Cancellazione sicura -------------------------------------------------
 * "Copia" duplica la riga ma NON il file: piu' short-code possono puntare
 * allo stesso filename. Rimuove i file da disco solo quando l'ultima riga
 * che li referenzia e' stata cancellata, altrimenti eliminando una copia si
 * distruggerebbe anche l'originale.
 */
function delete_image(string $short): bool {
  global $UPLOADS, $THUMBS;

  $st = db()->prepare("SELECT filename FROM images WHERE short=?");
  $st->execute([$short]);
  $r = $st->fetch();
  if (!$r) return false;

  db()->prepare("DELETE FROM images WHERE short=?")->execute([$short]);

  $c = db()->prepare("SELECT COUNT(*) FROM images WHERE filename=?");
  $c->execute([$r['filename']]);
  if ((int)$c->fetchColumn() === 0) {
    @unlink(rtrim($UPLOADS, '/') . '/' . $r['filename']);
    @unlink(rtrim($THUMBS,  '/') . '/' . $r['filename']);
  }
  return true;
}
