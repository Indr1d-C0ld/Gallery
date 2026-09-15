#!/usr/bin/env bash
# =============================================================================
#  apply_root_tasks.sh  —  esegue i passi "che restano da fare (serve root)"
#  della revisione Gallery 2026-09.
#
#    1. installa la nuova gallery.conf  (hotlink pubblico solo su i.php / i/ / t/)
#    2. crea gallery/secret.php con un API_TOKEN forte  (se non c'è già)
#    3. (opzionale) reimposta la password Basic Auth dell'utente admin
#    4. lancia  php migrate.php  come utente web
#    5. verifica: galleria protetta, immagini pubbliche
#
#  Uso:
#    sudo ./apply_root_tasks.sh                 # interattivo
#    sudo ./apply_root_tasks.sh --yes           # nessuna domanda, salta il passo 3
#    sudo ./apply_root_tasks.sh --skip-apache   # salta il passo 1
#    sudo ./apply_root_tasks.sh --host esempio.tld
#
#  Rollback passo 1:  il file preesistente viene salvato in
#    /etc/apache2/conf-available/gallery.conf.bak.<timestamp>
# =============================================================================
set -euo pipefail

# ---- parametri -------------------------------------------------------------
ASSUME_YES=0
SKIP_APACHE=0
SKIP_VERIFY=0
HOST="tuo-dominio.tld"
WEB_USER="www-data"
WEB_GROUP="www-data"

while [ $# -gt 0 ]; do
  case "$1" in
    --yes|-y)       ASSUME_YES=1 ;;
    --skip-apache)  SKIP_APACHE=1 ;;
    --skip-verify)  SKIP_VERIFY=1 ;;
    --host)         HOST="${2:?}"; shift ;;
    --host=*)       HOST="${1#*=}" ;;
    -h|--help)      sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "opzione sconosciuta: $1" >&2; exit 2 ;;
  esac
  shift
done

GALLERY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF_LINK="/etc/apache2/conf-enabled/gallery.conf"
CONF_FILE="$(readlink -f "$CONF_LINK" 2>/dev/null || echo /etc/apache2/conf-available/gallery.conf)"
HTPASSWD_FILE="/etc/apache2/.htpasswd-gallery"
SECRET_FILE="$GALLERY_DIR/secret.php"
TS="$(date +%Y%m%d-%H%M%S)"

# Il database puo' stare fuori dal docroot (chiave DB_PATH in secret.php):
# lo si chiede all'app invece di codificarlo, cosi' lo script segue la
# configurazione reale qualunque essa sia.
DB_PATH="$(php -r '
  $s = @include $argv[1];
  $d = dirname($argv[1]);
  echo (is_array($s) && !empty($s["DB_PATH"])) ? $s["DB_PATH"] : $d . "/gallery.db";
' "$SECRET_FILE" 2>/dev/null)"
[ -n "$DB_PATH" ] || DB_PATH="$GALLERY_DIR/gallery.db"

c_ok(){   printf '  \033[32m✓\033[0m %s\n' "$*"; }
c_info(){ printf '  \033[36m·\033[0m %s\n' "$*"; }
c_warn(){ printf '  \033[33m!\033[0m %s\n' "$*"; }
c_err(){  printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }
step(){   printf '\n\033[1m[%s]\033[0m %s\n' "$1" "$2"; }

[ "$(id -u)" -eq 0 ] || { c_err "Esegui con sudo/root."; exit 1; }
[ -d "$GALLERY_DIR" ] || { c_err "cartella gallery non trovata"; exit 1; }
command -v apache2ctl >/dev/null || { c_err "apache2ctl assente"; exit 1; }

# ------------------------------------------------------------------ contenuti
read -r -d '' NEW_CONF <<'EOF' || true
# /etc/apache2/conf-available/gallery.conf
# Revisione 2026-09: l'endpoint immagini (i.php, /i/, /t/) è pubblico per
# consentire l'hotlink da forum/siti esterni; tutto il resto resta dietro Basic Auth.

