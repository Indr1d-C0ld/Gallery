<?php
/* =========================================================================
 * Gallery – statistiche d'uso dai log di Apache (Tranche 4)
 * ---------------------------------------------------------------------------
 * Due meta':
 *  - LETTURA DEI LOG: stats_update.php, ogni notte dal crontab del
 *    proprietario del codice (il gruppo adm gli fa leggere i log), conta ogni richiesta
 *    di un'immagine per giorno, codice e provenienza e scrive stats/stats.db.
 *    Nessuna scrittura nel percorso pubblico: i.php non sa nulla di questo.
 *  - CONSULTAZIONE: il pannello admin (www-data) apre stats.db in sola
 *    lettura. Se il file manca o non si legge, il pannello funziona come
 *    prima, solo senza dati d'uso.
 *
 * Non si conservano indirizzi IP ne' user agent: solo conteggi. Dalle pagine
 * di provenienza si tolgono i parametri che possono contenere sessioni o
 * chiavi (sid, token, ...).
 *
 * Provenienze (colonna src):
 *   interno   pagine della galleria, oppure tu autenticato senza un'altra
 *             pagina di provenienza: il log registra l'utente anche sulle
 *             immagini pubbliche, perche' il browser manda le credenziali a
 *             tutto /gallery/
 *   pagina    una pagina web (ref = host/percorso?query ripulita), anche
 *             quando a guardarla sei tu: e' li' che l'immagine e' incollata
 *   diretto   un browser senza pagina di provenienza: link aperto da un'app
 *             o da un'email, pagina con una Referrer-Policy restrittiva
 *   servizio  anteprime e servizi che la scaricano per qualcuno (ref = nome):
 *             Telegram, WhatsApp, Discord, Gmail, lettori RSS...
 *   bot       motori di ricerca, crawler, strumenti (curl, ...)
 * "In uso" = pagina + diretto + servizio, negli ultimi USAGE_DAYS giorni.
 * ========================================================================= */

const USAGE_DAYS        = 30;     // finestra di "in uso": avvisi, filtro, cruscotto
const STATS_STALE_HOURS = 36;     // oltre: il job notturno non sta girando
const STATS_KEEP_DAYS   = 800;    // storico conservato nel file
const USAGE_SRC         = ['pagina', 'diretto', 'servizio'];
const STATS_SRC_LABEL   = [
  'pagina'   => 'pagine web',
  'diretto'  => 'senza provenienza',
  'servizio' => 'anteprime e servizi',
  'interno'  => 'galleria e pannello (tu)',
  'bot'      => 'bot e strumenti',
];

function stats_path(): string {
  return (string) ($GLOBALS['STATS_DB'] ?? (__DIR__ . '/stats/stats.db'));
}

/* Host della galleria stessa (ALLOWED_HOSTS), in minuscolo. */
function stats_own_hosts(): array {
  return array_values(array_unique(array_map('strtolower', (array) ($GLOBALS['ALLOWED_HOSTS'] ?? []))));
}

/* ---------------------------------------------------------------------------
 * Lettura dei log (stats_update.php; qui per poterla provare sul banco)
 * ------------------------------------------------------------------------- */

const STATS_MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                      'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

/* Sottostringa dello user agent (minuscolo) => servizio. Si controllano
 * prima dei bot: "TelegramBot" dice che il link e' stato condiviso. */
