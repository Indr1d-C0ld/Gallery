# Changelog

## 2026-09-11 (3) — Mobile & tablet

Un unico blocco `@media (max-width:768px), (pointer:coarse)` in `_theme.php`,
solo additivo — attivo solo su schermi ≤768px o puntatori touch, il desktop a
mouse resta invariato:

- niente zoom automatico di iOS Safari sui campi di testo (font-size 16px
  solo lì dentro — sotto i 16px Safari ingrandisce la pagina al focus);
- bersagli tattili più larghi: tab, pulsanti, toggle tema, azioni admin,
  controlli del lightbox passano da ~26-30px a 34-56px di altezza;
- niente hover "appiccicato" sui provini dopo un tap;
- a 768px la griglia mostra 2 colonne, la tabella admin entra senza
  scorrimento.

Corretti anche due bug di layout non solo mobile:
`<figure>` ereditava il margine di default del browser (16px 40px) —
riduceva silenziosamente ogni provino di 80px anche su desktop; la tabella
admin non aveva un contenitore scorrevole e sotto ~640px faceva scorrere
l'intera pagina invece della sola tabella (ora in `.tbl-scroll`).

## 2026-09-11 (2) — Toggle tema chiaro/scuro

Selettore manuale nella barra di galleria e admin, con precedenza corretta
su tre livelli: preferenza salvata (`localStorage`) > `prefers-color-scheme`
del sistema > default chiaro. Applicato prima del primo paint (script inline
nell'`<head>`) per evitare il flash bianco/nero al caricamento.

## 2026-09-11 (1) — Tema scuro automatico + fix lightbox

- Variante "camera oscura" via `@media (prefers-color-scheme:dark)`: solo
  ridefinizione di token CSS (l'architettura era già tutta a variabili).
  Contrasto verificato: corpo 14:1, tab 10:1, pulsante eliminazione 5.6:1.
- **Fix lightbox**: la freccia "precedente" occupava tutta la larghezza
  dello schermo — una regola generica sui pulsanti del lightbox imponeva
  `right` anche ai controlli di navigazione, sommato al loro `left`. Ora ESC
  e le frecce si posizionano in modo indipendente; nascoste se l'album ha
  una sola immagine.

## 2026-09-10 — Hardening & tema "Contact Sheet"

**Sicurezza**
- CSRF (token di sessione, `SameSite=Strict`) su upload e azioni admin.
- `require_login()` come difesa in profondità lato PHP, oltre all'Auth
  Basic di Apache.
- API token letto da `secret.php`/env; l'endpoint si autodisabilita (503)
  finché non è impostato un token valido — niente più placeholder in chiaro.
- Allowlist esplicita per l'Host header (niente più cache/link poisoning).
- `.htaccess` che nega `.db/.sql/.md/.sh`, `secret.php`, `config.php`;
  `uploads/`/`thumbs/` non eseguono più PHP.
- Header di risposta: `nosniff`, `Referrer-Policy`, `X-Frame-Options`, CSP
  sulle pagine applicative; `Cross-Origin-Resource-Policy` sull'endpoint
  immagini per l'hotlink.
- Script di manutenzione (`init_db.php`, `regen_thumbs.php`) resi CLI-only.
- PDO: WAL, `busy_timeout`, `foreign_keys`.

**Bug corretti**
- La ricerca full-text andava sempre in errore 500: le query usavano
  l'alias di tabella con `MATCH`, non ammesso da SQLite ≥3.46 — corretto
  usando il nome reale della tabella FTS5.
- La home mostrava di default solo le immagini senza album — ora il
  default è "tutti".
- `schema.sql` non era allineato al database reale (mancava `folder`) —
  ora sincronizzato, più `migrate.php` idempotente.
- `alt` delle immagini era sempre vuoto.

**Tema "Contact Sheet"**
- Interfaccia riscritta in `_theme.php`: nessun asset o font remoto.
- Provini con bordo tipo stampa, lightbox integrato (frecce, ESC, tasti
  freccia), pulsanti "copia" per URL/thumbnail/Markdown/BBCode,
  paginazione.

## apply_root_tasks.sh

Script di provisioning idempotente: installa `gallery.conf` (con backup +
`configtest` + rollback automatico), genera l'API token, lancia
`migrate.php` come utente web, verifica via `curl` che la galleria sia
protetta e le immagini pubbliche.
