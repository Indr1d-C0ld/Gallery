<?php
/* =========================================================================
 * Gallery – ingresso di un'immagine nell'archivio
 * ---------------------------------------------------------------------------
 * Il percorso di upload.php, diventato una funzione (Tranche 5) perche' ora
 * le immagini arrivano anche dal bot Telegram, che le scarica: non sono
 * "uploaded files" e move_uploaded_file() le rifiuterebbe.
 *
 * ingest_image($path, $opt) controlla, ripulisce e salva il file:
 *   tipo (finfo) -> pixel prima di decodificare (bombe) -> posizione GPS
 *   tolta -> misure con orientamento -> doppioni (sha256) -> file, miniatura,
 *   riga nel DB (e se la riga fallisce, via il file).
 * Opzioni: folder, title, alt, private (bool), uploaded (true = file di un
 * form, si sposta con move_uploaded_file).
 * Risultato: ['ok' => true, 'row' => riga, 'duplicate', 'restored',
 * 'location_removed'] oppure ['ok' => false, 'code' => HTTP, 'error' => testo].
 * ========================================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_images.php';
require_once __DIR__ . '/_archive.php';     // norm_folder()

function ingest_fail(int $code, string $msg): array { return ['ok' => false, 'code' => $code, 'error' => $msg]; }

function ingest_image(string $path, array $opt = []): array {
  global $ALLOWED, $MAX_BYTES, $MAX_PIXELS, $UPLOADS, $USE_THUMBS;

  /* 2) Dimensione del file */
  $size = is_file($path) ? (int) filesize($path) : 0;
  if ($size <= 0) return ingest_fail(400, "empty upload");
  if ($size > $MAX_BYTES) return ingest_fail(400, "too large (max " . (int) $MAX_BYTES . " bytes)");

  /* 3) MIME */
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($fi, $path);
  finfo_close($fi);
  if (!isset($ALLOWED[$mime])) return ingest_fail(415, "unsupported mime: " . $mime);
  $ext = $ALLOWED[$mime];

  /* 3b) Dimensioni in pixel — PRIMA di qualunque decodifica.
   * getimagesize() legge solo l'intestazione: una "decompression bomb" (file
   * piccolo che dichiara decine di migliaia di pixel per lato) verrebbe
   * altrimenti espansa in RAM da GD fino a saturare la memoria della macchina.
   * Il controllo avviene sul file temporaneo: una bomba non tocca mai uploads/. */
  $gi = @getimagesize($path);
  if (!is_array($gi) || ($gi[0] ?? 0) < 1 || ($gi[1] ?? 0) < 1) return ingest_fail(415, "immagine non leggibile o corrotta");
  if ($gi[0] * $gi[1] > $MAX_PIXELS) {
    return ingest_fail(413, sprintf("immagine troppo grande: %d×%d = %.1f megapixel (max %.0f)",
      $gi[0], $gi[1], ($gi[0] * $gi[1]) / 1e6, $MAX_PIXELS / 1e6));
  }

  /* 3c) Dati di posizione: via dal file temporaneo, prima che diventi
   * pubblico. Si tolgono sul posto (stessi pixel); solo se la posizione e'
   * in una forma sconosciuta l'immagine viene risalvata senza metadati, e se
   * nemmeno questo basta il caricamento viene rifiutato. */
  $loc = strip_location($path, $mime);
  if ($loc < 0) return ingest_fail(422, "l'immagine contiene dati di posizione che non si riescono a togliere: caricamento rifiutato");
  if ($loc === 2) $gi = @getimagesize($path) ?: $gi;   // risalvata: misure e orientamento nuovi

  /* 3d) Misure come le mostra il browser: con orientamento EXIF da 5 a 8 la
   * foto e' salvata coricata, quindi larghezza e altezza vanno scambiate. */
  $w = (int) $gi[0];
  $h = (int) $gi[1];
  if (image_orientation($path, $mime) >= 5) [$w, $h] = [$h, $w];

  /* 4) Album */
  $folder = norm_folder($opt['folder'] ?? '');

  /* 4b) Doppioni: la stessa immagine, byte per byte (dopo aver tolto la
   * posizione), e' gia' in archivio? Allora si restituisce quella; se e' nel
   * cestino viene ripristinata con i suoi indirizzi di prima. Fra piu' righe
   * con lo stesso file (solo in un DB precedente alla v4) si preferisce una
   * visibile, poi quella nello stesso album, poi la piu' vecchia. Se il DB
   * non risponde si prosegue: l'inserimento fallira' comunque in modo pulito. */
  $sha = hash_file('sha256', $path);
  $existing = null;
  try {
    $q = db()->prepare("SELECT * FROM images WHERE sha256=? ORDER BY (deleted_at IS NULL) DESC, (COALESCE(folder,'')=?) DESC, id ASC");
    $q->execute([$sha, $folder]);
    foreach ($q->fetchAll() as $r) {
      if (is_file(upload_path($r['filename']))) { $existing = $r; break; }
    }
  } catch (Throwable $e) {
    error_log('gallery upload: ricerca doppioni non riuscita — ' . $e->getMessage());
  }
  if ($existing) {
    $restored = $existing['deleted_at'] !== null;
    if ($restored) {
      db()->prepare("UPDATE images SET deleted_at=NULL WHERE id=?")->execute([$existing['id']]);
      $existing['deleted_at'] = null;
    }
    return ['ok' => true, 'row' => $existing, 'duplicate' => true, 'restored' => $restored, 'location_removed' => $loc > 0];
  }

  /* 5) Identificativi */
  $short  = shortcode(7);
  $delkey = bin2hex(random_bytes(8));
  $fname  = $short . "." . $ext;
  $dest   = upload_path($fname);

  /* 6) File */
  if (!is_dir($UPLOADS)) @mkdir($UPLOADS, 0775, true);
  $moved = !empty($opt['uploaded']) ? move_uploaded_file($path, $dest) : @rename($path, $dest);
  if (!$moved && empty($opt['uploaded']) && @copy($path, $dest)) { @unlink($path); $moved = true; }   // altro filesystem
  if (!$moved) return ingest_fail(500, "store failed");
  // come move_uploaded_file(): 0666 meno l'umask (un temporaneo nasce 0600, e
  // il backup, che legge uploads/ come utente normale, non lo leggerebbe)
  if (empty($opt['uploaded'])) @chmod($dest, 0666 & ~umask());

  /* 8) Miniatura (se fallisce, i.php ritenta alla prima richiesta) */
  if ($USE_THUMBS) make_thumb($dest, thumb_path($fname), $mime);

  /* 9) Riga nel DB. Il file e' gia' su disco: se l'inserimento fallisce
   * (disco pieno, DB bloccato, collisione di codice) resterebbe un file
   * orfano, invisibile dall'interfaccia ma che occupa spazio per sempre. */
  $row = [
    'short'      => $short,
    'filename'   => $fname,
    'mime'       => $mime,
    'size'       => (int) filesize($dest),
    'width'      => $w,
    'height'     => $h,
    'title'      => ($opt['title'] ?? '') !== '' ? (string) $opt['title'] : null,
    'alt'        => ($opt['alt'] ?? '') !== '' ? (string) $opt['alt'] : null,
    'delkey'     => $delkey,
    'created_at' => time(),
    'folder'     => $folder,
    'sha256'     => $sha,
    'private'    => empty($opt['private']) ? 0 : 1,
  ];
  try {
    db()->prepare("INSERT INTO images(" . implode(',', array_keys($row)) . ")
                   VALUES(" . implode(',', array_fill(0, count($row), '?')) . ")")
        ->execute(array_values($row));
  } catch (Throwable $e) {
    @unlink($dest);                 // niente riga, niente file
    @unlink(thumb_path($fname));    // e nemmeno la miniatura
    error_log('gallery upload: insert fallito per ' . $fname . ' — ' . $e->getMessage());
    return ingest_fail(500, "salvataggio non riuscito");
  }
  $row['deleted_at'] = null;
  return ['ok' => true, 'row' => $row, 'duplicate' => false, 'restored' => false, 'location_removed' => $loc > 0];
}