const STATS_SERVICES = [
  'telegrambot' => 'Telegram', 'whatsapp' => 'WhatsApp', 'discordbot' => 'Discord',
  'facebookexternalhit' => 'Facebook', 'facebot' => 'Facebook', 'twitterbot' => 'X (Twitter)',
  'slackbot' => 'Slack', 'slack-imgproxy' => 'Slack', 'linkedinbot' => 'LinkedIn',
  'skypeuripreview' => 'Skype', 'viber' => 'Viber', 'redditbot' => 'Reddit', 'pinterest' => 'Pinterest',
  'mastodon' => 'Mastodon', 'pleroma' => 'Fediverso', 'misskey' => 'Fediverso', 'akkoma' => 'Fediverso',
  'googleimageproxy' => 'Gmail', 'yahoomailproxy' => 'Yahoo Mail',
  'iframely' => 'Anteprime di link', 'embedly' => 'Anteprime di link',
  'feedly' => 'Lettori RSS', 'inoreader' => 'Lettori RSS', 'newsblur' => 'Lettori RSS', 'feedbin' => 'Lettori RSS',
  'netnewswire' => 'Lettori RSS', 'freshrss' => 'Lettori RSS', 'miniflux' => 'Lettori RSS',
  'tiny tiny rss' => 'Lettori RSS', 'rss reader' => 'Lettori RSS', 'feedfetcher' => 'Lettori RSS',
  'newsflash' => 'Lettori RSS',
];

function stats_service(string $ua): ?string {
  $ua = strtolower($ua);
  foreach (STATS_SERVICES as $needle => $name) if (str_contains($ua, $needle)) return $name;
  return null;
}

/* (?<!cu)bot: "CUBOT" e' una marca di telefoni, non un bot. */
function stats_is_bot(string $ua): bool {
  $ua = trim($ua);
  if ($ua === '' || $ua === '-') return true;
  return (bool) preg_match('~(?<!cu)bot|crawl|spider|slurp|scan|monitor|uptime|curl|wget|python|go-http|java/|libwww'
    . '|httpclient|okhttp|scrapy|headless|axios|node-fetch|guzzle|^php|ruby|perl|lighthouse|pagespeed~i', $ua);
}

/* Codice dell'immagine chiesta: /gallery/i/CODICE, /gallery/t/CODICE (anche
 * con ?w= e ?v=) oppure /gallery/i.php?c=CODICE. null per tutto il resto. */
function stats_request_short(string $target): ?string {
  [$path, $query] = array_pad(explode('?', $target, 2), 2, '');
  if (preg_match('~^/gallery/[it]/([A-Za-z0-9_-]{1,64})$~', $path, $m)) return $m[1];
  if ($path === '/gallery/i.php') {
    parse_str($query, $q);
    $c = $q['c'] ?? '';
    return is_string($c) && preg_match('~^[A-Za-z0-9_-]{1,64}$~', $c) ? $c : null;
  }
  return null;
}

/* Pagina di provenienza ripulita: ['host', 'path', 'ref'], dove ref e'
 * "host/percorso?query" per http(s) e conserva lo schema per gli altri
 * (android-app://...). Senza frammento, senza i parametri che possono
 * contenere sessioni, chiavi o indirizzi email. null se assente o illeggibile. */
function stats_norm_ref(string $raw): ?array {
  $raw = trim(explode(',', $raw, 2)[0]);    // alcuni client ne mandano due: "https://a, https://b"
  if ($raw === '' || $raw === '-') return null;
  $p = parse_url($raw);
  if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return null;
  $scheme = strtolower($p['scheme']);
  $host   = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
  $path   = ($p['path'] ?? '') === '' ? '/' : $p['path'];
  $keep = [];
  foreach (explode('&', $p['query'] ?? '') as $kv) {
    if ($kv === '') continue;
    $k = strtolower(urldecode(explode('=', $kv, 2)[0]));
    if (preg_match('~^(sid|k|phpsessid|sess|session.*|.*token.*|.*key|auth.*|code|state|pass.*|pw|hash|sig.*|e?mail|utm_.*|fbclid|gclid|mc_.*|_ga)$~', $k)) continue;
    $keep[] = $kv;
  }
  $ref = ($scheme === 'http' || $scheme === 'https' ? '' : $scheme . '://') . $host . $path . ($keep ? '?' . implode('&', $keep) : '');
  return ['host' => $host, 'path' => $path, 'ref' => mb_scrub(substr($ref, 0, 200), 'UTF-8')];
}