<Directory /var/www/html/gallery>
    Options -Indexes -MultiViews
    AllowOverride All

    RewriteEngine On
    RewriteBase /gallery/
    RewriteRule ^i/([A-Za-z0-9_-]+)$ i.php?c=$1 [L,QSA]
    RewriteRule ^t/([A-Za-z0-9_-]+)$ i.php?c=$1&thumb=1 [L,QSA]

    AuthType Basic
    AuthName "Gallery - Accesso riservato"
    AuthUserFile /etc/apache2/.htpasswd-gallery

    <RequireAny>
        # pubblico: solo la consegna delle immagini
        Require expr %{REQUEST_URI} =~ m#^/gallery/(i\.php$|i/[A-Za-z0-9_-]+$|t/[A-Za-z0-9_-]+$)#
        # tutto il resto: credenziali
        Require valid-user
    </RequireAny>
</Directory>

# Doppio lucchetto: file che non devono mai uscire, anche se raggiunti direttamente
<Files ~ "\.(db|db-wal|db-shm|sqlite|sql|md|log|bak|sh)$">
    Require all denied
</Files>
<Files "secret.php">
    Require all denied
</Files>
EOF

# =========================================================== 1) apache conf
if [ "$SKIP_APACHE" -eq 1 ]; then
  step 1 "Apache — SALTATO (--skip-apache)"
else
  step 1 "Apache — nuova gallery.conf"
  if [ -f "$CONF_FILE" ] && [ "$(cat "$CONF_FILE")" = "$NEW_CONF" ]; then
    c_ok "già aggiornata: $CONF_FILE"
  else
    if [ -f "$CONF_FILE" ]; then
      cp -p "$CONF_FILE" "$CONF_FILE.bak.$TS"
      c_info "backup: $CONF_FILE.bak.$TS"
    fi
    printf '%s\n' "$NEW_CONF" > "$CONF_FILE"
    c_info "scritto: $CONF_FILE"

    if ! apache2ctl configtest >"/tmp/gallery_configtest.$$" 2>&1; then
      c_err "configtest FALLITO:"
      sed 's/^/      /' "/tmp/gallery_configtest.$$" >&2
      rm -f "/tmp/gallery_configtest.$$"
      if [ -f "$CONF_FILE.bak.$TS" ]; then
        cp -p "$CONF_FILE.bak.$TS" "$CONF_FILE"
        c_info "backup ripristinato: $CONF_FILE"
      else
        rm -f "$CONF_FILE"
        c_info "file rimosso (non esisteva prima)"
      fi
      exit 1
    fi
    rm -f "/tmp/gallery_configtest.$$"
    c_ok "configtest: Syntax OK"

    # assicura che la conf sia abilitata (symlink in conf-enabled/)
    if [ ! -e "$CONF_LINK" ] && command -v a2enconf >/dev/null; then
      a2enconf gallery >/dev/null && c_info "a2enconf gallery"
    fi

    if command -v systemctl >/dev/null; then systemctl reload apache2
    else service apache2 reload; fi
    c_ok "apache2 ricaricato"
  fi
fi

# =========================================================== 2) secret.php
step 2 "API token — gallery/secret.php"
have_token=0
if [ -f "$SECRET_FILE" ]; then
  if php -r '$s=@include $argv[1]; exit(is_array($s) && !empty($s["API_TOKEN"]) && strlen($s["API_TOKEN"])>=16 ? 0 : 1);' "$SECRET_FILE"; then
    have_token=1
  fi
fi
if [ "$have_token" -eq 1 ]; then
  c_ok "secret.php già presente con un token valido — lasciato invariato"
else
  NEWTOK="$(php -r 'echo bin2hex(random_bytes(24));')"
  ( umask 027; cat > "$SECRET_FILE" <<PHP
<?php
// generato da apply_root_tasks.sh il $TS — non committare, non condividere
return [
    'API_TOKEN'     => '$NEWTOK',
    'ALLOWED_HOSTS' => ['$HOST'],
];
PHP
  )
  chown root:"$WEB_GROUP" "$SECRET_FILE"
  chmod 640 "$SECRET_FILE"
  c_ok "creato $SECRET_FILE (root:$WEB_GROUP, 640)"
  printf '  \033[1mAPI_TOKEN = %s\033[0m\n' "$NEWTOK"
  c_warn "annota il token adesso: non verrà più mostrato."
fi

