#!/usr/bin/env bash
# =============================================================================
#  bench/run.sh — banco di prova isolato per Gallery
#
#  Costruisce una COPIA INTEGRALE (codice, database, immagini, miniature) in
#  una cartella temporanea, la serve con `php -S` su 127.0.0.1 e ci fa girare
#  la batteria di prove di bench/tests.php. Alla fine cancella tutto.
#
#  Nessun collegamento ai dati reali: il banco ha un secret.php suo (DB nel
#  banco, token e credenziali generati al momento), niente link simbolici, e
#  prima e dopo le prove si confronta l'impronta dei dati reali: se cambia
#  qualcosa il banco fallisce.
#
#  Uso:
#    bash bench/run.sh [opzioni]
#      --code DIR   codice da provare (default: la cartella che contiene bench/)
#      --data DIR   installazione da cui copiare DB e immagini (default: la
#                   stessa di --code; il DB e' quello di DB_PATH nel suo
#                   secret.php). Per provare codice preparato altrove:
#                   --code /percorso/copia --data /percorso/installazione
#      --dir DIR    cartella del banco (default: nuova cartella temporanea)
#      --keep       non cancellare il banco alla fine (contiene copie delle
#                   immagini private: ricordarsi di rimuoverlo)
#      --serve      niente prove: lascia il server acceso per provare a mano
#                   nel browser, gia' autenticato, fino a Ctrl+C (poi il
#                   banco viene cancellato come sempre)
#      --stats FILE statistiche d'uso da copiare nel banco (default: quelle
#                   di --data, stats/stats.db, se ci sono)
#
#  Esito: 0 se tutte le prove passano, 1 altrimenti.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CODE="$(cd "$HERE/.." && pwd)"
DATA=""
BENCH=""
KEEP=0
SERVE=0
STATS_SRC=""

while [ $# -gt 0 ]; do
  case "$1" in
    --code) CODE="$(cd "${2:?}" && pwd)"; shift ;;
    --data) DATA="$(cd "${2:?}" && pwd)"; shift ;;
    --dir)  BENCH="${2:?}"; shift ;;
    --keep) KEEP=1 ;;
    --serve) SERVE=1 ;;
    --stats) STATS_SRC="${2:?}"; shift ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "opzione sconosciuta: $1" >&2; exit 2 ;;
  esac
  shift
done

[ -n "$DATA" ] || DATA="$CODE"

say()  { printf '\033[1m== %s\033[0m\n' "$*"; }
die()  { printf '\033[31m!! %s\033[0m\n' "$*" >&2; exit 1; }

# --- Dati reali: solo lettura ----------------------------------------------
REAL_DB="$(php -r '$s = @include $argv[1]; echo (is_array($s) && !empty($s["DB_PATH"])) ? $s["DB_PATH"] : dirname($argv[1]) . "/gallery.db";' "$DATA/secret.php")"
[ -r "$REAL_DB" ] || die "database reale non leggibile: $REAL_DB"
[ -d "$DATA/uploads" ] || die "uploads/ non trovato in $DATA"
[ -n "$STATS_SRC" ] || { [ -f "$DATA/stats/stats.db" ] && STATS_SRC="$DATA/stats/stats.db"; } || true
[ -z "$STATS_SRC" ] || [ -r "$STATS_SRC" ] || die "statistiche non leggibili: $STATS_SRC"

fingerprint() {
  {
    stat -c '%n %s %Y' "$REAL_DB" "$REAL_DB-wal" "$DATA/stats/stats.db" 2>/dev/null || true
    sha256sum "$REAL_DB"
    ( cd "$DATA" && find uploads thumbs -printf '%p %s %T@\n' | sort )
  } | sha256sum | cut -c1-16
}
FP_BEFORE="$(fingerprint)"