/* Una riga del log "combined" di Apache:
 *   IP - utente [09/Oct/2026:01:14:00 +0200] "GET /gallery/i/X HTTP/1.1" 200 1234 "referer" "user agent"
 * Restituisce [giorno, codice, src, ref, ok] per le GET di un'immagine con
 * esito utile (ok=1 servita: 200, 206, 304; ok=0 non trovata: 404, 410),
 * null per tutto il resto (altre pagine, HEAD, errori, righe illeggibili). */
function stats_parse_line(string $line, array $ownHosts): ?array {
  if (strpos($line, ' /gallery/') === false) return null;            // scarto veloce
  if (!preg_match('~^\S+ \S+ (\S+) \[(\d{2})/(\w{3})/(\d{4}):[^\]]*\] "GET (\S+) [^"]*" (\d{3}) \S+ '
    . '"((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"~', $line, $m)) return null;
  [, $user, $d, $mon, $y, $target, $status, $ref, $ua] = $m;
  if (!isset(STATS_MONTHS[$mon])) return null;
  $ok = in_array($status, ['200', '206', '304'], true) ? 1 : (in_array($status, ['404', '410'], true) ? 0 : null);
  if ($ok === null) return null;
  $short = stats_request_short($target);
  if ($short === null) return null;
  $day = sprintf('%04d-%02d-%02d', $y, STATS_MONTHS[$mon], $d);

  // Apache scrive \" \\ e \xhh nei campi tra virgolette; via i caratteri di controllo
  $r = stats_norm_ref(preg_replace('~[\x00-\x1f\x7f]~', '', stripcslashes($ref)));
  if ($r !== null && in_array($r['host'], $ownHosts, true) && preg_match('~^/gallery(/|$)~', $r['path'])) {
    return [$day, $short, 'interno', '', $ok];
  }
  // Tu, autenticato: conta come "interno", tranne quando l'immagine arriva da
  // un'altra pagina (un post del forum letto da te: e' li' che e' incollata).
  if ($user !== '-') return $r !== null ? [$day, $short, 'pagina', $r['ref'], $ok] : [$day, $short, 'interno', '', $ok];
  if (($svc = stats_service($ua)) !== null) return [$day, $short, 'servizio', $svc, $ok];
  if (stats_is_bot($ua)) return [$day, $short, 'bot', '', $ok];
  if ($r === null) return [$day, $short, 'diretto', '', $ok];
  return [$day, $short, 'pagina', $r['ref'], $ok];
}

/* ---------------------------------------------------------------------------
 * Consultazione (pannello admin)
 * ------------------------------------------------------------------------- */

/* Connessione in sola lettura, null se il file manca o non e' un database
 * delle statistiche (il pannello allora va avanti senza dati d'uso). */
function stats_db(): ?PDO {
  static $pdo = false;
  if ($pdo !== false) return $pdo;
  $pdo = null;
  $path = stats_path();
  if (!is_file($path)) return null;
  try {
    $c = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $c->exec('PRAGMA busy_timeout = 2000');
    $c->query("SELECT day, short, src, ref, ok, n FROM hits LIMIT 1")->fetchAll();
    $pdo = $c;
  } catch (Throwable $e) {
    error_log('gallery: statistiche d\'uso illeggibili (' . $path . '): ' . $e->getMessage());
  }
  return $pdo;
}

