<?php
/* Migrazioni dello schema da riga di comando.
 *
 * Di norma NON serve: l'app applica da sola le migrazioni mancanti alla prima
 * richiesta dopo un aggiornamento, come www-data (vedi _migrations.php).
 * Resta utile per un DB nuovo, per il banco di prova e per controllare a che
 * versione e' un database:
 *
 *   php migrate.php            applica le migrazioni mancanti, mostra lo stato
 *   php migrate.php --status   sola lettura, anche da un utente che non puo'
 *                              scrivere il DB: mostra lo stato senza toccarlo
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("solo CLI\n"); }
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_migrations.php";

echo "DB: $DB_PATH\n";

if (in_array('--status', $argv, true)) {
  // immutable=1: nessun lock, nessun file -wal/-shm, nessuna scrittura
  $pdo = new PDO('sqlite:file:' . $DB_PATH . '?immutable=1', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} else {
  $pdo = db();   // la connessione applica gia' le migrazioni mancanti
  @mkdir($UPLOADS, 0775, true);
  @mkdir($THUMBS, 0775, true);
}

$cur = schema_version($pdo);
echo "versione schema: $cur (codice: " . schema_latest() . ")\n";
if ($cur > 0) {
  foreach ($pdo->query("SELECT version, name, applied_at FROM schema_version ORDER BY version") as $r) {
    printf("  v%d  %s  %s\n", $r['version'], date('Y-m-d H:i', (int)$r['applied_at']), $r['name']);
  }
}
$pending = array_filter(array_keys(gallery_migrations()), fn($v) => $v > $cur);
echo $pending ? "da applicare: v" . implode(', v', $pending) . "\n" : "nessuna migrazione in sospeso\n";
echo "FTS5: " . ($pdo->query("SELECT 1 FROM sqlite_master WHERE name='images_fts'")->fetchColumn() ? "presente" : "assente (ricerca con LIKE)") . "\n";
