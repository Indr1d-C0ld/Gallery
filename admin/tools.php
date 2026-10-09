<?php
/* Configurazioni pronte per gli screenshot dal desktop (Tranche 5), con il
 * token dell'API gia' dentro: solo per chi e' entrato nel pannello.
 *   tools.php?f=sharex&album=Screenshot      Gallery.sxcu (ShareX, Windows)
 *   tools.php?f=flameshot&album=Screenshot   gallery-screenshot.sh (Flameshot, Linux) */
require_once __DIR__ . "/../_api.php";

require_login();
if (!api_token_ok()) { http_response_code(503); exit("API disattivata: manca un API_TOKEN robusto in secret.php\n"); }

$album = norm_folder(get_str('album', 'Screenshot'));
$up    = $BASE_URL . '/api/upload.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

switch (get_str('f')) {
  case 'sharex':
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="Gallery.sxcu"');
    echo json_encode([
      'Version'         => '17.0.0',
      'Name'            => 'Gallery (' . parse_url($BASE_URL, PHP_URL_HOST) . ')',
      'DestinationType' => 'ImageUploader',
      'RequestMethod'   => 'POST',
      'RequestURL'      => $up,
      'Headers'         => ['X-Api-Token' => (string) $API_TOKEN],
      'Body'            => 'MultipartFormData',
      'Arguments'       => $album !== '' ? ['folder' => $album] : (object) [],
      'FileFormName'    => 'img',
      'URL'             => '{json:url}',
      'ThumbnailURL'    => '{json:thumb}',
      'DeletionURL'     => '{json:delete}',
      'ErrorMessage'    => '{json:error}',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;

  case 'flameshot':
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Content-Disposition: attachment; filename="gallery-screenshot.sh"');
    $q = fn(string $s) => "'" . str_replace("'", "'\\''", $s) . "'";
    $when = date('d/m/Y H:i');
    echo <<<SH
#!/usr/bin/env bash
# Gallery — cattura con Flameshot, carica, link negli appunti.
# Scaricato dal pannello il {$when}: contiene il token dell'API,
# tienilo per te (chmod 700). Uso: gallery-screenshot.sh [--full]
# Comodo su una scorciatoia di tastiera (es. Stamp).
set -euo pipefail
URL={$q($up)}
TOKEN={$q((string) $API_TOKEN)}
ALBUM={$q($album)}

tmp="\$(mktemp --suffix=.png)"
trap 'rm -f "\$tmp"' EXIT
if [ "\${1:-}" = "--full" ]; then flameshot full --raw > "\$tmp"; else flameshot gui --raw > "\$tmp" || true; fi
[ -s "\$tmp" ] || exit 0                       # cattura annullata (Esc)

resp="\$(curl -sS --max-time 60 -H "X-Api-Token: \$TOKEN" -F "img=@\$tmp;type=image/png" -F "folder=\$ALBUM" "\$URL")" || resp=""
link="\$(printf '%s' "\$resp" | python3 -c 'import sys, json; print(json.load(sys.stdin)["url"])' 2>/dev/null \\
       || printf '%s' "\$resp" | sed -n 's/.*"url":"\\([^"]*\\)".*/\\1/p')"
if [ -z "\$link" ]; then
  err="\$(printf '%s' "\$resp" | sed -n 's/.*"error":"\\([^"]*\\)".*/\\1/p')"
  notify-send -u critical "Gallery" "Caricamento non riuscito: \${err:-nessuna risposta}" 2>/dev/null || true
  echo "caricamento non riuscito: \${err:-\$resp}" >&2
  exit 1
fi
if [ -n "\${WAYLAND_DISPLAY:-}" ] && command -v wl-copy >/dev/null; then printf '%s' "\$link" | wl-copy
elif command -v xclip >/dev/null; then printf '%s' "\$link" | xclip -selection clipboard
elif command -v xsel >/dev/null; then printf '%s' "\$link" | xsel --clipboard --input
fi
notify-send "Gallery" "\$link" 2>/dev/null || true
echo "\$link"

SH;
    exit;
}
http_response_code(404);
echo "sconosciuto\n";
