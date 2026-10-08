#!/usr/bin/env bash
# =============================================================================
#  bench/live_check.sh — controllo dell'installazione reale
#
#  Da lanciare dopo ogni rilascio e dopo ogni cambio di permessi.
#  Non ha bisogno di sudo e, senza opzioni, non scrive nulla:
#
#   1. permessi, calcolati per l'utente del server web: deve poter LEGGERE il
#      codice e SCRIVERE uploads/, thumbs/ e la cartella del database; non
#      deve poter scrivere il codice;
#   2. HTTP senza credenziali (sul server stesso, 127.0.0.1): tutto protetto
#      tranne la consegna delle immagini, che deve rispondere 200 image/*;
#   3. schema del database alla stessa versione del codice, indice di ricerca
#      allineato alla tabella images.
#
#  --write-test  prova anche una SCRITTURA reale fatta dal server web: sposta
#                da parte la miniatura piu' piccola, la fa rigenerare con una
#                richiesta a /t/, controlla il risultato e rimette l'originale
#                (byte per byte). E' la verifica "scrittura" da fare dopo un
#                cambio di permessi; una pagina che risponde 200 prova solo
#                la lettura.
#
#  Uso:  bash bench/live_check.sh [--write-test] [--perms-only] [--web-user www-data] [--app DIR]
#        --app DIR     installazione da controllare (default: la cartella sopra bench/)
#        --perms-only  solo la sezione permessi: per provare un cambio di
#                      permessi su una copia prima di applicarlo al live
#  Esito: 0 nessun errore (eventuali avvisi stampati), 1 errori.
# =============================================================================
set -uo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER="www-data"
WRITE_TEST=0
PERMS_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --write-test) WRITE_TEST=1 ;;
    --perms-only) PERMS_ONLY=1 ;;
    --web-user)   WEB_USER="${2:?}"; shift ;;
    --app)        APP="$(cd "${2:?}" && pwd)"; shift ;;
    -h|--help)    sed -n '2,26p' "$0"; exit 0 ;;
    *) echo "opzione sconosciuta: $1" >&2; exit 2 ;;
  esac
  shift
done

ERR=0; WARN=0
ok()   { printf '  ok  %s\n' "$*"; }
err()  { printf '  \033[31mXX\033[0m  %s\n' "$*"; ERR=$((ERR+1)); }
warn() { printf '  \033[33m!!\033[0m  %s\n' "$*"; WARN=$((WARN+1)); }
say()  { printf '\n\033[1m[%s]\033[0m\n' "$*"; }

