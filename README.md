# Gallery

Image host personale in PHP puro + SQLite: upload con thumbnail automatica,
album, ricerca full-text (FTS5), snippet Markdown/BBCode pronti per i forum,
pannello di amministrazione, e un tema "provini fotografici / contact sheet"
con supporto a chiaro/scuro (automatico o a scelta manuale).

Nessun framework, nessun database server, nessuna build. Pensato per un
singolo utente dietro HTTP Basic Auth, con le sole immagini pubblicamente
raggiungibili (hotlink) per poterle incollare su forum/siti esterni.

## Componenti

| Percorso | Ruolo |
|---|---|
| `index.php` | galleria: griglia "contact sheet", album (con panoramica e copertine), etichette, ricerca, lightbox, caricamento (più file, incolla, trascina) |
| `admin/index.php` | pannello: foglio di lavoro con azioni su più immagini e uso di ogni immagine, album (descrizione, copertina, ordine, rinomina/unisci), etichette, cruscotto, cestino; avviso prima di eliminare un'immagine ancora in uso |
| `upload.php` / `api/upload.php` | upload da form (sessione + CSRF) e via API (token); posizione GPS tolta, doppioni riconosciuti |
| `i.php` | consegna immagine, miniatura (`/t/`) e versioni ridotte (`?w=`), con ETag/Cache-Control |
| `delete.php` | sposta nel cestino via chiave di cancellazione (capability, non richiede login) |
| `_images.php` | pipeline unica delle immagini: miniature, versioni ridotte, orientamento EXIF, rimozione GPS, snippet |
| `_archive.php` | album, etichette, cestino, ricerca (FTS5 o LIKE) |
| `stats_update.php` | job notturno (cron): legge i log di accesso di Apache e aggiorna le statistiche d'uso in `stats/stats.db` |
| `_stats.php` | statistiche d'uso: lettura delle righe di log, provenienze (pagine, app, servizi, bot), consultazione per il pannello |
| `_migrations.php` | migrazioni dello schema, applicate dall'app alla prima richiesta |
| `_theme.php` | tema condiviso: CSS a token (chiaro/scuro), lightbox, formati degli snippet |
| `migrate.php` | stato delle migrazioni (`--status`) e applicazione da riga di comando |
| `bench/` | banco di prova isolato (`run.sh`, `--serve` per il browser) e controllo dell'installazione (`live_check.sh`) |
| `apply_root_tasks.sh` | installa la conf Apache, genera l'API token, lancia `migrate.php`, verifica |

## Sicurezza

- Tutta l'app dietro **HTTP Basic Auth**; solo `i.php` (e le route `/i/`, `/t/`)
  restano pubbliche per l'hotlink — vedi `deploy/gallery.conf.sample`.
- **CSRF** su upload e azioni admin; **CSP**/`X-Frame-Options`/`nosniff` sulle
  pagine applicative; `uploads/` e `thumbs/` non eseguono PHP.
- MIME validato via `finfo` (whitelist jpeg/png/gif/webp — niente SVG);
  short-code e chiave di cancellazione random, confronto con `hash_equals`.
- L'API (`api/upload.php`) resta disattivata (503) finché non imposti un
  token valido in `secret.php`.

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/Gallery.git
cd Gallery

# 1. Configurazione
cp config.sample.php config.php
cp secret.sample.php secret.php     # opzionale: token API, host ammessi
$EDITOR secret.php

# 2. Database
mkdir -p uploads thumbs
php migrate.php

# 3. Apache — vedi deploy/gallery.conf.sample, poi:
sudo bash apply_root_tasks.sh --host tuo-dominio.tld

# 4. Statistiche d'uso (facoltative): il server web le legge, non le scrive
chgrp www-data stats && chmod 2750 stats
crontab -e    # utente del gruppo adm, che legge i log di Apache:
# 50 1 * * * umask 027; /usr/bin/php /percorso/gallery/stats_update.php --quiet >> /percorso/gallery/stats/update.log 2>&1
```

`apply_root_tasks.sh` installa la conf Apache (con backup + `configtest` +
rollback automatico se fallisce), genera un `API_TOKEN` forte se manca,
lancia `migrate.php` come utente web e verifica con `curl` che la galleria
sia protetta e le immagini pubbliche.

Le statistiche d'uso vengono dai log di Apache, letti di notte da
`stats_update.php`: nessuna scrittura nel percorso pubblico delle immagini,
nessun indirizzo IP conservato. Senza il job il pannello funziona lo stesso,
solo senza dati d'uso.

## Requisiti

PHP 8.1+ con `pdo_sqlite`, `gd`, `fileinfo`; Apache con `mod_rewrite` e
`mod_headers`; SQLite compilato con FTS5 (opzionale — senza, la ricerca
ricade su `LIKE`).

## Licenza

GPL-3.0 — vedi [`LICENSE`](LICENSE).
