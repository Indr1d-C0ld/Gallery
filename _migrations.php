<?php
/* =========================================================================
 * Gallery – migrazioni dello schema, applicate dall'app
 * ---------------------------------------------------------------------------
 * Dal 2026-09 (audit #6) il database in /var/lib/gallery e' scrivibile solo
 * da www-data: `php migrate.php` lanciato dal proprio utente non puo' piu'
 * farlo.
 * Le migrazioni quindi le applica l'app stessa, alla prima richiesta dopo un
 * aggiornamento del codice, come www-data, dentro db() in config.php.
 *
 * Funzionamento:
 *  - tabella schema_version: una riga per ogni migrazione applicata;
 *  - controllo rapido a ogni connessione (una SELECT su una tabella di poche
 *    righe); se manca qualcosa si prende il lock di scrittura di SQLite
 *    (BEGIN IMMEDIATE), si rilegge la versione SOTTO il lock — una richiesta
 *    concorrente potrebbe averla appena applicata — e si applicano le
 *    migrazioni mancanti in un'unica transazione: o tutte o nessuna.
 *    In SQLite anche CREATE/ALTER sono transazionali.
 *  - una migrazione fallita annulla tutto, finisce nel log di Apache e fa
 *    fallire la richiesta; la successiva riprova.
 *
 * Per cambiare lo schema: aggiungere in fondo a gallery_migrations() una
 * nuova voce con il numero successivo. Non modificare MAI una migrazione gia'
 * andata in produzione, e provarla sul banco (bench/run.sh) prima del live.
 * Le migrazioni devono essere additive: cosi' tornare alla versione
 * precedente del codice resta sempre possibile.
 * ========================================================================= */

function gallery_migrations(): array {
  return [

    /* v1 — lo schema com'era prima delle migrazioni automatiche.
     * Su un DB esistente non cambia nulla (tutto IF NOT EXISTS) e registra
     * solo la versione; su un DB vuoto crea tutto da zero. */
    1 => ['baseline: images, indici, colonna folder, ricerca FTS5', function (PDO $pdo): void {
      // DB precedenti al 2026-09-10 non avevano la colonna folder: va aggiunta
      // PRIMA di schema.sql, che crea un indice su quella colonna.
      $cols = array_column($pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC), 'name');
      if ($cols && !in_array('folder', $cols, true)) {
        $pdo->exec("ALTER TABLE images ADD COLUMN folder TEXT DEFAULT ''");
      }
      $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

      // FTS5 e' facoltativa: senza, index.php e admin ricadono su LIKE.
      $had_fts = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE name='images_fts'")->fetchColumn();
      try {
        $pdo->exec(file_get_contents(__DIR__ . '/fts5_setup.sql'));
        // Indice appena creato su righe gia' esistenti: va popolato.
        // (Il vecchio "backfill" di fts5_setup.sql non poteva funzionare:
        // con content='images' anche SELECT rowid FROM images_fts legge
        // dalla tabella images, quindi non mancava mai nessuna riga.)
        if (!$had_fts) $pdo->exec("INSERT INTO images_fts(images_fts) VALUES('rebuild')");
      } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'fts5') === false) throw $e;
        error_log('gallery: FTS5 non disponibile, la ricerca usera\' LIKE');
      }
    }],

    /* v2 — al 2026-10-08 l'indice di ricerca del live copriva 83 immagini su
     * 125: le 42 piu' vecchie (id 1-42, fino al 28/12/2025), caricate prima
     * che l'indice esistesse, non vi erano mai entrate per via del backfill
     * che non inseriva nulla (vedi v1), e la ricerca non le trovava.
     * Ricostruzione completa dalla tabella images: idempotente, pochi ms. */
    2 => ['ricostruzione dell\'indice di ricerca FTS', function (PDO $pdo): void {
      if ($pdo->query("SELECT 1 FROM sqlite_master WHERE name='images_fts'")->fetchColumn()) {
        $pdo->exec("INSERT INTO images_fts(images_fts) VALUES('rebuild')");
      }
    }],

    /* v3 — impronta SHA-256 di ogni originale, per riconoscere i doppioni al
     * caricamento. Le righe esistenti si completano qui, leggendo i file:
     * al 2026-10 sono ~40 MB, una frazione di secondo. Le righe nate da
     * "Copia" condividono il file e quindi anche l'impronta. */
    3 => ['impronta sha256 degli originali (riconoscimento dei doppioni)', function (PDO $pdo): void {
      global $UPLOADS;
      $cols = array_column($pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC), 'name');
      if (!in_array('sha256', $cols, true)) $pdo->exec("ALTER TABLE images ADD COLUMN sha256 TEXT");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_sha256 ON images(sha256)");
      $set = $pdo->prepare("UPDATE images SET sha256=? WHERE id=?");
      foreach ($pdo->query("SELECT id, filename FROM images WHERE sha256 IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = rtrim((string) $UPLOADS, '/') . '/' . $r['filename'];
        if (is_file($f)) $set->execute([hash_file('sha256', $f), $r['id']]);
      }
    }],

  ];
}

function schema_latest(): int {
  return max(array_keys(gallery_migrations()));
}

/* Versione attuale del DB; 0 se non e' mai stato migrato. */
function schema_version(PDO $pdo): int {
  try {
    return (int) $pdo->query("SELECT COALESCE(MAX(version),0) FROM schema_version")->fetchColumn();
  } catch (PDOException $e) {
    return 0;   // tabella assente
  }
}

/* Applica le migrazioni mancanti; restituisce le versioni applicate. */
function migrate_db(PDO $pdo): array {
  if (schema_version($pdo) >= schema_latest()) return [];

  $pdo->exec('BEGIN IMMEDIATE');
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
      version    INTEGER PRIMARY KEY,
      name       TEXT    NOT NULL,
      applied_at INTEGER NOT NULL
    )");
    $cur  = schema_version($pdo);
    $done = [];
    $all  = gallery_migrations();
    ksort($all);
    foreach ($all as $v => [$name, $fn]) {
      if ($v <= $cur) continue;
      $fn($pdo);
      $pdo->prepare("INSERT INTO schema_version(version, name, applied_at) VALUES(?,?,?)")
          ->execute([$v, $name, time()]);
      $done[] = $v;
    }
    $pdo->exec('COMMIT');
  } catch (Throwable $e) {
    try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    error_log('gallery: migrazione dello schema FALLITA, nulla applicato — ' . $e->getMessage());
    throw $e;
  }

  if ($done) error_log('gallery: schema portato alla versione ' . max($done) . ' (applicate: ' . implode(', ', $done) . ')');
  return $done;
}