function stats_meta(): array {
  static $m = null;
  if ($m === null) {
    $m = [];
    try { if ($st = stats_db()) $m = $st->query("SELECT k, v FROM meta")->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (Throwable $e) { $m = []; }
  }
  return $m;
}

/* Stato per il pannello: ok (dati leggibili), updated (unix), since (giorno
 * da cui ci sono dati), stale (ultimo aggiornamento troppo vecchio). */
function stats_state(): array {
  $ok = stats_db() !== null;
  $m = $ok ? stats_meta() : [];
  $updated = isset($m['updated_at']) ? (int) $m['updated_at'] : null;
  return ['ok' => $ok, 'exists' => is_file(stats_path()), 'updated' => $updated, 'since' => $m['since'] ?? null,
          'stale' => $ok && ($updated === null || time() - $updated > STATS_STALE_HOURS * 3600)];
}

/* Primo giorno di una finestra di $days giorni che finisce oggi. */
function stats_day_ago(int $days): string {
  return (new DateTimeImmutable('today'))->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d');
}

/* Codici alias -> codice dell'immagine (copie fuse dalla migrazione v4) e
 * token dei link a scadenza (v5): le richieste contano per l'immagine a cui
 * portano. */
function stats_aliases(): array {
  static $map = null;
  if ($map === null) {
    $map = [];
    try {
      foreach (db()->query("SELECT a.short AS a, i.short AS s FROM short_aliases a JOIN images i ON i.id = a.image_id") as $r) $map[$r['a']] = $r['s'];
      foreach (db()->query("SELECT t.token AS a, i.short AS s FROM shares t JOIN images i ON i.id = t.image_id") as $r) $map[$r['a']] = $r['s'];
    } catch (Throwable $e) { /* tabelle di una versione piu' vecchia: quello che c'e' */ }
  }
  return $map;
}

function stats_ref_label(string $ref): string {
  foreach (stats_own_hosts() as $h) {
    if (str_starts_with($ref, $h . '/')) { $ref = substr($ref, strlen($h)); break; }
  }
  return mb_strlen($ref) > 70 ? mb_substr($ref, 0, 69) . '…' : $ref;
}

function usage_label(string $src, string $ref): string {
  return match ($src) {
    'pagina'   => stats_ref_label($ref),
    'servizio' => $ref,
    default    => STATS_SRC_LABEL[$src] ?? $src,
  };
}

/* Collegamento per una pagina di provenienza http(s), null per le altre. */
function stats_ref_url(string $src, string $ref): ?string {
  if ($src !== 'pagina' || preg_match('~^[a-z][a-z0-9+.-]*://~i', $ref)) return null;
  return 'https://' . $ref;
}

/* Richieste per immagine.
 *  $days  finestra in giorni (null = tutto lo storico)
 *  $srcs  provenienze da contare (default: quelle che fanno "in uso")
 *  $ok    null = tutte, 1 = servite, 0 = non trovate (nel cestino o eliminate)
 * Risultato: [codice => ['n' => totale, 'last' => giorno, 'by' => [etichetta => n, ...] decrescente]] */
function usage_by_image(?int $days = USAGE_DAYS, array $srcs = USAGE_SRC, ?int $ok = null): array {
  static $cache = [];
  $key = json_encode([$days, $srcs, $ok]);
  if (isset($cache[$key])) return $cache[$key];
  $out = [];
  if (($st = stats_db()) && $srcs) {
    try {
      $sql = "SELECT short, src, ref, SUM(n) AS n, MAX(day) AS last FROM hits WHERE src IN (" . implode(',', array_fill(0, count($srcs), '?')) . ")";
      $args = array_values($srcs);
      if ($days !== null) { $sql .= " AND day >= ?"; $args[] = stats_day_ago($days); }
      if ($ok !== null)   { $sql .= " AND ok = ?";   $args[] = $ok; }
      $q = $st->prepare($sql . " GROUP BY short, src, ref");
      $q->execute($args);
      $alias = stats_aliases();
      foreach ($q->fetchAll() as $r) {
        $s = $alias[$r['short']] ?? $r['short'];
        $lbl = usage_label($r['src'], $r['ref']);
        $out[$s]['n'] = ($out[$s]['n'] ?? 0) + (int) $r['n'];
        $out[$s]['last'] = max($out[$s]['last'] ?? '', (string) $r['last']);
        $out[$s]['by'][$lbl] = ($out[$s]['by'][$lbl] ?? 0) + (int) $r['n'];
      }
      foreach ($out as &$u) arsort($u['by']);
      unset($u);
    } catch (Throwable $e) {
      error_log('gallery: lettura delle statistiche d\'uso: ' . $e->getMessage());
      $out = [];
    }
  }
  return $cache[$key] = $out;
}

/* "12 viste negli ultimi 30 giorni: /forum/viewtopic.php?t=12 (5), Telegram (3), …" */
function usage_text(array $u, ?int $days = USAGE_DAYS, array $word = ['vista', 'viste'], int $max = 3): string {
  $parts = [];
  foreach (array_slice($u['by'], 0, $max, true) as $l => $n) $parts[] = "$l ($n)";
  if (count($u['by']) > $max) $parts[] = '…';
  $win = $days === null ? '' : " negli ultimi $days giorni";
  return $u['n'] . ' ' . ($u['n'] === 1 ? $word[0] : $word[1]) . $win . ': ' . implode(', ', $parts);
}

/* Fra le immagini indicate, quelle in uso: [codice => testo per l'avviso].
 * Vuoto anche quando le statistiche non ci sono: senza dati non si blocca nulla. */
function in_use_notes(array $shorts, array $word = ['vista', 'viste']): array {
  $u = usage_by_image(USAGE_DAYS);
  $out = [];
  foreach (array_unique($shorts) as $s) {
    if (!empty($u[$s]['n'])) $out[$s] = usage_text($u[$s], USAGE_DAYS, $word);
  }
  return $out;
}

/* Richieste al giorno negli ultimi $days giorni, per provenienza:
 * [giorno => [src => n]], giorni senza dati a zero. */
function usage_daily(int $days): array {
  $out = [];
  for ($i = $days - 1; $i >= 0; $i--) $out[stats_day_ago($i + 1)] = array_fill_keys(array_keys(STATS_SRC_LABEL), 0);
  if (!($st = stats_db())) return $out;
  try {
    $q = $st->prepare("SELECT day, src, SUM(n) AS n FROM hits WHERE day >= ? GROUP BY day, src");
    $q->execute([stats_day_ago($days)]);
    foreach ($q->fetchAll() as $r) if (isset($out[$r['day']][$r['src']])) $out[$r['day']][$r['src']] = (int) $r['n'];
  } catch (Throwable $e) {}
  return $out;
}

/* Pagine e servizi da cui arrivano le richieste "in uso", piu' frequenti prima:
 * [['src', 'ref', 'label', 'url', 'n', 'images'], ...] */
function usage_sources(int $days, int $limit = 15): array {
  if (!($st = stats_db())) return [];
  try {
    $q = $st->prepare("SELECT src, ref, SUM(n) AS n, COUNT(DISTINCT short) AS images FROM hits
                       WHERE day >= ? AND src IN ('pagina','diretto','servizio') GROUP BY src, ref ORDER BY n DESC LIMIT " . (int) $limit);
    $q->execute([stats_day_ago($days)]);
    return array_map(fn($r) => $r + ['label' => usage_label($r['src'], $r['ref']), 'url' => stats_ref_url($r['src'], $r['ref'])], $q->fetchAll());
  } catch (Throwable $e) { return []; }
}

/* Totali per provenienza (tutte, anche galleria e bot). */
function usage_by_src(int $days): array {
  $out = array_fill_keys(array_keys(STATS_SRC_LABEL), 0);
  if (!($st = stats_db())) return $out;
  try {
    $q = $st->prepare("SELECT src, SUM(n) AS n FROM hits WHERE day >= ? GROUP BY src");
    $q->execute([stats_day_ago($days)]);
    foreach ($q->fetchAll() as $r) if (isset($out[$r['src']])) $out[$r['src']] = (int) $r['n'];
  } catch (Throwable $e) {}
  return $out;
}
