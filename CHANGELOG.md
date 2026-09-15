# Changelog

## 2026-09-15 (2) — Ingressi robusti contro parametri di tipo inatteso

Un parametro passato come array — `?q[]=a` invece di `?q=a` — faceva arrivare
un array a `trim()`, con `TypeError` non gestito e risposta 500. Non era
sfruttabile per ottenere dati (nessuna informazione trapela con
`display_errors` disattivato) ma era un crash raggiungibile che sporcava i log.

Tre aiutanti in `config.php` garantiscono ora il tipo atteso a monte:

| Funzione | Garanzia |
|---|---|
| `get_str($k)` / `post_str($k)` | sempre `string`; array, bool, `null` → valore di default |
| `get_int($k, $def, $min, $max)` | sempre `int`, già limitato all'intervallo |

Applicati a `index.php`, `admin/index.php` (tutte le azioni POST comprese),
`delete.php`, `i.php`, `upload.php`, `api/upload.php`. L'unico accesso diretto
rimasto è un `isset($_GET['f'])`, che non converte tipi.

Verificato: `?q[]=` `?f[]=` `?p[]=` `?ok[]=` e loro combinazioni rispondono
200; `delete.php?c[]=&k[]=` risponde 400 invece di 403 con warning;
`i.php?c[]=` risponde 404 pulito. Zero warning nel log. Regressione superata su
ricerca FTS, token `folder:`, paginazione, upload con e senza metadati (i
`NULL` restano `NULL`), tutte le azioni di amministrazione, e CSRF che respinge
anche `csrf[]=x` con 419.

`get_int('p', 1, 1, 100000)` limita anche il numero di pagina: mitiga in parte
il rilievo sull'offset senza tetto, senza chiuderlo — manca il vincolo
all'ultima pagina realmente esistente.

## 2026-09-15 — Audit: due difetti critici corretti

Revisione avversariale integrale del codice: 13 rilievi complessivi, i due
critici corretti e verificati.

### Perdita di dati su copia + elimina

L'azione «Copia» duplica la riga nel database ma **non** il file su disco: più
short-code finiscono per puntare allo stesso `filename`. L'eliminazione faceva
`unlink()` senza verificare se altre righe referenziassero ancora quel file,
quindi cancellare una copia distruggeva anche l'originale — riga ancora in
elenco, immagine 404, file perduto in modo irreversibile.

Nuova funzione `delete_image()` in `config.php`: cancella la riga, poi rimuove
i file solo quando nessun'altra riga li referenzia. Chiamata sia da
`admin/index.php` sia da `delete.php`, che prima contenevano due copie
divergenti della stessa logica.

### Immagine-bomba: esaurimento memoria dell'intera macchina

L'upload limitava la dimensione del file (20 MB) ma non le dimensioni in pixel,
e `imagecreatefromstring()` decodificava prima di qualunque verifica. Un PNG di
poche centinaia di KB che dichiara decine di migliaia di pixel per lato viene
espanso da GD fino a saturare la RAM: su un server condiviso l'OOM killer non
si porta via solo questa applicazione.

Nuovo controllo `$MAX_PIXELS` (40 megapixel di default) in `upload.php`: la
verifica usa `getimagesize()`, che legge solo l'intestazione, e avviene sul file
temporaneo — un file ostile non raggiunge mai `uploads/`.

> **Nota per chi implementa difese analoghe.** Alzare o abbassare
> `memory_limit` non risolve: libgd alloca fuori dalla contabilità di memoria di
> PHP, quindi il limite non la vincola. Misurato: un PNG di 107 KB che dichiara
> 30000×30000 ha portato il processo a **1754 MB di RSS** con `memory_limit`
> impostato a 256M. Il controllo sui pixel prima della decodifica non è la
> difesa preferibile, è l'unica. Il `memory_limit` impostato in `config.php`
> resta utile per le allocazioni lato PHP e il commento ne dichiara i limiti.

Verificato: eliminare una copia lascia intatto l'originale; eliminare l'ultima
riga rimuove file e miniatura senza orfani; chiave di cancellazione errata 403;
bomba palette 900 MP e bomba truecolor 625 MP entrambe respinte con 413;
immagine legittima 800×600 accettata con dimensioni corrette.

### Rilievi minori ancora aperti

Errore 500 su parametri passati come array (`?q[]=`); permessi del docroot;
`Strict-Transport-Security` assente nella configurazione di esempio; regola
sui file nascosti che non copre le cartelle nascoste; file orfano se
l'inserimento nel database fallisce dopo il salvataggio; `theme_foot()` che
stampa il parametro senza escape; numero di pagina senza tetto massimo.

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
