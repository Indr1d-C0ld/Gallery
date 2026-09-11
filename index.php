<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/_theme.php";

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
      if     ($k === 'id')   $parts[] = 'short:"' . $v . '"';
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
$folder = substr(preg_replace('~[^a-zA-Z0-9 _-]~', '', trim($_GET['f'] ?? '')), 0, 64);
$q      = substr(preg_replace('~\s+~', ' ', trim($_GET['q'] ?? '')), 0, 80);
$ok     = substr(preg_replace('~[^A-Za-z0-9_-]~', '', trim($_GET['ok'] ?? '')), 0, 16);

$per    = 48;
$page   = max(1, (int)($_GET['p'] ?? 1));
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
  $total = (int)$cnt->fetchColumn();

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
  $total = (int)$cnt->fetchColumn();

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

theme_head('Gallery', $total . ' fotogrammi' . ($scope === 'all' ? ' in archivio' : ' · ' . ($folder === '' ? 'senza album' : htmlspecialchars($folder))));
?>

<?php if ($ok !== ''): ?>
  <div class="flash">Upload OK &nbsp;·&nbsp; ID <code><?= htmlspecialchars($ok) ?></code>
    &nbsp;·&nbsp; <a href="<?= htmlspecialchars($BASE_URL . '/i/' . $ok) ?>" target="_blank" rel="noopener">apri</a></div>
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
<div class="slot">
  <h2>Nuovo provino</h2>
  <form action="upload.php" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="file" name="img" accept="image/*" required>
    <input type="text" name="folder" placeholder="album (opz.)" value="<?= htmlspecialchars($folder) ?>">
    <input type="text" name="title" placeholder="titolo (opz.)">
    <input type="text" name="alt" placeholder="alt (opz.)">
    <button type="submit">Carica</button>
  </form>
</div>
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
  $md    = "[![{$alttx}]({$thumb})]({$full})";
  $bb    = "[url={$full}][img]{$thumb}[/img][/url]";
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
      <details>
        <summary>· link &amp; embed ·</summary>
        <div class="copies">
          <button type="button" data-copy="<?= htmlspecialchars($full, ENT_QUOTES) ?>">URL</button>
          <button type="button" data-copy="<?= htmlspecialchars($thumb, ENT_QUOTES) ?>">Thumb</button>
          <button type="button" data-copy="<?= htmlspecialchars($md, ENT_QUOTES) ?>">MD</button>
          <button type="button" data-copy="<?= htmlspecialchars($bb, ENT_QUOTES) ?>">BBCode</button>
        </div>
      </details>
    </figcaption>
  </figure>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pager = '';
if ($pages > 1) {
  $pager .= '<span class="pager">';
  $pager .= $page > 1 ? '<a href="' . htmlspecialchars($qs(['p' => $page - 1])) . '">‹ prec</a>' : '<span>‹ prec</span>';
  $pager .= " &nbsp; pagina $page / $pages &nbsp; ";
  $pager .= $page < $pages ? '<a href="' . htmlspecialchars($qs(['p' => $page + 1])) . '">succ ›</a>' : '<span>succ ›</span>';
  $pager .= '</span>';
}
theme_foot($pager ?: ($total . ' fotogrammi'), 'Archivio privato');