# --- Costruzione del banco ---------------------------------------------------
if [ -z "$BENCH" ]; then BENCH="$(mktemp -d "${TMPDIR:-/tmp}/gallery-bench.XXXXXX")"; fi
mkdir -p "$BENCH"
BENCH="$(cd "$BENCH" && pwd)"
case "$BENCH" in "$DATA"|"$DATA"/*|"$CODE"|"$CODE"/*) die "il banco non puo' stare dentro $DATA o $CODE";; esac
[ -z "$(ls -A "$BENCH")" ] || die "la cartella del banco non e' vuota: $BENCH"

SERVER_PID=""
cleanup() {
  # il server ha i suoi processi figli (worker): si ferma l'intero gruppo
  if [ -n "$SERVER_PID" ]; then
    kill -- "-$SERVER_PID" 2>/dev/null || kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [ "$KEEP" -eq 1 ]; then
    echo "banco conservato: $BENCH  (contiene copie delle immagini: rm -rf quando hai finito)"
  else
    chmod -R u+w "$BENCH" 2>/dev/null || true
    rm -rf "$BENCH"
  fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM      # cosi' anche Ctrl+C e kill passano da cleanup

say "banco: $BENCH"
mkdir -p "$BENCH/www/gallery" "$BENCH/db" "$BENCH/tools" "$BENCH/log" "$BENCH/sessions" "$BENCH/tmp" "$BENCH/apache"

# codice: tutto tranne .claude e i dati
rsync -a --no-owner --no-group --exclude='.claude' --exclude='_orig_backup_*' --exclude='/secret.php' \
  --exclude='/gallery.db*' --exclude='/uploads/*' --exclude='/thumbs/*' --exclude='/stats/*' \
  "$CODE"/ "$BENCH/www/gallery"/
# dati: copia integrale delle immagini e del database
rsync -a --no-owner --no-group "$DATA/uploads"/ "$BENCH/www/gallery/uploads"/
rsync -a --no-owner --no-group "$DATA/thumbs"/  "$BENCH/www/gallery/thumbs"/
sqlite3 "file:$REAL_DB?immutable=1" "VACUUM INTO '$BENCH/db/gallery.db'"
# statistiche d'uso: il job le sostituisce con rename(), quindi il file e' sempre intero
mkdir -p "$BENCH/www/gallery/stats"
[ -z "$STATS_SRC" ] || cp "$STATS_SRC" "$BENCH/www/gallery/stats/stats.db"
cp "$HERE/router.php" "$HERE/tests.php" "$BENCH/tools/"

# porta libera e credenziali usa-e-getta
PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
PASS="$(php -r 'echo bin2hex(random_bytes(12));')"
TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"

# secret.php del banco (quello reale non viene copiato: punta al DB vero)
cat > "$BENCH/www/gallery/secret.php" <<PHP
<?php
// banco di prova — generato da bench/run.sh, nessun dato reale
return [
    'API_TOKEN'     => '$TOKEN',
    'ALLOWED_HOSTS' => ['127.0.0.1:$PORT'],
    'DB_PATH'       => '$BENCH/db/gallery.db',
    'STATS_DB'      => '$BENCH/www/gallery/stats/stats.db',
    'ACCESS_LOGS'   => '$BENCH/apache/access.log*',
];
PHP

cat > "$BENCH/tools/bench.json" <<JSON
{"bench":"$BENCH","www":"$BENCH/www/gallery","db":"$BENCH/db/gallery.db",
 "base":"http://127.0.0.1:$PORT","user":"bench","pass":"$PASS","token":"$TOKEN",
 "log":"$BENCH/log/php_errors.log","real_db":"$REAL_DB","real_data":"$DATA",
 "stats_db":"$BENCH/www/gallery/stats/stats.db","apache":"$BENCH/apache","stats_copied":$( [ -n "$STATS_SRC" ] && echo true || echo false ),
 "autologin":$( [ "$SERVE" -eq 1 ] && echo true || echo false )}
JSON

# --- Verifica di isolamento, PRIMA di avviare qualunque cosa ------------------
say "isolamento"
EFFECTIVE="$(cd "$BENCH/www/gallery" && php -r '
  $_SERVER["HTTP_HOST"] = "127.0.0.1";
  require "config.php";
  foreach ([$DB_PATH, $UPLOADS, $THUMBS, $STATS_DB, $ACCESS_LOGS] as $p) echo realpath(dirname($p)) . "/" . basename($p), "\n";')"
while read -r p; do
  case "$p" in "$BENCH"/*) echo "  ok  $p" ;; *) die "percorso fuori dal banco: $p" ;; esac
done <<< "$EFFECTIVE"
[ -z "$(find "$BENCH" -type l)" ] || die "link simbolici nel banco: $(find "$BENCH" -type l | head -3)"
grep -q "$REAL_DB" "$BENCH/www/gallery/secret.php" && die "secret.php del banco punta al DB reale"
echo "  ok  nessun link simbolico, secret.php del banco"

# --- Server --------------------------------------------------------------------
# Limiti uguali a quelli di Apache (php.ini apache2), sessioni e temporanei nel banco.
# OPcache spento: e' attivo anche in `php -S` (opcache.enable_cli non lo
# riguarda) e terrebbe in cache secret.php, che le prove cambiano al volo.
say "server su 127.0.0.1:$PORT"
PHP_CLI_SERVER_WORKERS=4 setsid php -d opcache.enable=0 \
  -d upload_max_filesize=20M -d post_max_size=20M -d memory_limit=128M -d max_execution_time=30 \
  -d display_errors=0 -d log_errors=1 -d error_log="$BENCH/log/php_errors.log" \
  -d session.save_path="$BENCH/sessions" -d upload_tmp_dir="$BENCH/tmp" -d sys_temp_dir="$BENCH/tmp" \
  -S "127.0.0.1:$PORT" -t "$BENCH/www" "$BENCH/tools/router.php" >"$BENCH/log/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 50); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/gallery/i.php" && break
  sleep 0.1
done

if [ "$SERVE" -eq 1 ]; then
  say "banco acceso: http://127.0.0.1:$PORT/gallery/  (gia' autenticato)"
  echo "   per chiudere: Ctrl+C, oppure  kill $$  (il banco poi si cancella da solo)"
  echo "   log PHP: $BENCH/log/php_errors.log"
  wait "$SERVER_PID" || true
  exit 0
fi

# --- Prove ----------------------------------------------------------------------
say "prove"
set +e
php -d error_log="$BENCH/log/tests_cli.log" "$BENCH/tools/tests.php" "$BENCH/tools/bench.json"
RC=$?
set -e

# --- Dati reali intatti? ---------------------------------------------------------
FP_AFTER="$(fingerprint)"
if [ "$FP_BEFORE" = "$FP_AFTER" ]; then
  echo "  ok  dati reali invariati (impronta $FP_AFTER)"
else
  echo "  !!  l'impronta dei dati reali e' cambiata durante il banco ($FP_BEFORE -> $FP_AFTER)."
  echo "      Se nel frattempo nessuno ha usato la galleria (e non e' girato il job notturno delle"
  echo "      statistiche), qualcosa nel banco ha toccato i dati veri."
  RC=1
fi

[ "$RC" -eq 0 ] && say "ESITO: tutte le prove superate" || say "ESITO: ci sono prove fallite"
exit "$RC"
