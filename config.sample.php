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
$MAX_BYTES = 20 * 1024 * 1024;   // 20 MB

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
  $sent = $_POST['csrf'] ?? '';
  if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
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
