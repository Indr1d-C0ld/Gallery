<?php
/* =========================================================================
 * Gallery – archivio: album, etichette, cestino, ricerca
 * ---------------------------------------------------------------------------
 * Un solo posto per le operazioni sull'archivio, usate da galleria, pannello
 * admin, upload.php e delete.php. Prima la ricerca esisteva in due copie
 * (index.php e admin/index.php); con il cestino e le etichette ogni filtro
 * andava scritto due volte.
 *
 * Modello (migrazione v4):
 *  - l'album di un'immagine e' images.folder ('' = senza album); la tabella
 *    albums aggiunge descrizione, copertina e ordine, e nasce alla prima
 *    modifica;
 *  - le etichette (tags/image_tags) sono molte per immagine e prendono il
 *    posto di "Copia", che duplicava la riga;
 *  - images.deleted_at e' il cestino: dopo TRASH_DAYS giorni l'eliminazione
 *    diventa definitiva (alla prima apertura del pannello admin).
 * ========================================================================= */
require_once __DIR__ . '/config.php';

const TRASH_DAYS = 30;
const TAG_MAX_LEN = 40;

function fts5_available(): bool {
  static $ok = null;
  if ($ok === null) {
    try { $ok = (bool) db()->query("SELECT 1 FROM sqlite_master WHERE name='images_fts'")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

/* Nome di album (qui e non in config.php: fino alla Tranche 3 upload.php e il
 * pannello ne avevano una copia ciascuno, e durante il rilascio una vecchia
 * copia ancora in cache non deve scontrarsi con questa).
 * Nome di album: lettere, cifre, spazio, trattino, trattino basso; 64 al massimo. */
function norm_folder($s): string {
  $s = trim((string) ($s ?? ''));
  $s = preg_replace('~[^a-zA-Z0-9 _-]~', '', $s);
  return substr($s, 0, 64);
}

function in_list(array $values): string {
  return implode(',', array_fill(0, count($values), '?'));
}

/* ---------------------------------------------------------------------------
 * Etichette
 * ------------------------------------------------------------------------- */

/* Lettere (anche accentate), cifre, spazio, trattino, trattino basso. */
function norm_tag(string $s): string {
  $s = preg_replace('~[^\p{L}\p{N} _-]~u', '', $s) ?? '';
  $s = trim(preg_replace('~\s+~u', ' ', $s) ?? '');
  return mb_substr($s, 0, TAG_MAX_LEN);
}

/* "uno, Due ,uno" -> ["uno", "Due"]: normalizzate, senza doppioni
 * (maiuscole e minuscole contano come uguali), al massimo 20. */
function parse_tags(string $csv): array {
  $out = [];
  foreach (explode(',', $csv) as $t) {
    $t = norm_tag($t);
    if ($t !== '' && !isset($out[mb_strtolower($t)])) $out[mb_strtolower($t)] = $t;
  }
  return array_slice(array_values($out), 0, 20);
}

function tag_id(string $name, bool $create = true): ?int {
  $q = db()->prepare("SELECT id FROM tags WHERE name = ? COLLATE NOCASE");
  $q->execute([$name]);
  $id = $q->fetchColumn();
  if ($id !== false) return (int) $id;
  if (!$create) return null;
  db()->prepare("INSERT INTO tags(name) VALUES(?)")->execute([$name]);
  return (int) db()->lastInsertId();
}

/* Etichette di piu' immagini: [id_immagine => [nome, ...]]. */
function tags_for(array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];
  $q = db()->prepare("SELECT it.image_id, t.name FROM image_tags it JOIN tags t ON t.id = it.tag_id
                      WHERE it.image_id IN (" . in_list($ids) . ") ORDER BY t.name COLLATE NOCASE");
  $q->execute($ids);
  $out = [];
  foreach ($q->fetchAll() as $r) $out[(int) $r['image_id']][] = $r['name'];
  return $out;
}

/* Etichette in uso su immagini visibili, con il conteggio. */
function tag_list(): array {
  return db()->query("SELECT t.name, COUNT(*) AS n FROM tags t
    JOIN image_tags it ON it.tag_id = t.id
    JOIN images i ON i.id = it.image_id AND i.deleted_at IS NULL
    GROUP BY t.id ORDER BY t.name COLLATE NOCASE")->fetchAll();
}

function tags_purge_orphans(): void {
  db()->exec("DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM image_tags)");
}

function image_set_tags(int $imageId, array $names): void {
  db()->prepare("DELETE FROM image_tags WHERE image_id=?")->execute([$imageId]);
  $link = db()->prepare("INSERT OR IGNORE INTO image_tags(image_id, tag_id) VALUES(?,?)");
  foreach ($names as $n) $link->execute([$imageId, tag_id($n)]);
  tags_purge_orphans();
}

/* Aggiunge o toglie un'etichetta a piu' immagini (per codice). */
function images_tag(array $shorts, string $name, bool $add): int {
  $name = norm_tag($name);
  $shorts = array_values(array_unique($shorts));
  if ($name === '' || !$shorts) return 0;
  $tid = tag_id($name, $add);
  if ($tid === null) return 0;
  $ids = db()->prepare("SELECT id FROM images WHERE short IN (" . in_list($shorts) . ")");
  $ids->execute($shorts);
  $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
  if (!$ids) return 0;
  if ($add) {
    $link = db()->prepare("INSERT OR IGNORE INTO image_tags(image_id, tag_id) VALUES(?,?)");
    $n = 0;
    foreach ($ids as $id) { $link->execute([$id, $tid]); $n += $link->rowCount(); }
  } else {
    $del = db()->prepare("DELETE FROM image_tags WHERE tag_id=? AND image_id IN (" . in_list($ids) . ")");
    $del->execute(array_merge([$tid], $ids));
    $n = $del->rowCount();
    tags_purge_orphans();
  }
  return $n;
}

/* Rinomina un'etichetta; se il nuovo nome esiste gia' le unisce; un nome
 * vuoto la elimina (le immagini restano). */
function tag_rename(string $from, string $to): string {
  $to = norm_tag($to);
  $src = tag_id($from, false);
  if ($src === null) return 'none';
  if ($to === '') { db()->prepare("DELETE FROM tags WHERE id=?")->execute([$src]); return 'deleted'; }
  $dst = tag_id($to, false);
  if ($dst === null || $dst === $src) {
    db()->prepare("UPDATE tags SET name=? WHERE id=?")->execute([$to, $src]);
    return 'renamed';
  }
  db()->prepare("INSERT OR IGNORE INTO image_tags(image_id, tag_id) SELECT image_id, ? FROM image_tags WHERE tag_id=?")->execute([$dst, $src]);
  db()->prepare("DELETE FROM tags WHERE id=?")->execute([$src]);
  return 'merged';
}

/* ---------------------------------------------------------------------------
 * Album
 * ------------------------------------------------------------------------- */

/* Album con immagini visibili, nell'ordine scelto: prima "senza album",
 * poi quelli con una posizione, poi gli altri in ordine alfabetico. */
function album_list(): array {
  return db()->query("
    SELECT f.name, f.n, COALESCE(a.description,'') AS description, a.cover, a.position
    FROM (SELECT COALESCE(folder,'') AS name, COUNT(*) AS n FROM images
          WHERE deleted_at IS NULL GROUP BY COALESCE(folder,'')) f
    LEFT JOIN albums a ON a.name = f.name
    ORDER BY (f.name = '') DESC, (a.position IS NULL), a.position, f.name COLLATE NOCASE
  ")->fetchAll();
}

function album_meta(string $name): ?array {
  $q = db()->prepare("SELECT * FROM albums WHERE name=?");
  $q->execute([$name]);
  return $q->fetch() ?: null;
}

/* Immagine di copertina: quella scelta, se e' ancora nell'album e visibile,
 * altrimenti la piu' recente. */
function album_cover(string $name, ?string $cover): ?array {
  if ($cover) {
    $q = db()->prepare("SELECT * FROM images WHERE short=? AND COALESCE(folder,'')=? AND deleted_at IS NULL");
    $q->execute([$cover, $name]);
    if ($r = $q->fetch()) return $r;
  }
  $q = db()->prepare("SELECT * FROM images WHERE COALESCE(folder,'')=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
  $q->execute([$name]);
  return $q->fetch() ?: null;
}

function album_save_meta(string $name, string $description, string $cover, string $position): void {
  if ($name === '') return;                       // "senza album" non e' un album
  $description = mb_substr(trim($description), 0, 500);
  $q = db()->prepare("SELECT 1 FROM images WHERE short=? AND COALESCE(folder,'')=? AND deleted_at IS NULL");
  $q->execute([$cover, $name]);
  $cover = $q->fetchColumn() ? $cover : null;
  $pos = preg_match('~^-?\d{1,6}$~', trim($position)) ? (int) $position : null;
  db()->prepare("INSERT INTO albums(name, description, cover, position, created_at) VALUES(?,?,?,?,?)
                 ON CONFLICT(name) DO UPDATE SET description=excluded.description, cover=excluded.cover, position=excluded.position")
      ->execute([$name, $description, $cover, $pos, time()]);
}

/* Rinomina un album in un'unica operazione; se il nuovo nome esiste gia'
 * gli album si uniscono (descrizione, copertina e ordine del primo restano
 * se l'altro non li ha). Nome vuoto: le immagini restano "senza album".
 * Anche le immagini nel cestino seguono l'album. */
function album_rename(string $from, string $to): string {
  $to = norm_folder($to);
  if ($to === $from) return 'noop';
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT 1 FROM images WHERE COALESCE(folder,'')=? LIMIT 1");
    $q->execute([$to]);
    $merge = $to !== '' && (bool) $q->fetchColumn();
    $pdo->prepare("UPDATE images SET folder=? WHERE COALESCE(folder,'')=?")->execute([$to, $from]);
    $src = album_meta($from);
    $dst = $to === '' ? null : album_meta($to);
    if ($src && $to === '') {
      $pdo->prepare("DELETE FROM albums WHERE name=?")->execute([$from]);
    } elseif ($src && !$dst) {
      $pdo->prepare("UPDATE albums SET name=? WHERE name=?")->execute([$to, $from]);
    } elseif ($src && $dst) {
      $pdo->prepare("UPDATE albums SET description = CASE WHEN description='' THEN ? ELSE description END,
                       cover = COALESCE(cover, ?), position = COALESCE(position, ?) WHERE name=?")
          ->execute([$src['description'], $src['cover'], $src['position'], $to]);
      $pdo->prepare("DELETE FROM albums WHERE name=?")->execute([$from]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return $merge ? 'merged' : 'renamed';
}

/* ---------------------------------------------------------------------------
 * Cestino
 * ------------------------------------------------------------------------- */

function trash_images(array $shorts): int {
  $shorts = array_values(array_unique($shorts));
  if (!$shorts) return 0;
  $q = db()->prepare("UPDATE images SET deleted_at=? WHERE deleted_at IS NULL AND short IN (" . in_list($shorts) . ")");
  $q->execute(array_merge([time()], $shorts));
  return $q->rowCount();
}

function restore_images(array $shorts): int {
  $shorts = array_values(array_unique($shorts));
  if (!$shorts) return 0;
  $q = db()->prepare("UPDATE images SET deleted_at=NULL WHERE deleted_at IS NOT NULL AND short IN (" . in_list($shorts) . ")");
  $q->execute($shorts);
  return $q->rowCount();
}

/* Eliminazione definitiva di immagini GIA' nel cestino (mai di quelle
 * visibili: si passa sempre dal cestino). */
function purge_images(array $shorts): int {
  $shorts = array_values(array_unique($shorts));
  if (!$shorts) return 0;
  $q = db()->prepare("SELECT short FROM images WHERE deleted_at IS NOT NULL AND short IN (" . in_list($shorts) . ")");
  $q->execute($shorts);
  $n = 0;
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) $n += (int) delete_image($s);
  if ($n) tags_purge_orphans();
  return $n;
}

/* Svuota il cestino; con $olderThan solo cio' che vi e' entrato prima. */
function purge_trash(?int $olderThan = null): int {
  $sql = "SELECT short FROM images WHERE deleted_at IS NOT NULL" . ($olderThan !== null ? " AND deleted_at < ?" : "");
  $q = db()->prepare($sql);
  $q->execute($olderThan !== null ? [$olderThan] : []);
  $n = 0;
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) $n += (int) delete_image($s);
  if ($n) tags_purge_orphans();
  return $n;
}

function trash_count(): int {
  return (int) db()->query("SELECT COUNT(*) FROM images WHERE deleted_at IS NOT NULL")->fetchColumn();
}

/* ---------------------------------------------------------------------------
 * Ricerca (galleria e pannello)
 * ------------------------------------------------------------------------- */

/* "tramonto folder:Viaggi tag:mare" -> [query FTS, etichette, testo libero].
 * id: cerca nel nome del file (short e' UNINDEXED nell'indice FTS). */
function search_parse(string $q): array {
  $fts = []; $tags = []; $plain = [];
  foreach (explode(' ', $q) as $tok) {
    if ($tok === '') continue;
    if (preg_match('~^tag:(.+)$~i', $tok, $m)) {
      $t = norm_tag(trim($m[1], "\"'"));
      if ($t !== '') $tags[] = $t;
      continue;
    }
    $plain[] = $tok;
    if (preg_match('~^(folder|title|alt|file|id):(.+)$~i', $tok, $m)) {
      $k = strtolower($m[1]);
      $v = str_replace('"', '""', trim(trim($m[2]), "\"'"));
      $fts[] = (($k === 'id' || $k === 'file') ? 'filename' : $k) . ':"' . $v . '"';
    } else {
      $fts[] = '"' . str_replace('"', '""', trim($tok, "\"'")) . '"';
    }
  }
  return [implode(' AND ', $fts), $tags, implode(' ', $plain)];
}

/* Opzioni: q (testo), folder (null = tutti gli album, '' = senza album),
 * tag, page, per, trash (true = solo il cestino).
 * Risultato: rows (con 'tags'), total, page (corretta), pages, fts. */
function gallery_search(array $o): array {
  $q     = substr(preg_replace('~\s+~', ' ', trim((string) ($o['q'] ?? ''))), 0, 120);
  $per   = max(1, (int) ($o['per'] ?? 48));
  $page  = max(1, (int) ($o['page'] ?? 1));
  $trash = !empty($o['trash']);
  [$fts, $tags, $plain] = search_parse($q);
  if (($o['tag'] ?? '') !== '') $tags[] = norm_tag((string) $o['tag']);

  $from = 'images i'; $where = []; $args = []; $order = $trash ? 'i.deleted_at DESC' : 'i.created_at DESC';
  $useFts = false;
  if ($fts !== '' && fts5_available()) {
    $from = 'images_fts JOIN images i ON i.id = images_fts.rowid';
    $where[] = 'images_fts MATCH ?'; $args[] = $fts;
    $order = 'bm25(images_fts), ' . $order;
    $useFts = true;
  } elseif ($plain !== '') {
    $where[] = "(i.short LIKE ? OR i.title LIKE ? OR i.alt LIKE ? OR i.filename LIKE ? OR COALESCE(i.folder,'') LIKE ?)";
    array_push($args, ...array_fill(0, 5, "%$plain%"));
  }
  $where[] = $trash ? 'i.deleted_at IS NOT NULL' : 'i.deleted_at IS NULL';
  if (array_key_exists('folder', $o) && $o['folder'] !== null) {
    $where[] = "COALESCE(i.folder,'') = ?"; $args[] = (string) $o['folder'];
  }
  foreach (array_unique(array_filter($tags)) as $t) {
    $where[] = "i.id IN (SELECT it.image_id FROM image_tags it JOIN tags t ON t.id = it.tag_id WHERE t.name = ? COLLATE NOCASE)";
    $args[] = $t;
  }
  $w = implode(' AND ', $where);

  $cnt = db()->prepare("SELECT COUNT(*) FROM $from WHERE $w");
  $cnt->execute($args);
  $total  = (int) $cnt->fetchColumn();
  $offset = page_offset($page, $total, $per);

  $st = db()->prepare("SELECT i.id, i.short, i.filename, i.mime, i.title, i.alt, i.width, i.height, i.size,
      i.created_at, i.deleted_at, COALESCE(i.folder,'') AS folder
    FROM $from WHERE $w ORDER BY $order LIMIT $per OFFSET $offset");
  $st->execute($args);
  $rows = $st->fetchAll();
  $tagmap = tags_for(array_column($rows, 'id'));
  foreach ($rows as &$r) $r['tags'] = $tagmap[(int) $r['id']] ?? [];
  unset($r);

  return ['rows' => $rows, 'total' => $total, 'page' => $page,
          'pages' => max(1, (int) ceil($total / $per)), 'fts' => $useFts];
}