read -r DB HOST LATEST < <(php -r '
  $s = @include $argv[1] . "/secret.php";
  $db = (is_array($s) && !empty($s["DB_PATH"])) ? $s["DB_PATH"] : $argv[1] . "/gallery.db";
  $host = (is_array($s) && !empty($s["ALLOWED_HOSTS"][0])) ? $s["ALLOWED_HOSTS"][0] : "localhost";
  $latest = 0;
  if (is_file($argv[1] . "/_migrations.php")) { require $argv[1] . "/_migrations.php"; $latest = schema_latest(); }
  echo $db, " ", $host, " ", $latest, "\n";' "$APP")
echo "app: $APP · DB: $DB · host: $HOST · utente web: $WEB_USER"

# ---------------------------------------------------------------------------
say "Permessi per $WEB_USER"
# Il calcolo usa i bit di stat e i gruppi di $WEB_USER, compreso
# l'attraversamento di tutte le cartelle superiori.
PERMS="$(php -r '
  [$_, $user, $app, $db] = $argv;
  $pw = posix_getpwnam($user) or exit("utente $user inesistente\n");
  $uid = $pw["uid"];
  $gids = array_map("intval", explode(" ", trim(shell_exec("id -G " . escapeshellarg($user)))));
  $can = function (string $p, string $what) use ($uid, $gids): bool {
    $st = @stat($p); if (!$st) return false;
    $m = $st["mode"];
    $bits = $st["uid"] === $uid ? ($m >> 6) & 7 : (in_array($st["gid"], $gids, true) ? ($m >> 3) & 7 : $m & 7);
    return (bool) ($bits & ["r" => 4, "w" => 2, "x" => 1][$what]);
  };
  $reach = function (string $p) use ($can): bool {
    for ($d = dirname($p); $d !== "/" && $d !== "."; $d = dirname($d)) if (!$can($d, "x")) return false;
    return true;
  };
  $res = [];
  $line = function (string $kind, string $msg) use (&$res) { $res[] = "$kind $msg"; };

  // codice: leggibile, non scrivibile
  $code = [$app];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
  foreach ($it as $f) {
    $p = $f->getPathname(); $rel = substr($p, strlen($app) + 1);
    if (preg_match("~^(uploads|thumbs)/.~", $rel)) continue;
    $code[] = $p;
  }
  $unreadable = []; $writable = [];
  foreach ($code as $p) {
    $rel = $p === $app ? "." : substr($p, strlen($app) + 1);
    if (in_array($rel, ["uploads", "thumbs"], true)) continue;
    $isdir = is_dir($p);
    // .claude/ (impostazioni di Claude Code): il server web non deve leggerla,
    // ma soprattutto non deve poterla scrivere: gli hook girano come utente normale.
    $needs_read = !str_starts_with($rel, ".claude") && ($isdir || preg_match("~\.(php|sql|htaccess)$|/\.htaccess$|^\.htaccess$~", $rel));
    if ($needs_read && (!$reach($p) || !$can($p, "r") || ($isdir && !$can($p, "x")))) $unreadable[] = $rel;
    if ($can($p, "w")) $writable[] = $rel . ($isdir ? "/" : "");
  }
  $unreadable ? $line("ERR", "non leggibili dal server web: " . implode(", ", $unreadable))
              : $line("OK", "codice leggibile dal server web (" . count($code) . " voci)");
  $writable ? $line("WARN", count($writable) . " voci del codice SCRIVIBILI dal server web (audit #6): " . implode(", ", array_slice($writable, 0, 12)) . (count($writable) > 12 ? ", …" : ""))
            : $line("OK", "codice non scrivibile dal server web");

  // dati: scrivibili
  foreach (["$app/uploads", "$app/thumbs", dirname($db)] as $d) {
    ($reach($d) && $can($d, "w") && $can($d, "x")) ? $line("OK", "cartella scrivibile: $d") : $line("ERR", "cartella NON scrivibile dal server web: $d");
  }
  foreach ([$db, "$db-wal", "$db-shm"] as $f) {
    if (!file_exists($f)) continue;
    ($can($f, "r") && $can($f, "w")) ? $line("OK", "lettura e scrittura: " . basename($f)) : $line("ERR", "il server web non puo leggere e scrivere " . basename($f));
  }
  // segreti
  $sec = "$app/secret.php";
  if (!$can($sec, "r")) $line("ERR", "secret.php non leggibile dal server web");
  elseif (fileperms($sec) & 0004) $line("WARN", "secret.php leggibile da chiunque (consigliato 640)");
  else $line("OK", "secret.php leggibile dal server web, non da altri");
  echo implode("\n", $res), "\n";
' "$WEB_USER" "$APP" "$DB")" || err "calcolo dei permessi non riuscito"
while read -r kind msg; do
  case "$kind" in OK) ok "$msg" ;; WARN) warn "$msg" ;; ERR) err "$msg" ;; esac
done <<< "$PERMS"

if [ "$PERMS_ONLY" -eq 1 ]; then
  echo
  if [ "$ERR" -eq 0 ]; then printf '\033[1mESITO (solo permessi): nessun errore%s\033[0m\n' "$( [ "$WARN" -gt 0 ] && echo ", $WARN avvisi" )"; exit 0
  else printf '\033[1mESITO (solo permessi): %d errori, %d avvisi\033[0m\n' "$ERR" "$WARN"; exit 1; fi
fi

# ---------------------------------------------------------------------------
say "Schema del database"
SQ() { sqlite3 "file:$DB?immutable=1" "$1" 2>/dev/null; }
if [ -s "$DB-wal" ]; then
  warn "il file -wal non e' vuoto: la lettura in sola lettura potrebbe non vedere le ultime scritture"
fi
CUR="$(SQ "SELECT COALESCE(MAX(version),0) FROM schema_version" || echo 0)"; CUR="${CUR:-0}"
if [ "$LATEST" = 0 ]; then
  warn "codice senza migrazioni automatiche (_migrations.php assente)"
elif [ "$CUR" = "$LATEST" ]; then
  ok "versione $CUR, come il codice ($(SQ "SELECT datetime(applied_at,'unixepoch','localtime') FROM schema_version WHERE version=$CUR"))"
elif [ "$CUR" -lt "$LATEST" ]; then
  warn "DB alla versione $CUR, codice alla $LATEST: le migrazioni partono alla prossima richiesta"
else
  warn "DB alla versione $CUR, piu' avanti del codice ($LATEST): codice tornato indietro?"
