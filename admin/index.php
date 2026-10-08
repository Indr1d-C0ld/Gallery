<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../_theme.php";
require_once __DIR__ . "/../_images.php";
require_once __DIR__ . "/../_archive.php";

require_login();
csrf_token();

/* Viste: foglio di lavoro (default), album, etichette, cestino */
$VIEWS = ['' => 'Foglio di lavoro', 'album' => 'Album', 'etichette' => 'Etichette', 'cestino' => 'Cestino'];
$view  = get_str('v');
if (!isset($VIEWS[$view])) $view = '';

function flash(string $msg): void { $_SESSION['flash'] = $msg; }
/* "1 immagine spostata" / "3 immagini spostate" */
function imgs(int $n, string $one, string $many): string { return $n === 1 ? "1 immagine $one" : "$n immagini $many"; }
function back(array $keep): void {
  header("Location: index.php?" . http_build_query(array_filter($keep, fn($v) => $v !== '' && $v !== null)), true, 303);
  exit;
}

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
      case 'trash': $n = trash_images($ids); flash(imgs($n, 'spostata', 'spostate') . " nel cestino"); break;
      default:      flash("Azione sconosciuta");
    }

  } elseif ($act === 'restore') {
    $n = restore_images(post_list('ids') ?: [$short]);
    flash(imgs($n, 'ripristinata', 'ripristinate'));

  } elseif ($act === 'purge') {
    $n = purge_images(post_list('ids') ?: [$short]);
    flash(imgs($n, 'eliminata', 'eliminate') . " definitivamente");

  } elseif ($act === 'purge_all') {
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

  back(['v' => $view, 'q' => get_str('q'), 'tag' => get_str('tag'), 'p' => get_int('p', 1, 1, 100000) > 1 ? get_int('p', 1, 1, 100000) : '']);
}

/* =====================  GET  ===================== */
// cestino: oltre TRASH_DAYS giorni l'eliminazione diventa definitiva
$auto = purge_trash(time() - TRASH_DAYS * 86400);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
if ($auto) $flash = trim($flash . " · Eliminate definitivamente: " . imgs($auto, 'rimasta', 'rimaste') . " nel cestino oltre " . TRASH_DAYS . " giorni", ' ·');

$q    = substr(preg_replace('~\s+~', ' ', trim(get_str('q'))), 0, 80);
$tag  = norm_tag(get_str('tag'));
$page = get_int('p', 1, 1, 100000);
$B    = $BASE_URL;
$ntrash = trash_count();
$albums = album_list();
$albumNames = array_values(array_filter(array_column($albums, 'name'), fn($n) => $n !== ''));

