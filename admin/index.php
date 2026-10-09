<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../_theme.php";
require_once __DIR__ . "/../_images.php";
require_once __DIR__ . "/../_archive.php";
require_once __DIR__ . "/../_stats.php";

require_login();
csrf_token();

/* Viste: foglio di lavoro (default), album, etichette, cruscotto, cestino */
$VIEWS = ['' => 'Foglio di lavoro', 'album' => 'Album', 'etichette' => 'Etichette', 'cruscotto' => 'Cruscotto', 'cestino' => 'Cestino'];
$view  = get_str('v');
if (!isset($VIEWS[$view])) $view = '';

function flash(string $msg): void { $_SESSION['flash'] = $msg; }
/* "1 immagine spostata" / "3 immagini spostate" */
function imgs(int $n, string $one, string $many): string { return $n === 1 ? "1 immagine $one" : "$n immagini $many"; }
function back(array $keep): void {
  header("Location: index.php?" . http_build_query(array_filter($keep, fn($v) => $v !== '' && $v !== null)), true, 303);
  exit;
}
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

/* Avviso prima di eliminare (Tranche 4): un'azione su immagini ancora in uso
 * (viste da pagine, app o servizi negli ultimi USAGE_DAYS giorni, secondo i
 * log) parte solo con in_use_ok=1, che il pannello mette dopo la conferma.
 * Senza, non cambia nulla e il pannello mostra l'elenco con un pulsante per
 * confermare: vale anche senza JavaScript. Senza statistiche non blocca nulla. */
function hold_if_in_use(array $shorts, array $fields, string $button, array $word = ['vista', 'viste']): bool {
  if (post_str('in_use_ok') === '1') return false;
  $notes = in_use_notes($shorts, $word);
  if (!$notes) return false;
  $_SESSION['hold'] = ['notes' => $notes, 'fields' => $fields, 'button' => $button];
  return true;
}

$keep = ['v' => $view, 'q' => get_str('q'), 'tag' => get_str('tag'), 'uso' => get_str('uso'),
         'p' => get_int('p', 1, 1, 100000) > 1 ? get_int('p', 1, 1, 100000) : ''];

/* =====================  POST: azioni  ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act   = post_str('act');
  $short = post_str('short');
  $n = 0;

  if ($act === 'meta') {
    $q = db()->prepare("SELECT id FROM images WHERE short=?");
    $q->execute([$short]);
    if ($id = $q->fetchColumn()) {
      db()->prepare("UPDATE images SET title=?, alt=?, folder=? WHERE id=?")
          ->execute([post_str('title'), post_str('alt'), norm_folder(post_str('folder')), $id]);
      if (array_key_exists('tags', $_POST)) image_set_tags((int) $id, parse_tags(post_str('tags')));
    }

  } elseif ($act === 'delete') {                  // nel cestino, non definitivo
    if (hold_if_in_use([$short], ['act' => 'delete', 'short' => $short], 'Sposta comunque nel cestino')) back($keep);
    $n = trash_images([$short]);
    flash($n ? "Spostata nel cestino: $short (ripristinabile per " . TRASH_DAYS . " giorni)" : "Nessuna immagine spostata");

  } elseif ($act === 'retthumb') {
    $q = db()->prepare("SELECT filename,mime FROM images WHERE short=?");
    $q->execute([$short]);
    if ($r = $q->fetch()) {
      make_thumb(upload_path($r['filename']), thumb_path($r['filename']), $r['mime']);
    }

  } elseif ($act === 'bulk') {
    $ids = post_list('ids');
    $val = post_str('value');
    switch (post_str('op')) {
      case 'move':
        if ($ids) {
          $st = db()->prepare("UPDATE images SET folder=? WHERE short IN (" . in_list($ids) . ")");
          $st->execute(array_merge([norm_folder($val)], $ids));
          $n = $st->rowCount();
        }
        $dest = norm_folder($val);
        flash(imgs($n, 'spostata', 'spostate') . " in «" . ($dest === '' ? 'senza album' : $dest) . "»");
        break;
      case 'tag':   $n = images_tag($ids, $val, true);  flash("Etichetta «" . norm_tag($val) . "» aggiunta a " . trim(imgs($n, '', ''))); break;
      case 'untag': $n = images_tag($ids, $val, false); flash("Etichetta «" . norm_tag($val) . "» tolta da " . trim(imgs($n, '', ''))); break;
      case 'trash':
        if (hold_if_in_use($ids, ['act' => 'bulk', 'op' => 'trash', 'ids' => $ids], 'Sposta comunque nel cestino')) back($keep);
        $n = trash_images($ids); flash(imgs($n, 'spostata', 'spostate') . " nel cestino"); break;
      default:      flash("Azione sconosciuta");
    }

  } elseif ($act === 'restore') {
    $n = restore_images(post_list('ids') ?: [$short]);
    flash(imgs($n, 'ripristinata', 'ripristinate'));

  } elseif ($act === 'purge') {
    $ids = post_list('ids') ?: [$short];
    if (hold_if_in_use($ids, ['act' => 'purge', 'ids' => $ids], 'Elimina comunque per sempre', ['richiesta', 'richieste'])) back($keep);
    $n = purge_images($ids);
    flash(imgs($n, 'eliminata', 'eliminate') . " definitivamente");

  } elseif ($act === 'purge_all') {
    $all = db()->query("SELECT short FROM images WHERE deleted_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    if (hold_if_in_use($all, ['act' => 'purge_all'], 'Svuota comunque il cestino', ['richiesta', 'richieste'])) back($keep);
    $n = purge_trash();
    flash("Cestino svuotato: " . imgs($n, 'eliminata', 'eliminate') . " definitivamente");

  } elseif ($act === 'album_meta') {
    album_save_meta(post_str('name'), post_str('description'), post_str('cover'), post_str('position'));
    flash("Album «" . post_str('name') . "» salvato");

  } elseif ($act === 'album_rename') {
    $from = post_str('from'); $to = norm_folder(post_str('to'));
    $res = album_rename($from, $to);
    flash($res === 'merged' ? "Album «{$from}» unito a «{$to}»"
        : ($res === 'renamed' ? ($to === '' ? "Le immagini di «{$from}» ora sono senza album" : "Album «{$from}» rinominato in «{$to}»")
        : "Nessun cambiamento"));

  } elseif ($act === 'tag_rename') {
    $from = post_str('from'); $res = tag_rename($from, post_str('to'));
    flash(['renamed' => "Etichetta rinominata", 'merged' => "Etichette unite", 'deleted' => "Etichetta «{$from}» eliminata", 'none' => "Etichetta non trovata"][$res]);
  }

  back($keep);
}

/* =====================  GET  ===================== */
$stats  = stats_state();
$use30  = $stats['ok'] ? usage_by_image(USAGE_DAYS) : [];      // in uso: pagine, app, servizi negli ultimi 30 giorni
$useAll = $stats['ok'] ? usage_by_image(null) : [];            // da quando ci sono le statistiche
$since  = $stats['since'] ? date('d/m/Y', strtotime($stats['since'])) : '';

