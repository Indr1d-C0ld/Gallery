<?php
/* Migrazione idempotente. Eseguire da CLI:  php migrate.php
 * - crea/aggiorna schema base
 * - aggiunge la colonna folder se manca
 * - (ri)crea gli indici
 * - installa FTS5 se disponibile
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("solo CLI\n"); }
require_once __DIR__ . "/config.php";

$pdo = db();
echo "DB: $DB_PATH\n";

$pdo->exec(file_get_contents(__DIR__ . "/schema.sql"));
echo "schema base: ok\n";

$cols = array_column($pdo->query("PRAGMA table_info(images)")->fetchAll(), 'name');
if (!in_array('folder', $cols, true)) {
  $pdo->exec("ALTER TABLE images ADD COLUMN folder TEXT DEFAULT ''");
  echo "colonna folder: aggiunta\n";
} else {
  echo "colonna folder: già presente\n";
}

@mkdir($UPLOADS, 0775, true);
@mkdir($THUMBS, 0775, true);

try {
  $pdo->exec(file_get_contents(__DIR__ . "/fts5_setup.sql"));
  echo "FTS5: installato/aggiornato\n";
} catch (Throwable $e) {
  echo "FTS5: non disponibile (" . $e->getMessage() . ") – si userà LIKE\n";
}

echo "fatto.\n";
