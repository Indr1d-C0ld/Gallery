<?php
/* Router per `php -S` sul banco di prova: riproduce le parti di Apache da cui
 * dipende l'applicazione (gallery.conf + .htaccess):
 *   - Basic Auth su tutto tranne la consegna immagini (i.php, /i/X, /t/X);
 *   - riscrittura /gallery/i/X -> i.php?c=X  e  /gallery/t/X -> i.php?c=X&thumb=1;
 *   - nessun accesso a bench/, file nascosti, segreti, DB e sorgenti non-PHP.
 * NON verifica la configurazione reale di Apache: quella la controlla
 * bench/live_check.sh sul server vero.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

/* Decide cosa fare della richiesta: un intero = risposta di rifiuto,
 * una stringa = script PHP da eseguire, false = file statico. */
function bench_route() {
  $cfg  = json_decode((string) file_get_contents(__DIR__ . '/bench.json'), true);
  $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
  $root = $_SERVER['DOCUMENT_ROOT'];

  // Solo per le prove: i percorsi che il server sta usando DAVVERO adesso
  if ($path === '/__bench/config') return '__config__';
  if (!str_starts_with($path, '/gallery/')) return 404;

  // Auth: come il RequireAny di gallery.conf
  $public = (bool) preg_match('#^/gallery/(i\.php$|i/[A-Za-z0-9_-]+$|t/[A-Za-z0-9_-]+$)#', $path);
  $authed = ($_SERVER['PHP_AUTH_USER'] ?? null) === $cfg['user']
         && hash_equals($cfg['pass'], (string) ($_SERVER['PHP_AUTH_PW'] ?? ''));
  // run.sh --serve: banco da provare a mano nel browser, gia' "autenticato"
  if (!empty($cfg['autologin'])) { $authed = true; $_SERVER['PHP_AUTH_USER'] = $cfg['user']; }
  if (!$authed) unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
  if (!$public && !$authed) {
    header('WWW-Authenticate: Basic realm="Gallery - Accesso riservato"');
    return 401;
  }
  if ($authed) $_SERVER['REMOTE_USER'] = $cfg['user'];

  // File e cartelle che Apache nega
  if (preg_match('#^/gallery/(bench(/|$)|_orig_backup_|(.*/)?\.)#', $path)) return 404;
  if (preg_match('#\.(db|db-wal|db-shm|sqlite|sql|md|log|bak|sh)$#', $path)) return 403;
  if (preg_match('#/(secret|_theme|_images|_migrations|config)\.php$#', $path)) return 403;
  if (preg_match('#^/gallery/(uploads|thumbs)/.*\.(php|phtml|phar)$#', $path)) return 403;

  // Riscritture
  if (preg_match('#^/gallery/([it])/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $_GET['c'] = $m[2];
    if ($m[1] === 't') $_GET['thumb'] = '1';
    return $root . '/gallery/i.php';
  }

  // Cartelle: index.php se c'e', altrimenti niente elenco (Options -Indexes)
  $file = $root . $path;
  if (is_dir($file)) {
    if (!str_ends_with($path, '/')) { header('Location: ' . $path . '/', true, 301); return 301; }
    $file .= 'index.php';
    if (!is_file($file)) return 403;
  }
  if (!is_file($file)) return 404;
  return str_ends_with($file, '.php') ? $file : false;
}

$__bench_target = bench_route();
if (is_int($__bench_target)) { http_response_code($__bench_target); return true; }
if ($__bench_target === '__config__') {
  require $_SERVER['DOCUMENT_ROOT'] . '/gallery/config.php';
  header('Content-Type: application/json');
  echo json_encode(['db' => $DB_PATH, 'uploads' => $UPLOADS, 'thumbs' => $THUMBS]);
  return true;
}
if ($__bench_target === false) return false;   // file statico: lo serve php -S

// Script PHP: eseguito nello scope globale, come farebbe Apache
$_SERVER['SCRIPT_FILENAME'] = $__bench_target;
chdir(dirname($__bench_target));
unset($__bench_target);
require $_SERVER['SCRIPT_FILENAME'];
return true;