// cestino: oltre TRASH_DAYS giorni l'eliminazione diventa definitiva, tranne
// per le immagini ancora richieste (un post che le mostra rotte): restano
// finche' non le elimini tu, segnalate nel cestino
$auto = purge_trash(time() - TRASH_DAYS * 86400, array_map('strval', array_keys($use30)));
$flash = $_SESSION['flash'] ?? '';
$hold  = $_SESSION['hold'] ?? null;
unset($_SESSION['flash'], $_SESSION['hold']);
if ($auto) $flash = trim($flash . " · Eliminate definitivamente: " . imgs($auto, 'rimasta', 'rimaste') . " nel cestino oltre " . TRASH_DAYS . " giorni", ' ·');

$q    = substr(preg_replace('~\s+~', ' ', trim(get_str('q'))), 0, 80);
$tag  = norm_tag(get_str('tag'));
$uso  = $stats['ok'] && in_array(get_str('uso'), ['in', 'mai'], true) ? get_str('uso') : '';
$page = get_int('p', 1, 1, 100000);
$B    = $BASE_URL;
$ntrash = trash_count();
$albums = album_list();
$albumNames = array_values(array_filter(array_column($albums, 'name'), fn($n) => $n !== ''));

if ($view === '' || $view === 'cestino') {
  $opt = ['q' => $q, 'tag' => $tag, 'page' => $page, 'per' => 40, 'trash' => $view === 'cestino'];
  if ($view === '' && $uso === 'in')  $opt['only']   = array_map('strval', array_keys($use30));
  if ($view === '' && $uso === 'mai') $opt['except'] = array_map('strval', array_keys($useAll));
  $res = gallery_search($opt);
  $rows = $res['rows']; $total = $res['total']; $page = $res['page']; $pages = $res['pages'];
  $sub = $view === 'cestino' ? "$total nel cestino" : "$total immagini";
  $inUse = in_use_notes(array_column($rows, 'short'), $view === 'cestino' ? ['richiesta', 'richieste'] : ['vista', 'viste']);
} elseif ($view === 'album') {
  $sub = count($albumNames) . ' album';
} elseif ($view === 'cruscotto') {
  $sub = 'cruscotto';
} else {
  $tags = tag_list();
  $sub = count($tags) . ' etichette';
}

/* Miniatura come data: URI (per il cestino: /t/ non serve le immagini cestinate) */
function inline_thumb(array $r): string {
  $f = thumb_path($r['filename']);
  if (!is_file($f) || filesize($f) > 200000) return '';
  return 'data:' . $r['mime'] . ';base64,' . base64_encode((string) file_get_contents($f));
}
function view_url(array $p): string { return 'index.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null)); }
function mb(int|float $bytes): string {
  if ($bytes >= 1048576 * 10) return round($bytes / 1048576) . ' MB';
  if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '') . ' MB';
  return ($bytes > 0 ? max(1, round($bytes / 1024)) : 0) . ' KB';
}
function pct(int|float $n, int|float $max): string { return $max > 0 ? round(100 * $n / $max, 1) . '%' : '0%'; }

/* Uso di un'immagine, sotto il codice nel foglio di lavoro */
function use_badge(string $short, array $use30, array $useAll, string $since): string {
  if (!empty($use30[$short])) {
    $u = $use30[$short];
    return '<span class="use on" title="' . h(usage_text($u, USAGE_DAYS, ['vista', 'viste'], 6)) . '">' . $u['n'] . ($u['n'] === 1 ? ' vista' : ' viste') . ' · ' . USAGE_DAYS . ' g</span>';
  }
  if (!empty($useAll[$short])) {
    return '<span class="use" title="' . h(usage_text($useAll[$short], null, ['vista', 'viste'], 6)) . '">ultima vista ' . h(date('d/m/Y', strtotime($useAll[$short]['last']))) . '</span>';
  }
  return '<span class="use off" title="Nessuna vista da pagine, app o servizi dal ' . h($since) . ', da quando ci sono le statistiche">mai vista</span>';
}