# =========================================================== 3) htpasswd
step 3 "Password Basic Auth (utente admin)"
if [ "$ASSUME_YES" -eq 1 ]; then
  c_info "saltato (--yes). Per cambiarla:  sudo htpasswd $HTPASSWD_FILE admin"
else
  read -r -p "  Reimpostare adesso la password di 'admin'? [y/N] " ans || ans=""
  case "$ans" in
    y|Y|s|S)
      [ -f "$HTPASSWD_FILE" ] || : > "$HTPASSWD_FILE"
      htpasswd "$HTPASSWD_FILE" admin
      chown root:"$WEB_GROUP" "$HTPASSWD_FILE"; chmod 640 "$HTPASSWD_FILE"
      c_ok "password aggiornata"
      ;;
    *) c_info "lasciata invariata" ;;
  esac
fi

# =========================================================== 4) migrate
step 4 "Schema + FTS5 — php migrate.php (come $WEB_USER)"
if [ -f "$GALLERY_DIR/migrate.php" ]; then
  if ( cd "$GALLERY_DIR" && sudo -u "$WEB_USER" php migrate.php ) >"/tmp/gallery_migrate.$$" 2>&1; then
    sed 's/^/  /' "/tmp/gallery_migrate.$$"
    c_ok "migrazione completata"
  else
    sed 's/^/  /' "/tmp/gallery_migrate.$$" >&2
    c_warn "migrate.php ha restituito un errore (vedi sopra) — proseguo"
  fi
  rm -f "/tmp/gallery_migrate.$$"
  # eventuali file WAL creati devono restare del web user, ovunque stia il DB
  DBDIR="$(dirname "$DB_PATH")"
  for f in "$(basename "$DB_PATH")" "$(basename "$DB_PATH")-wal" "$(basename "$DB_PATH")-shm"; do
    [ -e "$DBDIR/$f" ] && chown "$WEB_USER":"$WEB_GROUP" "$DBDIR/$f"
  done
else
  c_warn "migrate.php non trovato — salto"
fi

# =========================================================== 5) verifica
if [ "$SKIP_VERIFY" -eq 1 ]; then
  step 5 "Verifica — SALTATA (--skip-verify)"
else
  step 5 "Verifica su https://$HOST/gallery/  (loopback su 127.0.0.1)"
  RES=(--resolve "$HOST:443:127.0.0.1" --resolve "$HOST:80:127.0.0.1")
  CURL=(curl -sS -k --max-time 15 "${RES[@]}" -o /dev/null -w '%{http_code}')
  SHORT="$(sqlite3 "file:$DB_PATH?immutable=1" 'SELECT short FROM images ORDER BY created_at DESC LIMIT 1' 2>/dev/null || true)"
  fail=0

  code="$("${CURL[@]}" "https://$HOST/gallery/" || true)"
  [ "$code" = "401" ] && c_ok "galleria protetta (HTTP $code)" || { c_err "galleria: atteso 401, ricevuto $code"; fail=1; }

  code="$("${CURL[@]}" "https://$HOST/gallery/admin/" || true)"
  [ "$code" = "401" ] && c_ok "admin protetto (HTTP $code)" || { c_err "admin: atteso 401, ricevuto $code"; fail=1; }

  if [ -n "$SHORT" ]; then
    read -r code ctype < <(curl -sS -k --max-time 15 "${RES[@]}" -o /dev/null \
        -w '%{http_code} %{content_type}' "https://$HOST/gallery/i/$SHORT" || echo "000 -")
    if [ "$code" = "200" ] && printf '%s' "$ctype" | grep -qi '^image/'; then
      c_ok "immagine pubblica senza credenziali (HTTP $code, $ctype)"
    else
      c_err "immagine /i/$SHORT: atteso 200 image/*, ricevuto $code $ctype"; fail=1
    fi
  else
    c_warn "nessuno short nel DB: salto il test hotlink"
  fi

  echo
  if [ "$fail" -eq 0 ]; then c_ok "TUTTO OK"; else
    c_err "Alcuni controlli sono falliti — vedi sopra."
    c_info "se il passo 1 è stato saltato, gli URL immagine sono ancora sotto auth (normale)."
    exit 1
  fi
fi

echo
c_ok "Fatto."
