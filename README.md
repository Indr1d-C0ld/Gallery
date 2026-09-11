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
| `index.php` | galleria: griglia "contact sheet", album, ricerca, lightbox, upload |
| `admin/index.php` | gestione: modifica metadati, sposta/copia tra album, rigenera thumbnail, elimina |
| `upload.php` / `api/upload.php` | upload da form (sessione + CSRF) e via API (token) |
| `i.php` | consegna immagine/thumbnail per short-code, con ETag/Cache-Control |
| `delete.php` | elimina via chiave di cancellazione (capability, non richiede login) |
| `_theme.php` | tema condiviso: CSS a token (chiaro/scuro), lightbox, copia-negli-appunti |
| `migrate.php` | migrazione idempotente: schema, colonna `folder`, indici, FTS5 |
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
```

`apply_root_tasks.sh` installa la conf Apache (con backup + `configtest` +
rollback automatico se fallisce), genera un `API_TOKEN` forte se manca,
lancia `migrate.php` come utente web e verifica con `curl` che la galleria
sia protetta e le immagini pubbliche.

## Requisiti

PHP 8.1+ con `pdo_sqlite`, `gd`, `fileinfo`; Apache con `mod_rewrite` e
`mod_headers`; SQLite compilato con FTS5 (opzionale — senza, la ricerca
ricade su `LIKE`).

## Licenza

GPL-3.0 — vedi [`LICENSE`](LICENSE).
