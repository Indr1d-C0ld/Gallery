<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_theme.php";
require_once __DIR__ . "/_images.php";

require_login();          // difesa in profondità (Apache autentica già)
csrf_token();             // avvia sessione + token PRIMA di qualsiasi output

function fts5_available(): bool {
  try {
    $r = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='images_fts'")->fetch();
    return (bool)$r;
  } catch (Throwable $e) {
    return false;
  }
}

function build_fts_query(string $q): string {
  $q = preg_replace('~\s+~', ' ', trim($q));
  $q = substr($q, 0, 120);

  $parts = [];
  foreach (explode(' ', $q) as $tok) {
    if ($tok === '') continue;
    if (preg_match('~^(folder|title|alt|file|id):(.+)$~i', $tok, $m)) {
      $k = strtolower($m[1]);
      $v = str_replace('"', '""', trim(trim($m[2]), "\"'"));
      // short e' UNINDEXED nell'indice FTS: cercarci non trova mai nulla.
      // Il codice e' anche nel nome del file (CODICE.ext), che e' indicizzato.
      if     ($k === 'id')   $parts[] = 'filename:"' . $v . '"';
      elseif ($k === 'file') $parts[] = 'filename:"' . $v . '"';
      else                   $parts[] = $k . ':"' . $v . '"';
    } else {
      $tok = str_replace('"', '""', trim($tok, "\"'"));
      $parts[] = '"' . $tok . '"';
    }
  }
  return $parts ? implode(' AND ', $parts) : '';
}

function human_size(?int $b): string {
  if (!$b) return '';
  $u = ['B','KB','MB','GB']; $i = 0;
  while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
  return ($i ? round($b, 1) : $b) . ' ' . $u[$i];
}

/* ---- Input ----
 * f assente        -> scope "all"  (mostra tutto l'archivio)
 * f="" (esplicito) -> scope "folder" con folder vuoto (senza album)
 * f="Nome"         -> scope "folder" con quell'album
 */
$scope  = isset($_GET['f']) ? 'folder' : 'all';
$folder = substr(preg_replace('~[^a-zA-Z0-9 _-]~', '', trim(get_str('f'))), 0, 64);
$q      = substr(preg_replace('~\s+~', ' ', trim(get_str('q'))), 0, 80);
$ok     = substr(preg_replace('~[^A-Za-z0-9_-]~', '', trim(get_str('ok'))), 0, 16);

$per    = 48;
$page   = get_int('p', 1, 1, 100000);   // il tetto reale si applica dopo il conteggio
$offset = ($page - 1) * $per;