if ($view === '' || $view === 'cestino') {
  $res = gallery_search(['q' => $q, 'tag' => $tag, 'page' => $page, 'per' => 40, 'trash' => $view === 'cestino']);
  $rows = $res['rows']; $total = $res['total']; $page = $res['page']; $pages = $res['pages'];
  $sub = $view === 'cestino' ? "$total nel cestino" : "$total immagini";
} elseif ($view === 'album') {
  $sub = count($albumNames) . ' album';
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
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="folder:Viaggi title:mare tag:forum id:abc123" style="min-width:300px">
    <button type="submit">Cerca</button>
    <?php if ($q !== '' || $tag !== ''): ?><a class="btn ghost" href="<?= view_url(['v' => $view]) ?>">Reset</a><?php endif; ?>
  </form>
  <?php endif; ?>
  <a class="btn ghost" href="<?= htmlspecialchars($B) ?>/">↗ galleria</a>
  <?php if ($view === ''): ?><label class="fmt">formato <select data-snip-format aria-label="Formato degli snippet"></select></label><?php endif; ?>
  <?= theme_toggle() ?>
</div>

<?php if ($flash !== ''): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($tag !== ''): ?><p class="note">Etichetta: <span class="chip on"><?= htmlspecialchars($tag) ?></span>
  <a href="<?= view_url(['v' => $view, 'q' => $q]) ?>">togli il filtro</a></p><?php endif; ?>

<datalist id="albums"><?php foreach ($albumNames as $a): ?><option value="<?= htmlspecialchars($a) ?>"><?php endforeach; ?></datalist>

<?php if ($view === ''): /* ===================== FOGLIO DI LAVORO ===================== */ ?>

<form method="post" id="bulk" class="bulkbar" data-bulk>
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="bulk">
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
<tr><th><input type="checkbox" data-bulk-all aria-label="Seleziona tutte"></th><th>Provino</th><th>Short / data</th><th>Album · titolo · alt · etichette</th><th>Link &amp; embed</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $full  = $B . "/i/" . $r['short'];
  $tb    = $B . "/i.php?c=" . $r['short'] . "&thumb=1&v=" . thumb_version($r['filename']);
  $dim   = ($r['width'] && $r['height']) ? "{$r['width']}×{$r['height']}" : "?";
  $sh    = htmlspecialchars($r['short']);
?>
<tr>
  <td><input type="checkbox" name="ids[]" value="<?= $sh ?>" form="bulk" data-bulk-item aria-label="Seleziona <?= $sh ?>"></td>
  <td><a href="<?= htmlspecialchars($full) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($tb) ?>" alt=""></a><br>
      <span style="color:var(--muted)"><?= $dim ?> · <?= (int)round(($r['size'] ?? 0)/1024) ?> KB</span></td>

  <td><code><?= $sh ?></code><br>
      <span style="color:var(--muted)"><?= date('Y-m-d H:i', $r['created_at']) ?></span></td>

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
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="delete">
        <input type="hidden" name="short" value="<?= $sh ?>">
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
  Ricaricare la stessa immagine la ripristina con i suoi indirizzi di prima.</p>
<?php if ($ntrash): ?>
<form method="post" data-confirm="Eliminare definitivamente tutte le <?= $ntrash ?> immagini del cestino? Non si torna indietro.">
  <?= csrf_field() ?><input type="hidden" name="act" value="purge_all">
  <button type="submit" class="danger">Svuota il cestino (<?= $ntrash ?>)</button>
</form>
<?php endif; ?>

<?php if (!$rows): ?><p class="note">Il cestino è vuoto.</p><?php else: ?>
<div class="tbl-scroll">
<table class="ws">
<tr><th>Provino</th><th>Codice · album · titolo</th><th>Nel cestino</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $days = TRASH_DAYS - (int) floor((time() - (int) $r['deleted_at']) / 86400);
  $sh = htmlspecialchars($r['short']); $img = inline_thumb($r); ?>
<tr>
  <td><?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php else: ?><span class="note">(nessuna miniatura)</span><?php endif; ?></td>
  <td><code><?= $sh ?></code><br><?= htmlspecialchars($r['folder'] === '' ? 'senza album' : $r['folder']) ?>
      <?= $r['title'] ? '<br>' . htmlspecialchars($r['title']) : '' ?></td>
  <td><?= date('Y-m-d H:i', (int) $r['deleted_at']) ?><br>
      <span style="color:var(--muted)">ancora <?= max(0, $days) ?> giorni</span></td>
  <td>
    <div class="act-grid">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="restore"><input type="hidden" name="short" value="<?= $sh ?>">
        <button type="submit">Ripristina</button>
      </form>
      <form method="post" data-confirm="Eliminare definitivamente <?= $sh ?>? Non si torna indietro."><?= csrf_field() ?>
        <input type="hidden" name="act" value="purge"><input type="hidden" name="short" value="<?= $sh ?>">
        <button type="submit" class="danger">Elimina per sempre</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

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
  // conferme per le azioni senza ritorno
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    if (f.hasAttribute('data-confirm-merge')) {
      var to = f.elements.to.value.trim(), from = f.elements.from.value;
      var exists = [].some.call(document.querySelectorAll('#albums option'), function (o) { return o.value === to && to !== from; });
      if (exists) msg = 'L\'album «' + to + '» esiste già: unire «' + from + '» a «' + to + '»?';
      else if (to === '') msg = 'Togliere l\'album: le immagini di «' + from + '» resteranno senza album?';
    }
    if (f.hasAttribute('data-bulk') && f.elements.op.value === 'trash') {
      msg = 'Spostare nel cestino ' + document.querySelectorAll('[data-bulk-item]:checked').length + ' immagini?';
    }
    if (msg && !confirm(msg)) e.preventDefault();
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
  $mk = fn($p) => view_url(['v' => $view, 'q' => $q, 'tag' => $tag, 'p' => $p]);
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
