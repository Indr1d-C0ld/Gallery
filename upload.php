<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_ingest.php";      // controlli, posizione GPS, doppioni, salvataggio

/* Chiamata via API con token (api/upload.php): salta login di sessione e CSRF,
   il token è già stato verificato. Altrimenti: richiede auth Apache + CSRF. */
if (!defined('GALLERY_API_CALL')) {
  require_login();
  csrf_check();
}

/* Risposta in JSON per l'API e per il caricatore della pagina (che chiede
 * "Accept: application/json"); per il form classico testo e redirect. */
$JSON = defined('GALLERY_API_CALL') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function upload_fail(int $code, string $msg): void {
  global $JSON;
  http_response_code($code);
  if ($JSON) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  } else {
    echo $msg;
  }
  exit;
}

/* Esito positivo: immagine appena salvata oppure doppione gia' in archivio
 * (eventualmente ripescato dal cestino). */
function upload_done(array $row, bool $duplicate, bool $location_removed = false, bool $restored = false): void {
  global $JSON, $BASE_URL;
  if ($JSON) {
    $out = ['ok' => true, 'duplicate' => $duplicate, 'restored' => $restored, 'location_removed' => $location_removed,
            'private' => !empty($row['private'])] + snippet_data($row);
    if (defined('GALLERY_API_CALL')) {
      $out['delete'] = $BASE_URL . '/delete.php?c=' . $row['short'] . '&k=' . $row['delkey'];
    }
    header('Content-Type: application/json');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }
  $target = $BASE_URL . "/?ok=" . rawurlencode($row['short']) . ($duplicate ? '&dup=1' : '') . ($restored ? '&restored=1' : '');
  header("Location: " . $target, true, 303);
  exit;
}

if (empty($_FILES['img'])) {
  upload_fail(400, "no file");
}

$f = $_FILES['img'];

/* 1) Upload error dettagliato */
if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
  $map = [
    UPLOAD_ERR_INI_SIZE   => 'INI_SIZE (upload_max_filesize)',
    UPLOAD_ERR_FORM_SIZE  => 'FORM_SIZE (MAX_FILE_SIZE)',
    UPLOAD_ERR_PARTIAL    => 'PARTIAL',
    UPLOAD_ERR_NO_FILE    => 'NO_FILE',
    UPLOAD_ERR_NO_TMP_DIR => 'NO_TMP_DIR',
    UPLOAD_ERR_CANT_WRITE => 'CANT_WRITE',
    UPLOAD_ERR_EXTENSION  => 'EXTENSION'
  ];
  $code = $f['error'] ?? -1;
  $msg  = $map[$code] ?? 'UNKNOWN';
  upload_fail(400, "upload error: $code ($msg)");
}

/* 2–10) Tutto il resto in _ingest.php: dimensione, tipo, pixel prima di
 * decodificare, posizione GPS, misure, doppioni, file, miniatura, riga. */
$res = ingest_image((string) $f['tmp_name'], [
  'folder'   => post_str('folder'),
  'title'    => post_str('title'),
  'alt'      => post_str('alt'),
  'private'  => post_str('private') === '1',
  'uploaded' => true,
]);
if (!$res['ok']) upload_fail($res['code'], $res['error']);
upload_done($res['row'], $res['duplicate'], $res['location_removed'], $res['restored']);