/* ---- Cartelle ---- */
$folders = db()->query("
  SELECT COALESCE(folder,'') AS folder, COUNT(*) AS c
  FROM images
  GROUP BY COALESCE(folder,'')
  ORDER BY (COALESCE(folder,'')='') DESC, folder ASC
")->fetchAll();

/* ---- Query risultati + conteggio ---- */
$useFts = ($q !== '' && fts5_available());

if ($useFts) {
  $fts  = build_fts_query($q);
  // NB: usare il nome reale della tabella FTS (l'alias non è ammesso con MATCH)
  $from = "FROM images_fts JOIN images i ON i.id = images_fts.rowid WHERE images_fts MATCH ?";
  $args = [$fts];
  if ($scope === 'folder') {
    if ($folder === '') { $from .= " AND COALESCE(i.folder,'')=''"; }
    else                { $from .= " AND i.folder=?"; $args[] = $folder; }
  }

  $cnt = db()->prepare("SELECT COUNT(*) $from");
  $cnt->execute($args);
  $total  = (int)$cnt->fetchColumn();
  $offset = page_offset($page, $total, $per);

  $st = db()->prepare("
    SELECT i.short,i.filename,i.mime,i.title,i.alt,i.width,i.height,i.size,i.created_at,COALESCE(i.folder,'') AS folder
    $from ORDER BY bm25(images_fts), i.created_at DESC LIMIT $per OFFSET $offset
  ");
  $st->execute($args);
  $rows = $st->fetchAll();
} else {
  $where = []; $args = [];
  if ($scope === 'folder') {
    if ($folder === '') { $where[] = "COALESCE(folder,'')=''"; }
    else                { $where[] = "folder=?"; $args[] = $folder; }
  }
  if ($q !== '') {
    $where[] = "(short LIKE ? OR title LIKE ? OR alt LIKE ? OR filename LIKE ? OR COALESCE(folder,'') LIKE ?)";
    $like = "%$q%"; array_push($args, $like, $like, $like, $like, $like);
  }
  $w = $where ? (" WHERE " . implode(" AND ", $where)) : "";

  $cnt = db()->prepare("SELECT COUNT(*) FROM images$w");
  $cnt->execute($args);
  $total  = (int)$cnt->fetchColumn();
  $offset = page_offset($page, $total, $per);

  $st = db()->prepare("
    SELECT short,filename,mime,title,alt,width,height,size,created_at,COALESCE(folder,'') AS folder
    FROM images$w ORDER BY created_at DESC LIMIT $per OFFSET $offset
  ");
  $st->execute($args);
  $rows = $st->fetchAll();
}

$pages = max(1, (int)ceil($total / $per));
$qs = function (array $ov = []) use ($scope, $folder, $q, $page) {
  $base = ['q' => $q, 'p' => $page];
  if ($scope === 'folder') $base = ['f' => $folder] + $base;
  return '?' . http_build_query(array_merge($base, $ov));
};

// theme_head() applica gia' l'escape: qui si passa testo grezzo (vedi #13)
theme_head('Gallery', $total . ' fotogrammi' . ($scope === 'all' ? ' in archivio' : ' · ' . ($folder === '' ? 'senza album' : $folder)));
?>

<?php if ($ok !== ''):
  $st = db()->prepare("SELECT * FROM images WHERE short=?");
  $st->execute([$ok]);
  $okRow = $st->fetch() ?: null; ?>
  <div class="flash"><?= get_str('dup') === '1' ? 'Già in archivio: ecco il link esistente' : 'Upload OK' ?>
    &nbsp;·&nbsp; ID <code><?= htmlspecialchars($ok) ?></code>
    &nbsp;·&nbsp; <a href="<?= htmlspecialchars($BASE_URL . '/i/' . $ok) ?>" target="_blank" rel="noopener">apri</a>
    <?php if ($okRow): ?><?= snippet_box($okRow, 'altri formati') ?><?php endif; ?></div>
<?php endif; ?>

<div class="bar">
  <div class="tabs">
    <a class="tab <?= $scope === 'all' ? 'on' : '' ?>" href="?q=<?= urlencode($q) ?>">tutti<span class="n"><?= array_sum(array_column($folders, 'c')) ?></span></a>
    <?php foreach ($folders as $fo): $name = $fo['folder'];
      $isRoot = ($name === '');
      $on = ($scope === 'folder' && $folder === $name);
      $lbl = $isRoot ? 'senza album' : htmlspecialchars($name); ?>
      <a class="tab <?= $on ? 'on' : '' ?>" href="?f=<?= urlencode($name) ?>&q=<?= urlencode($q) ?>">
        <?= $lbl ?><span class="n"><?= $fo['c'] ?></span></a>
    <?php endforeach; ?>
  </div>

  <form class="search" method="get">
    <?php if ($scope === 'folder'): ?><input type="hidden" name="f" value="<?= htmlspecialchars($folder) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="tramonto · folder:Viaggi · title:mare · id:abc123" style="min-width:280px">
    <button type="submit">Cerca</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="<?= $scope === 'folder' ? '?f=' . urlencode($folder) : '?' ?>">Reset</a><?php endif; ?>
    <?php if (current_user() !== null): ?><a class="btn ghost" href="<?= htmlspecialchars($BASE_URL) ?>/admin/">↗ admin</a><?php endif; ?>
  </form>
  <?= theme_toggle() ?>
</div>

<?php if ($q !== ''): ?>
  <div class="note">Ricerca: <code><?= htmlspecialchars($q) ?></code><?= $useFts ? ' · FTS5' : ' · LIKE' ?></div>
<?php endif; ?>

<?php if (current_user() !== null): ?>
<div class="slot" id="carica">
  <h2>Nuovo provino</h2>
  <form action="upload.php" method="post" enctype="multipart/form-data"
        data-uploader data-max="<?= (int)$MAX_BYTES ?>">
    <?= csrf_field() ?>
    <input type="file" name="img" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
    <input type="text" name="folder" placeholder="album (opz.)" value="<?= htmlspecialchars($folder) ?>">
    <input type="text" name="title" placeholder="titolo (opz.)">
    <input type="text" name="alt" placeholder="alt (opz.)">
    <button type="submit" data-up-submit>Carica</button>
    <label class="fmt">formato <select data-snip-format aria-label="Formato degli snippet"></select></label>
  </form>
  <p class="note" data-up-hint hidden>Scegli uno o più file, <b>incolla</b> uno screenshot (Ctrl+V) o
    <b>trascina</b> le immagini in qualunque punto della pagina: il caricamento parte subito, con album,
    titolo e alt scritti qui sopra, e alla fine il link è già negli appunti nel formato scelto.</p>
  <div class="up-status" data-up-status hidden></div>
  <ol class="tray" data-tray hidden></ol>
</div>
<div class="dropzone" data-dropzone hidden><span>Rilascia per caricare</span></div>
<?php endif; ?>

<hr class="rule">

<?php if (!$rows): ?>
  <p class="note">Nessun fotogramma per questa selezione.</p>
<?php else: ?>
<div class="sheet">
<?php
$n = $offset;
foreach ($rows as $r):
  $n++;
  $full  = $BASE_URL . "/i/" . $r['short'];
  $thumb = $BASE_URL . "/i.php?c=" . $r['short'] . "&thumb=1";
  $tfile = $THUMBS . "/" . $r['filename'];
  $v     = is_file($tfile) ? (int)@filemtime($tfile) : 0;
  $tb    = $thumb . "&v=" . $v;

  $label = $r['title'] ?: $r['alt'] ?: '';
  $alttx = $r['alt'] ?: $r['title'] ?: $r['short'];
  $dim   = ($r['width'] && $r['height']) ? "{$r['width']}×{$r['height']}" : "";
  $meta  = trim($dim . ($dim && $r['size'] ? ' · ' : '') . human_size($r['size']));
?>
  <figure class="frame">
    <img class="shot" loading="lazy" decoding="async"
         src="<?= htmlspecialchars($tb) ?>" alt="<?= htmlspecialchars($alttx) ?>"
         data-full="<?= htmlspecialchars($full) ?>"
         data-title="<?= htmlspecialchars($label ?: $r['short']) ?>"
         data-meta="<?= htmlspecialchars(($r['folder'] ?: 'root') . ' · ' . $meta) ?>">
    <figcaption class="cap">
      <div class="row1"><span><?= sprintf('%03d', $n) ?></span><span><?= date('Y-m-d', $r['created_at']) ?></span></div>
      <div class="ttl"><?= htmlspecialchars($label) ?: '&nbsp;' ?></div>
      <div class="dim"><?= htmlspecialchars($r['folder'] ?: 'root') ?><?= $meta ? ' · ' . htmlspecialchars($meta) : '' ?></div>
      <?= snippet_box($r, '· altri formati ·') ?>
    </figcaption>
  </figure>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
/* =========================================================================
 * Caricatore: file multipli, incolla (Ctrl+V), trascina e rilascia.
 * Un file alla volta verso upload.php (risposta JSON), barra di avanzamento
 * per ciascuno; alla fine i link di tutto il gruppo vanno negli appunti nel
 * formato scelto. Senza JavaScript resta il form classico.
 * Gli snippet li compone gallerySnip, definito in _theme.php.
 * ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('form[data-uploader]');
  if (!form || !window.gallerySnip || !window.FormData) return;
  var input  = form.querySelector('input[type=file]');
  var tray   = document.querySelector('[data-tray]');
  var status = document.querySelector('[data-up-status]');
  var zone   = document.querySelector('[data-dropzone]');
  var MAX    = +form.getAttribute('data-max') || 0;
  var TYPES  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  var queue = [], busy = false, batch = [];

  // Con JavaScript il caricamento parte appena si sceglie un file.
  document.querySelector('[data-up-hint]').hidden = false;
  form.querySelector('[data-up-submit]').hidden = true;
  input.required = false;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function size(n) {
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  }

  function enqueue(list, origin) {
    var files = [].slice.call(list || []);
    if (!files.length) return;
    tray.hidden = false;
    files.forEach(function (f) {
      var li = el('li', 'up-item'), img = el('img', 'up-thumb'), body = el('div', 'up-body');
      var name = (origin === 'paste' && (!f.name || /^image\.\w+$/.test(f.name))) ? 'immagine incollata' : (f.name || 'immagine');
      var bar = el('div', 'up-bar'), fill = el('i');
      img.alt = '';
      bar.appendChild(fill);
      body.appendChild(el('div', 'up-name', name + ' · ' + size(f.size)));
      body.appendChild(bar);
      var msg = body.appendChild(el('div', 'up-msg', 'in coda'));
      li.appendChild(img); li.appendChild(body);
      tray.appendChild(li);
      var job = { file: f, li: li, img: img, body: body, fill: fill, msg: msg };
      if (TYPES.indexOf(f.type) < 0) return fail(job, 'formato non supportato (' + (f.type || 'sconosciuto') + ')');
      if (MAX && f.size > MAX) return fail(job, 'troppo grande: ' + size(f.size) + ', massimo ' + size(MAX));
      queue.push(job);
    });
    tray.lastChild.scrollIntoView({ block: 'nearest' });
    if (!busy) next();
  }

  function next() {
    var job = queue.shift();
    if (!job) { busy = false; finish(); return; }
    busy = true;
    send(job);
  }

  function send(job) {
    var fd = new FormData(form);           // csrf, album, titolo, alt
    fd.delete('img');
    fd.append('img', job.file, job.file.name || 'immagine.png');
    var x = new XMLHttpRequest();
    x.open('POST', form.action);
    x.setRequestHeader('Accept', 'application/json');
    x.upload.onprogress = function (e) {
      if (!e.lengthComputable) return;
      var p = Math.round(e.loaded / e.total * 100);
      job.fill.style.width = p + '%';
      job.msg.textContent = p < 100 ? 'caricamento ' + p + '%' : 'elaborazione…';
    };
    x.onload = function () {
      var d = null;
      try { d = JSON.parse(x.responseText); } catch (e) {}
      if (x.status === 200 && d && d.ok) done(job, d); else fail(job, explain(x.status, d, x.responseText));
      next();
    };
    x.onerror = function () { fail(job, 'connessione interrotta'); next(); };
    job.li.className = 'up-item busy';
    job.msg.textContent = 'caricamento 0%';
    x.send(fd);
  }

  function explain(code, d, raw) {
    if (d && d.error) return d.error;
    if (code === 419) return 'sessione scaduta: ricarica la pagina e riprova';
    if (code === 401) return 'accesso richiesto: ricarica la pagina';
    if (code === 413) return 'file troppo grande per il server';
    return 'errore ' + code + (raw ? ': ' + String(raw).slice(0, 120) : '');
  }

  function fail(job, text) {
    job.li.className = 'up-item err';
    job.fill.style.width = '100%';
    job.msg.textContent = text;
  }

  function done(job, d) {
    job.li.className = 'up-item ' + (d.duplicate ? 'dup' : 'ok');
    job.fill.style.width = '100%';
    job.img.src = d.thumb;
    job.msg.textContent = d.duplicate
      ? 'già in archivio' + (d.folder ? ' (album ' + d.folder + ')' : '') + ': link esistente, nessun doppione'
      : 'caricata · ' + d.width + '×' + d.height;
    if (d.location_removed) job.msg.textContent += ' · posizione GPS rimossa';
    var box = el('div', 'snip'), copy = el('button', 'snip-copy', 'copia');
    var det = el('details'), all = el('div', 'copies');
    box.setAttribute('data-snip', JSON.stringify(d));
    copy.type = 'button'; copy.setAttribute('data-snip-copy', '');
    all.setAttribute('data-snip-all', '');
    det.appendChild(el('summary', null, 'altri formati'));
    det.appendChild(all);
    box.appendChild(copy); box.appendChild(det);
    job.body.appendChild(box);
    gallerySnip.render(box);
    batch.push(d);
  }

  // Fine del gruppo: tutti i link negli appunti, uno per riga.
  function finish() {
    if (!batch.length) return;
    var n = batch.length, label = gallerySnip.label();
    var text = batch.map(function (d) { return gallerySnip.text(d); }).join('\n');
    batch = [];
    status.hidden = false;
    status.textContent = '';
    gallerySnip.copy(text).then(function () {
      status.appendChild(el('span', 'up-done', (n === 1 ? 'Link copiato' : n + ' link copiati') + ' negli appunti · ' + label));
    }, function () {
      status.appendChild(el('span', 'up-warn', 'Il browser non ha permesso la copia automatica: usa i pulsanti «copia».'));
    }).then(function () {
      var a = el('a', 'up-reload', '↻ mostra nella galleria');
      a.href = location.pathname + location.search.replace(/([?&])(ok|dup)=[^&]*/g, '$1');
      status.appendChild(document.createTextNode(' · '));
      status.appendChild(a);
    });
  }

  input.addEventListener('change', function () {
    enqueue(input.files, 'input');
    input.value = '';
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); });

  document.addEventListener('paste', function (e) {
    var cd = e.clipboardData, files = [];
    if (!cd) return;
    [].forEach.call(cd.items || [], function (it) {
      if (it.kind === 'file') { var f = it.getAsFile(); if (f) files.push(f); }
    });
    if (!files.length) files = [].slice.call(cd.files || []);
    files = files.filter(function (f) { return /^image\//.test(f.type); });
    if (!files.length) return;               // testo normale: lo incolla il campo
    e.preventDefault();
    enqueue(files, 'paste');
  });

  var depth = 0;
  function hasFiles(e) {
    var t = e.dataTransfer && e.dataTransfer.types;
    return !!t && [].indexOf.call(t, 'Files') >= 0;
  }
  window.addEventListener('dragenter', function (e) { if (hasFiles(e)) { depth++; zone.hidden = false; } });
  window.addEventListener('dragleave', function (e) { if (hasFiles(e) && --depth <= 0) { depth = 0; zone.hidden = true; } });
  window.addEventListener('dragover',  function (e) { if (hasFiles(e)) e.preventDefault(); });
  window.addEventListener('drop', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    depth = 0; zone.hidden = true;
    enqueue(e.dataTransfer.files, 'drop');
  });

  window.addEventListener('beforeunload', function (e) {
    if (busy) { e.preventDefault(); e.returnValue = ''; }
  });
});
</script>

<?php
theme_foot([
  'page'  => $page,
  'pages' => $pages,
  'prev'  => $page > 1      ? $qs(['p' => $page - 1]) : null,
  'next'  => $page < $pages ? $qs(['p' => $page + 1]) : null,
  'label' => $total . ' fotogrammi',
], 'Archivio privato');