theme_head('Gallery · Admin', $sub . ' · utente ' . (current_user() ?? '?'));
?>

<nav class="tabs adminnav">
  <?php foreach ($VIEWS as $k => $label): ?>
    <a class="tab <?= $view === $k ? 'on' : '' ?>" href="<?= view_url(['v' => $k]) ?>"><?= htmlspecialchars($label) ?><?php
      if ($k === 'cestino' && $ntrash): ?><span class="n"><?= $ntrash ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<div class="bar">
  <?php if ($view === '' || $view === 'cestino'): ?>
  <form class="search" method="get" style="margin-left:0">
    <?php if ($view): ?><input type="hidden" name="v" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
    <?php if ($uso): ?><input type="hidden" name="uso" value="<?= h($uso) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="folder:Viaggi title:mare tag:forum id:abc123" style="min-width:300px">
    <button type="submit">Cerca</button>
    <?php if ($q !== '' || $tag !== ''): ?><a class="btn ghost" href="<?= view_url(['v' => $view, 'uso' => $uso]) ?>">Reset</a><?php endif; ?>
  </form>
  <?php endif; ?>
  <a class="btn ghost" href="<?= htmlspecialchars($B) ?>/">↗ galleria</a>
  <?php if ($view === ''): ?><label class="fmt">formato <select data-snip-format aria-label="Formato degli snippet"></select></label><?php endif; ?>
  <?= theme_toggle() ?>
</div>

<?php if ($flash !== ''): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if ($hold): /* azione fermata: immagini ancora in uso */ ?>
<div class="warnbox" role="alert">
  <p><b><?= count($hold['notes']) === 1 ? "Un'immagine è ancora in uso" : count($hold['notes']) . " immagini sono ancora in uso" ?></b>
    secondo i log di Apache: non è stato cambiato nulla.</p>
  <ul>
    <?php foreach ($hold['notes'] as $s => $t): ?><li><code><?= h((string) $s) ?></code> — <?= h($t) ?></li><?php endforeach; ?>
  </ul>
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($hold['fields'] as $k => $v): ?>
      <?php if (is_array($v)): foreach ($v as $x): ?><input type="hidden" name="<?= h($k) ?>[]" value="<?= h($x) ?>"><?php endforeach;
            else: ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <input type="hidden" name="in_use_ok" value="1">
    <button type="submit" class="danger"><?= h($hold['button']) ?></button>
    <a class="btn ghost" href="<?= view_url($keep) ?>">Annulla</a>
  </form>
</div>
<?php endif; ?>

<?php if ($view === '' || $view === 'cruscotto'): /* stato delle statistiche d'uso */ ?>
  <?php if (!$stats['exists']): ?>
    <p class="note">Statistiche d'uso non ancora disponibili: le prepara ogni notte <code>stats_update.php</code>
      leggendo i log di Apache. Per averle subito lancialo dalla cartella della galleria con un utente che legge i log.</p>
  <?php elseif (!$stats['ok']): ?>
    <div class="warnbox">Il file delle statistiche d'uso c'è ma non si legge (dettagli nel log degli errori di Apache):
      il pannello funziona, senza dati d'uso. Al prossimo giro <code>stats_update.php</code> lo mette da parte e lo ricostruisce dai log.</div>
  <?php elseif ($stats['stale']): ?>
    <div class="warnbox">Statistiche d'uso ferme al <?= h(date('d/m/Y H:i', (int) $stats['updated'])) ?>:
      l'aggiornamento notturno (<code>stats_update.php</code> nel crontab) non sta girando. I numeri qui sotto non comprendono i giorni successivi.</div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tag !== ''): ?><p class="note">Etichetta: <span class="chip on"><?= htmlspecialchars($tag) ?></span>
  <a href="<?= view_url(['v' => $view, 'q' => $q, 'uso' => $uso]) ?>">togli il filtro</a></p><?php endif; ?>

<datalist id="albums"><?php foreach ($albumNames as $a): ?><option value="<?= htmlspecialchars($a) ?>"><?php endforeach; ?></datalist>

<?php if ($view === ''): /* ===================== FOGLIO DI LAVORO ===================== */ ?>