fi
N_IMG="$(SQ "SELECT COUNT(*) FROM images")"
N_FTS="$(SQ "SELECT COUNT(*) FROM images_fts_docsize")"
if [ -z "$N_FTS" ]; then warn "indice FTS assente: la ricerca usa LIKE"
elif [ "$N_FTS" = "$N_IMG" ]; then ok "indice di ricerca allineato: $N_FTS voci per $N_IMG immagini"
else err "indice di ricerca disallineato: $N_FTS voci per $N_IMG immagini"; fi

# ---------------------------------------------------------------------------
say "HTTP senza credenziali (https://$HOST via 127.0.0.1)"
CURL=(curl -sS -k --max-time 15 --resolve "$HOST:443:127.0.0.1")
code() { "${CURL[@]}" -o /dev/null -w '%{http_code}' "https://$HOST$1" 2>/dev/null || echo 000; }

for p in /gallery/ /gallery/admin/ /gallery/upload.php /gallery/api/upload.php /gallery/delete.php \
         /gallery/regen_thumbs.php /gallery/_fpm_check.php; do
  c="$(code "$p")"; [ "$c" = 401 ] && ok "$p -> 401" || err "$p -> $c (atteso 401)"
done
for p in /gallery/secret.php /gallery/config.php /gallery/_migrations.php /gallery/_images.php \
         /gallery/schema.sql /gallery/CHANGES.md /gallery/.htaccess /gallery/apply_root_tasks.sh \
         /gallery/bench/run.sh /gallery/bench/tests.php /gallery/uploads/ /gallery/thumbs/; do
  body="$("${CURL[@]}" -w '\n%{http_code}' "https://$HOST$p" 2>/dev/null)"; c="${body##*$'\n'}"
  if [[ "$c" =~ ^(401|403|404)$ ]] && ! grep -qE '<\?php|API_TOKEN|DB_PATH' <<< "$body"; then ok "$p -> $c"
  else err "$p -> $c (atteso 401/403/404 senza contenuto)"; fi
done
SHORT="$(SQ "SELECT short FROM images ORDER BY created_at DESC LIMIT 1")"
for kind in i t; do
  read -r c ct < <("${CURL[@]}" -o /dev/null -w '%{http_code} %{content_type}\n' "https://$HOST/gallery/$kind/$SHORT" 2>/dev/null || echo "000 -")
  [ "$c" = 200 ] && [[ "$ct" == image/* ]] && ok "/gallery/$kind/$SHORT -> 200 $ct" || err "/gallery/$kind/$SHORT -> $c $ct (atteso 200 image/*)"
done

# ---------------------------------------------------------------------------
if [ "$WRITE_TEST" -eq 1 ]; then
  say "Scrittura reale del server web (miniatura rigenerata e poi ripristinata)"
  read -r TS TF < <(SQ "SELECT short, filename FROM images" | while IFS='|' read -r s f; do
      [ -f "$APP/thumbs/$f" ] && echo "$(stat -c %s "$APP/thumbs/$f") $s $f"; done | sort -n | head -1 | cut -d' ' -f2-)
  if [ -z "${TF:-}" ]; then err "nessuna miniatura su cui provare"; else
    T="$APP/thumbs/$TF"; SAVED="$T.live-check-$$"
    mv "$T" "$SAVED" || { err "impossibile spostare $TF (servono i permessi di gruppo su thumbs/)"; SAVED=""; }
    if [ -n "$SAVED" ]; then
      trap 'mv -f "$SAVED" "$T" 2>/dev/null' EXIT
      c="$(code "/gallery/t/$TS")"
      if [ "$c" = 200 ] && [ -s "$T" ] && [ "$(stat -c %U "$T")" = "$WEB_USER" ]; then
        ok "rigenerata da $WEB_USER: $TF ($(stat -c %s "$T") byte, HTTP $c)"
        cmp -s "$T" "$SAVED" && ok "identica all'originale" || ok "diversa dall'originale nei byte (normale se prodotta da codice piu' vecchio)"
        ls "$APP/thumbs/" | grep -q '\.tmp-' && err "file temporanei rimasti in thumbs/" || ok "nessun file temporaneo rimasto"
      else
        err "rigenerazione fallita: HTTP $c, file $( [ -e "$T" ] && stat -c '%U %s' "$T" || echo assente)"
      fi
      mv -f "$SAVED" "$T" && trap - EXIT && ok "originale rimesso al suo posto (proprietario $(stat -c %U "$T"))"
    fi
  fi
fi

echo
if [ "$ERR" -eq 0 ]; then printf '\033[1mESITO: nessun errore%s\033[0m\n' "$( [ "$WARN" -gt 0 ] && echo ", $WARN avvisi" )"; exit 0
else printf '\033[1mESITO: %d errori, %d avvisi\033[0m\n' "$ERR" "$WARN"; exit 1; fi