<?php if ($stats['ok']):
  $visible = db()->query("SELECT short FROM images WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
  $nIn  = count(array_intersect($visible, array_map('strval', array_keys($use30))));
  $nMai = count(array_diff($visible, array_map('strval', array_keys($useAll)))); ?>
<nav class="chips" aria-label="Filtro sull'uso">
  <span class="note" style="margin:0">uso:</span>
  <a class="chip <?= $uso === '' ? 'on' : '' ?>" href="<?= view_url(['q' => $q, 'tag' => $tag]) ?>">tutte</a>
  <a class="chip <?= $uso === 'in' ? 'on' : '' ?>" href="<?= view_url(['q' => $q, 'tag' => $tag, 'uso' => 'in']) ?>"
     title="Viste da pagine web, app o servizi negli ultimi <?= USAGE_DAYS ?> giorni">in uso<span class="n"><?= $nIn ?></span></a>
  <a class="chip <?= $uso === 'mai' ? 'on' : '' ?>" href="<?= view_url(['q' => $q, 'tag' => $tag, 'uso' => 'mai']) ?>"
     title="Nessuna vista da pagine web, app o servizi dal <?= h($since) ?>, da quando ci sono le statistiche">mai viste<span class="n"><?= $nMai ?></span></a>
  <span class="note" style="margin:0">dati dal <?= h($since) ?> · <a href="<?= view_url(['v' => 'cruscotto']) ?>">cruscotto</a></span>
</nav>
<?php endif; ?>

<form method="post" id="bulk" class="bulkbar" data-bulk>
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="bulk">
  <input type="hidden" name="in_use_ok" value="0">
  <span class="bulk-n" data-bulk-count>nessuna selezionata</span>
  <select name="op" data-bulk-op aria-label="Azione sulle immagini selezionate">
    <option value="move">sposta nell'album</option>
    <option value="tag">aggiungi l'etichetta</option>
    <option value="untag">togli l'etichetta</option>
    <option value="trash">sposta nel cestino</option>
  </select>
  <input type="text" name="value" data-bulk-value list="albums" placeholder="album (vuoto: senza album)">
  <button type="submit" data-bulk-go disabled>Applica</button>
</form>

<div class="tbl-scroll">
<table class="ws">
<tr><th><input type="checkbox" data-bulk-all aria-label="Seleziona tutte"></th><th>Provino</th><th>Short / data / uso</th><th>Album · titolo · alt · etichette</th><th>Link &amp; embed</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $full  = $B . "/i/" . $r['short'];
  $tb    = $B . "/i.php?c=" . $r['short'] . "&thumb=1&v=" . thumb_version($r['filename']);
  $dim   = ($r['width'] && $r['height']) ? "{$r['width']}×{$r['height']}" : "?";
  $sh    = htmlspecialchars($r['short']);
  $note  = $inUse[$r['short']] ?? null;
?>
<tr>
  <td><input type="checkbox" name="ids[]" value="<?= $sh ?>" form="bulk" data-bulk-item aria-label="Seleziona <?= $sh ?>"<?= $note ? ' data-in-use="' . h($note) . '"' : '' ?>></td>
  <td><a href="<?= htmlspecialchars($full) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($tb) ?>" alt=""></a><br>
      <span style="color:var(--muted)"><?= $dim ?> · <?= (int)round(($r['size'] ?? 0)/1024) ?> KB</span></td>

  <td><code><?= $sh ?></code><br>
      <span style="color:var(--muted)"><?= date('Y-m-d H:i', $r['created_at']) ?></span>
      <?php if ($stats['ok']): ?><br><?= use_badge($r['short'], $use30, $useAll, $since) ?><?php endif; ?></td>

  <td>
    <form method="post" class="mini">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="meta">
      <input type="hidden" name="short" value="<?= $sh ?>">
      <input type="text" name="folder" value="<?= htmlspecialchars($r['folder']) ?>" placeholder="album" list="albums">
      <input type="text" name="title"  value="<?= htmlspecialchars($r['title'] ?? '') ?>" placeholder="titolo">
      <input type="text" name="alt"    value="<?= htmlspecialchars($r['alt'] ?? '') ?>" placeholder="alt">
      <input type="text" name="tags"   value="<?= htmlspecialchars(implode(', ', $r['tags'])) ?>" placeholder="etichette, separate da virgole">
      <button type="submit">Salva</button>
    </form>
  </td>

  <td><?= snippet_box($r, 'altri formati') ?></td>

  <td>
    <div class="act-grid">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="retthumb">
        <input type="hidden" name="short" value="<?= $sh ?>">
        <button type="submit" class="ghost">Rigenera thumb</button>
      </form>
      <form method="post"<?= $note ? ' data-confirm="' . h("«{$r['short']}» è ancora in uso: $note.\n\nSpostarla comunque nel cestino?") . '"' : '' ?>><?= csrf_field() ?>
        <input type="hidden" name="act" value="delete">
        <input type="hidden" name="short" value="<?= $sh ?>">
        <input type="hidden" name="in_use_ok" value="0">
        <button type="submit" class="danger">Cestina</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php elseif ($view === 'cestino'): /* ===================== CESTINO ===================== */ ?>

<p class="note">Le immagini nel cestino non sono più pubbliche (i loro indirizzi rispondono 404) ma i file restano:
  si possono ripristinare per <?= TRASH_DAYS ?> giorni, poi l'eliminazione diventa definitiva.
  Ricaricare la stessa immagine la ripristina con i suoi indirizzi di prima.
  <?php if ($stats['ok']): ?>Quelle ancora richieste da pagine, app o servizi non vengono eliminate in automatico.<?php endif; ?></p>
<?php if ($ntrash):
  $busyAll = in_use_notes(db()->query("SELECT short FROM images WHERE deleted_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), ['richiesta', 'richieste']);
  $msgAll = "Eliminare definitivamente tutte le $ntrash immagini del cestino? Non si torna indietro.";
  if ($busyAll) $msgAll .= "\n\nAttenzione, " . (count($busyAll) === 1 ? "una è ancora richiesta" : count($busyAll) . " sono ancora richieste") . ":\n"
    . implode("\n", array_map(fn($s, $t) => "• $s: $t", array_keys(array_slice($busyAll, 0, 8, true)), array_slice($busyAll, 0, 8, true))) . (count($busyAll) > 8 ? "\n…" : ''); ?>
<form method="post" data-confirm="<?= h($msgAll) ?>">
  <?= csrf_field() ?><input type="hidden" name="act" value="purge_all"><input type="hidden" name="in_use_ok" value="0">
  <button type="submit" class="danger">Svuota il cestino (<?= $ntrash ?>)</button>
</form>
<?php endif; ?>

<?php if (!$rows): ?><p class="note">Il cestino è vuoto.</p><?php else: ?>
<div class="tbl-scroll">
<table class="ws">
<tr><th>Provino</th><th>Codice · album · titolo</th><th>Nel cestino</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $days = TRASH_DAYS - (int) floor((time() - (int) $r['deleted_at']) / 86400);
  $sh = htmlspecialchars($r['short']); $img = inline_thumb($r);
  $note = $inUse[$r['short']] ?? null; ?>
<tr>
  <td><?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php else: ?><span class="note">(nessuna miniatura)</span><?php endif; ?></td>
  <td><code><?= $sh ?></code><br><?= htmlspecialchars($r['folder'] === '' ? 'senza album' : $r['folder']) ?>
      <?= $r['title'] ? '<br>' . htmlspecialchars($r['title']) : '' ?></td>
  <td><?= date('Y-m-d H:i', (int) $r['deleted_at']) ?><br>
      <?php if ($note && $days <= 0): ?><span class="use warn">tenuta: ancora richiesta</span>
      <?php else: ?><span style="color:var(--muted)">ancora <?= max(0, $days) ?> giorni</span><?php endif; ?>
      <?php if ($note): ?><br><span class="use warn" title="<?= h($note) ?>">ancora richiesta: <?= h($note) ?></span><?php endif; ?></td>
  <td>
    <div class="act-grid">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="restore"><input type="hidden" name="short" value="<?= $sh ?>">
        <button type="submit">Ripristina</button>
      </form>
      <form method="post" data-confirm="<?= h("Eliminare definitivamente {$r['short']}? Non si torna indietro."
          . ($note ? "\n\nAttenzione: è ancora richiesta — $note. Chi la cerca troverà un'immagine mancante." : '')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="act" value="purge"><input type="hidden" name="short" value="<?= $sh ?>">
        <input type="hidden" name="in_use_ok" value="0">
        <button type="submit" class="danger">Elimina per sempre</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php elseif ($view === 'cruscotto'): /* ===================== CRUSCOTTO ===================== */
  // archivio: spazio e caricamenti
  $vis   = db()->query("SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS b FROM images WHERE deleted_at IS NULL")->fetch();
  $tr    = db()->query("SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS b FROM images WHERE deleted_at IS NOT NULL")->fetch();
  $byAlb = db()->query("SELECT COALESCE(folder,'') AS name, COUNT(*) AS n, COALESCE(SUM(size),0) AS b FROM images
                        WHERE deleted_at IS NULL GROUP BY COALESCE(folder,'') ORDER BY b DESC")->fetchAll();
  $thumbB = 0; $derivB = 0; $derivN = 0;
  foreach (glob($THUMBS . '/*') ?: [] as $f) {
    if (!is_file($f)) continue;
    if (preg_match('~\.w\d+\.webp$~', $f)) { $derivB += filesize($f); $derivN++; } else $thumbB += filesize($f);
  }
  $months = [];
  foreach (db()->query("SELECT created_at, size FROM images") as $r) {
    $m = date('Y-m', (int) $r['created_at']);
    $months[$m]['n'] = ($months[$m]['n'] ?? 0) + 1;
    $months[$m]['b'] = ($months[$m]['b'] ?? 0) + (int) $r['size'];
  }
  if ($months) {                                  // mesi senza caricamenti a zero, fino a oggi
    ksort($months);
    for ($m = array_key_first($months); $m <= date('Y-m'); $m = date('Y-m', strtotime("$m-01 +1 month"))) $months[$m] ??= ['n' => 0, 'b' => 0];
    ksort($months);
    $months = array_slice($months, -24, null, true);
  }
  // uso
  $bySrc  = $stats['ok'] ? usage_by_src(USAGE_DAYS) : [];
  $daily  = $stats['ok'] ? usage_daily(USAGE_DAYS) : [];
  $srcs   = $stats['ok'] ? usage_sources(USAGE_DAYS, 15) : [];
  $top    = $use30;
  uasort($top, fn($a, $b) => $b['n'] <=> $a['n']);
  $top    = array_slice($top, 0, 10, true);
  $missing = $stats['ok'] ? usage_by_image(USAGE_DAYS, USAGE_SRC, 0) : [];
  uasort($missing, fn($a, $b) => $b['n'] <=> $a['n']);
  $missing = array_slice($missing, 0, 10, true);
  $info = [];                                     // righe delle immagini citate
  if ($top || $missing) {
    $want = array_values(array_unique(array_merge(array_map('strval', array_keys($top)), array_map('strval', array_keys($missing)))));
    $st = db()->prepare("SELECT short, filename, mime, title, COALESCE(folder,'') AS folder, deleted_at FROM images WHERE short IN (" . in_list($want) . ")");
    $st->execute($want);
    foreach ($st->fetchAll() as $r) $info[$r['short']] = $r;
  }
  $useTot = array_sum(array_intersect_key($bySrc, array_flip(USAGE_SRC)));
?>

<div class="dash">
  <section class="card wide">
    <h2>In breve</h2>
    <div class="kpis">
      <div class="kpi"><b><?= (int) $vis['n'] ?></b><span>immagini · <?= mb((int) $vis['b']) ?> di originali</span></div>
      <div class="kpi"><b><?= mb($thumbB + $derivB) ?></b><span>miniature (<?= mb($thumbB) ?>) e <?= $derivN ?> versioni ridotte (<?= mb($derivB) ?>)</span></div>
      <div class="kpi"><b><?= (int) $tr['n'] ?></b><span>nel cestino<?= $tr['n'] ? ' · ' . mb((int) $tr['b']) : '' ?></span></div>
      <?php if ($stats['ok']): ?>
      <div class="kpi"><b><?= $useTot ?></b><span>viste da pagine, app e servizi negli ultimi <?= USAGE_DAYS ?> giorni</span></div>
      <?php $visible = db()->query("SELECT short FROM images WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN); ?>
      <div class="kpi"><b><?= count(array_intersect($visible, array_map('strval', array_keys($use30)))) ?></b>
        <span><a href="<?= view_url(['uso' => 'in']) ?>">immagini in uso</a> ·
          <a href="<?= view_url(['uso' => 'mai']) ?>"><?= count(array_diff($visible, array_map('strval', array_keys($useAll)))) ?> mai viste</a></span></div>
      <?php endif; ?>
    </div>
    <?php if ($stats['ok']): ?>
    <p class="note">Statistiche dai log di Apache, aggiornate il <?= h(date('d/m/Y \a\l\l\e H:i', (int) $stats['updated'])) ?>, dati dal <?= h($since) ?>.
      Ogni richiesta di un'immagine (originale, ridotta o miniatura) conta una vista; non si conservano indirizzi IP.
      «In uso» esclude le richieste della galleria stessa, le tue da autenticato e i bot.</p>
    <?php endif; ?>
  </section>

  <?php if ($stats['ok']): ?>
  <section class="card wide">
    <h2>Più viste negli ultimi <?= USAGE_DAYS ?> giorni</h2>
    <?php if (!$top): ?><p class="note">Nessuna vista da pagine, app o servizi in questo periodo.</p><?php else: $max = reset($top)['n']; ?>
    <div class="tbl-scroll"><table class="dt">
      <tr><th></th><th>Immagine</th><th>Da dove</th><th class="num">Viste</th><th></th></tr>
      <?php foreach ($top as $s => $u): $s = (string) $s; $im = $info[$s] ?? null; ?>
      <tr>
        <td><?php if ($im && $im['deleted_at'] === null): ?><a href="<?= h("$B/i/$s") ?>" target="_blank" rel="noopener"><img src="<?= h("$B/i.php?c=$s&thumb=1&v=" . thumb_version($im['filename'])) ?>" alt=""></a><?php endif; ?></td>
        <td><a href="<?= view_url(['q' => "id:$s"]) ?>"><code><?= h($s) ?></code></a><br>
          <span style="color:var(--muted)"><?= $im ? h(($im['folder'] === '' ? 'senza album' : $im['folder']) . ($im['title'] ? ' · ' . $im['title'] : '')) : 'non più nell\'archivio' ?></span></td>
        <td class="lbl"><?= h(implode(', ', array_map(fn($l, $n) => "$l ($n)", array_keys(array_slice($u['by'], 0, 3, true)), array_slice($u['by'], 0, 3, true)))) . (count($u['by']) > 3 ? ', …' : '') ?></td>
        <td class="num"><?= $u['n'] ?></td>
        <td style="width:28%"><span class="hbar"><i style="width:<?= pct($u['n'], $max) ?>"></i></span></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Viste al giorno</h2>
    <?php $dmax = max(1, max(array_map(fn($d) => array_sum(array_intersect_key($d, array_flip(USAGE_SRC))), $daily))); ?>
    <div class="cols" role="img" aria-label="Viste da pagine, app e servizi negli ultimi <?= USAGE_DAYS ?> giorni">
      <?php foreach ($daily as $d => $c): $v = array_sum(array_intersect_key($c, array_flip(USAGE_SRC))); ?>
        <span class="<?= $v ? '' : 'zero' ?>" style="height:<?= pct($v, $dmax) ?>" title="<?= h(date('d/m', strtotime($d)) . ": $v viste") ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="cols-x"><span><?= h(date('d/m', strtotime(array_key_first($daily)))) ?></span><span>oggi</span></div>
    <table class="dt" style="margin-top:12px">
      <?php $smax = max(1, max($bySrc)); foreach ($bySrc as $k => $n): ?>
      <tr><td><?= h(STATS_SRC_LABEL[$k]) ?><?= in_array($k, USAGE_SRC, true) ? '' : ' <span class="note">(non conta)</span>' ?></td>
        <td class="num"><?= $n ?></td>
        <td style="width:40%"><span class="hbar <?= in_array($k, USAGE_SRC, true) ? '' : 'mut' ?>"><i style="width:<?= pct($n, $smax) ?>"></i></span></td></tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="card">
    <h2>Da dove arrivano</h2>
    <?php if (!$srcs): ?><p class="note">Nessuna provenienza in questo periodo.</p><?php else: $rmax = (int) $srcs[0]['n']; ?>
    <table class="dt">
      <tr><th>Pagina o servizio</th><th class="num">Viste</th><th class="num">Immagini</th><th></th></tr>
      <?php foreach ($srcs as $s): ?>
      <tr><td class="lbl"><?php if ($s['url']): ?><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['label']) ?></a><?php else: ?><?= h($s['label']) ?><?php endif; ?></td>
        <td class="num"><?= (int) $s['n'] ?></td><td class="num"><?= (int) $s['images'] ?></td>
        <td style="width:25%"><span class="hbar"><i style="width:<?= pct((int) $s['n'], $rmax) ?>"></i></span></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="note">«Senza provenienza»: browser che non dichiarano la pagina di origine — link aperti da app o email,
      pagine con una politica sul referrer restrittiva.</p>
    <?php endif; ?>
  </section>

  <?php if ($missing): ?>
  <section class="card wide">
    <h2>Richieste a immagini che non ci sono più</h2>
    <p class="note">Negli ultimi <?= USAGE_DAYS ?> giorni qualcuno ha chiesto queste immagini e ha ricevuto un 404: sono ancora incollate da qualche parte.</p>
    <table class="dt">
      <tr><th>Codice</th><th>Stato</th><th>Da dove</th><th class="num">Richieste</th></tr>
      <?php foreach ($missing as $s => $u): $s = (string) $s; $im = $info[$s] ?? null; ?>
      <tr><td><code><?= h($s) ?></code></td>
        <td><?= !$im ? 'eliminata' : ($im['deleted_at'] !== null ? '<a href="' . view_url(['v' => 'cestino', 'q' => "id:$s"]) . '">nel cestino</a>' : 'ripristinata') ?></td>
        <td class="lbl"><?= h(implode(', ', array_map(fn($l, $n) => "$l ($n)", array_keys(array_slice($u['by'], 0, 3, true)), array_slice($u['by'], 0, 3, true)))) ?></td>
        <td class="num"><?= $u['n'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; ?>
  <?php endif; /* stats ok */ ?>

  <section class="card">
    <h2>Spazio per album</h2>
    <?php $amax = max(1, max(array_column($byAlb, 'b') ?: [1])); ?>
    <table class="dt">
      <tr><th>Album</th><th class="num">Immagini</th><th class="num">Originali</th><th></th></tr>
      <?php foreach ($byAlb as $a): ?>
      <tr><td><?= $a['name'] === '' ? '<i>senza album</i>' : '<a href="' . h($B . '/?f=' . rawurlencode($a['name'])) . '">' . h($a['name']) . '</a>' ?></td>
        <td class="num"><?= (int) $a['n'] ?></td><td class="num"><?= mb((int) $a['b']) ?></td>
        <td style="width:30%"><span class="hbar"><i style="width:<?= pct((int) $a['b'], $amax) ?>"></i></span></td></tr>
      <?php endforeach; ?>
      <?php if ($tr['n']): ?>
      <tr><td><a href="<?= view_url(['v' => 'cestino']) ?>"><i>cestino</i></a></td><td class="num"><?= (int) $tr['n'] ?></td><td class="num"><?= mb((int) $tr['b']) ?></td>
        <td><span class="hbar mut"><i style="width:<?= pct((int) $tr['b'], $amax) ?>"></i></span></td></tr>
      <?php endif; ?>
    </table>
  </section>

  <section class="card">
    <h2>Caricamenti nel tempo</h2>
    <?php if (!$months): ?><p class="note">Nessuna immagine.</p><?php else: $mmax = max(1, max(array_column($months, 'n'))); ?>
    <div class="cols" role="img" aria-label="Immagini caricate per mese">
      <?php foreach ($months as $m => $c): ?>
        <span class="<?= $c['n'] ? '' : 'zero' ?>" style="height:<?= pct($c['n'], $mmax) ?>" title="<?= h(date('m/Y', strtotime("$m-01")) . ': ' . $c['n'] . ' immagini, ' . mb($c['b'])) ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="cols-x"><span><?= h(date('m/Y', strtotime(array_key_first($months) . '-01'))) ?></span><span><?= h(date('m/Y')) ?></span></div>
    <table class="dt" style="margin-top:12px">
      <?php foreach (array_slice(array_filter(array_reverse($months, true), fn($c) => $c['n'] > 0), 0, 6, true) as $m => $c): ?>
      <tr><td><?= h(date('m/Y', strtotime("$m-01"))) ?></td><td class="num"><?= $c['n'] === 1 ? '1 immagine' : $c['n'] . ' immagini' ?></td><td class="num"><?= mb($c['b']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</div>

<?php elseif ($view === 'album'): /* ===================== ALBUM ===================== */ ?>

<p class="note">Rinominare un album con il nome di uno che esiste già li unisce. Le linguette della galleria
  seguono la posizione (numeri più bassi prima); senza posizione l'ordine è alfabetico.</p>
<div class="tbl-scroll">
<table class="ws">
<tr><th>Copertina</th><th>Album</th><th>Descrizione · copertina · posizione</th><th>Rinomina o unisci</th></tr>
<?php foreach ($albums as $a):
  if ($a['name'] === '') continue;
  $cov = album_cover($a['name'], $a['cover']);
  $nm  = htmlspecialchars($a['name']);
  $imgs = db()->prepare("SELECT short, title FROM images WHERE COALESCE(folder,'')=? AND deleted_at IS NULL ORDER BY created_at DESC");
  $imgs->execute([$a['name']]); ?>
<tr>
  <td><?php if ($cov): ?><img class="cover-thumb" src="<?= htmlspecialchars($B . '/i.php?c=' . $cov['short'] . '&thumb=1&v=' . thumb_version($cov['filename'])) ?>" alt=""><?php endif; ?></td>
  <td><b><?= $nm ?></b><br><span style="color:var(--muted)"><?= (int) $a['n'] ?> immagini</span><br>
      <a href="<?= htmlspecialchars($B . '/?f=' . rawurlencode($a['name'])) ?>">↗ in galleria</a></td>
  <td>
    <form method="post" class="mini">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="album_meta"><input type="hidden" name="name" value="<?= $nm ?>">
      <textarea name="description" rows="2" maxlength="500" placeholder="descrizione"><?= htmlspecialchars($a['description']) ?></textarea>
      <select name="cover" aria-label="Copertina di <?= $nm ?>">
        <option value="">copertina: la più recente</option>
        <?php foreach ($imgs->fetchAll() as $im): ?>
          <option value="<?= htmlspecialchars($im['short']) ?>" <?= $a['cover'] === $im['short'] ? 'selected' : '' ?>><?= htmlspecialchars($im['short'] . ($im['title'] ? ' · ' . $im['title'] : '')) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="position" value="<?= $a['position'] === null ? '' : (int) $a['position'] ?>" placeholder="posizione" inputmode="numeric" style="width:96px">
      <button type="submit">Salva</button>
    </form>
  </td>
  <td>
    <form method="post" class="mini" data-confirm-merge>
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="album_rename"><input type="hidden" name="from" value="<?= $nm ?>">
      <input type="text" name="to" value="<?= $nm ?>" list="albums" aria-label="Nuovo nome di <?= $nm ?>">
      <button type="submit" class="ghost">Rinomina / unisci</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php else: /* ===================== ETICHETTE ===================== */ ?>

<p class="note">Rinominare con un nome già esistente unisce le due etichette; un nome vuoto elimina l'etichetta
  (le immagini restano). Le etichette si assegnano dal foglio di lavoro, anche a più immagini insieme.</p>
<?php if (!$tags): ?><p class="note">Nessuna etichetta, per ora.</p><?php else: ?>
<div class="tbl-scroll">
<table class="ws">
<tr><th>Etichetta</th><th>Immagini</th><th>Rinomina, unisci o elimina</th></tr>
<?php foreach ($tags as $t): $tn = htmlspecialchars($t['name']); ?>
<tr>
  <td><a class="chip" href="<?= view_url(['tag' => $t['name']]) ?>"><?= $tn ?></a></td>
  <td><?= (int) $t['n'] ?></td>
  <td>
    <form method="post" class="mini">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="tag_rename"><input type="hidden" name="from" value="<?= $tn ?>">
      <input type="text" name="to" value="<?= $tn ?>" aria-label="Nuovo nome di <?= $tn ?>">
      <button type="submit" class="ghost">Salva</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // conferme per le azioni senza ritorno, e per quelle su immagini ancora in uso
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    if (f.hasAttribute('data-confirm-merge')) {
      var to = f.elements.to.value.trim(), from = f.elements.from.value;
      var exists = [].some.call(document.querySelectorAll('#albums option'), function (o) { return o.value === to && to !== from; });
      if (exists) msg = 'L\'album «' + to + '» esiste già: unire «' + from + '» a «' + to + '»?';
      else if (to === '') msg = 'Togliere l\'album: le immagini di «' + from + '» resteranno senza album?';
    }
    if (f.hasAttribute('data-bulk') && f.elements.op.value === 'trash') {
      var sel = [].slice.call(document.querySelectorAll('[data-bulk-item]:checked'));
      var busy = sel.filter(function (c) { return c.hasAttribute('data-in-use'); });
      msg = 'Spostare nel cestino ' + (sel.length === 1 ? '1 immagine?' : sel.length + ' immagini?');
      if (busy.length) {
        msg += '\n\nAttenzione, ' + (busy.length === 1 ? 'una è ancora in uso' : busy.length + ' sono ancora in uso') + ':\n'
          + busy.slice(0, 8).map(function (c) { return '• ' + c.value + ': ' + c.getAttribute('data-in-use'); }).join('\n')
          + (busy.length > 8 ? '\n…' : '');
      }
    }
    if (!msg) return;
    if (!confirm(msg)) { e.preventDefault(); return; }
    if (f.elements.in_use_ok) f.elements.in_use_ok.value = '1';   // l'avviso e' stato letto e confermato
  });

  // selezione multipla
  var bar = document.querySelector('[data-bulk]');
  if (!bar) return;
  var all = document.querySelector('[data-bulk-all]'), count = bar.querySelector('[data-bulk-count]');
  var go = bar.querySelector('[data-bulk-go]'), op = bar.querySelector('[data-bulk-op]'), val = bar.querySelector('[data-bulk-value]');
  var items = [].slice.call(document.querySelectorAll('[data-bulk-item]'));
  var HINT = { move: 'album (vuoto: senza album)', tag: 'etichetta da aggiungere', untag: 'etichetta da togliere', trash: '' };
  function refresh() {
    var n = items.filter(function (c) { return c.checked; }).length;
    count.textContent = n ? n + (n === 1 ? ' selezionata' : ' selezionate') : 'nessuna selezionata';
    go.disabled = !n || ((op.value === 'tag' || op.value === 'untag') && !val.value.trim());
    all.checked = n > 0 && n === items.length;
    all.indeterminate = n > 0 && n < items.length;
    bar.classList.toggle('on', n > 0);
  }
  function setOp() {
    val.hidden = op.value === 'trash';
    val.placeholder = HINT[op.value];
    if (op.value === 'move') val.setAttribute('list', 'albums'); else val.removeAttribute('list');
    refresh();
  }
  all.addEventListener('change', function () { items.forEach(function (c) { c.checked = all.checked; }); refresh(); });
  items.forEach(function (c) { c.addEventListener('change', refresh); });
  op.addEventListener('change', setOp);
  val.addEventListener('input', refresh);
  setOp();
});
</script>

<?php
if ($view === '' || $view === 'cestino') {
  $mk = fn($p) => view_url(['v' => $view, 'q' => $q, 'tag' => $tag, 'uso' => $uso, 'p' => $p]);
  theme_foot([
    'page'  => $page,
    'pages' => $pages,
    'prev'  => $page > 1      ? $mk($page - 1) : null,
    'next'  => $page < $pages ? $mk($page + 1) : null,
    'label' => $sub,
  ], 'Pannello admin', false);
} else {
  theme_foot(null, 'Pannello admin', false);
}
