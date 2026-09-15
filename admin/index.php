<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../_theme.php";

require_login();
csrf_token();

function norm_folder($s) {
  $s = trim($s ?? '');
  $s = preg_replace('~[^a-zA-Z0-9 _-]~', '', $s);
  return substr($s, 0, 64);
}

function fts5_available(): bool {
  try {
    return (bool) db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='images_fts'")->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

function build_fts_query(string $q): string {
  $q = substr(preg_replace('~\s+~', ' ', trim($q)), 0, 120);
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
      $parts[] = '"' . str_replace('"', '""', trim($tok, "\"'")) . '"';
    }
  }
  return $parts ? implode(' AND ', $parts) : '';
}

/* =====================  POST: azioni  ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act   = post_str('act');
  $short = post_str('short');

  if ($act === 'meta') {
    $folder = norm_folder(post_str('folder'));
    db()->prepare("UPDATE images SET title=?, alt=?, folder=? WHERE short=?")
        ->execute([post_str('title'), post_str('alt'), $folder, $short]);

  } elseif ($act === 'delete') {
    delete_image($short);   // rimuove i file solo se nessun'altra copia li usa

  } elseif ($act === 'retthumb') {
    $q = db()->prepare("SELECT filename,mime FROM images WHERE short=?");
    $q->execute([$short]);
    if (($r = $q->fetch()) && is_file($src = $UPLOADS . '/' . $r['filename'])) {
      if ($img = @imagecreatefromstring(file_get_contents($src))) {
        $ow = imagesx($img); $oh = imagesy($img);
        $scale = min(1.0, $THUMB_MAX_W / max($ow, $oh));
        $tw = max(1, (int)round($ow * $scale));
        $th = max(1, (int)round($oh * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagealphablending($thumb, false); imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);
        $dst = $THUMBS . '/' . $r['filename'];
        @mkdir(dirname($dst), 0775, true);
        switch ($r['mime']) {
          case 'image/jpeg': imagejpeg($thumb, $dst, 85); break;
          case 'image/png':  imagepng($thumb, $dst, 6);   break;
          case 'image/gif':  imagegif($thumb, $dst);      break;
          case 'image/webp': imagewebp($thumb, $dst, 85); break;
        }
        imagedestroy($thumb); imagedestroy($img);
      }
    }

  } elseif ($act === 'move') {
    db()->prepare("UPDATE images SET folder=? WHERE short=?")
        ->execute([norm_folder(post_str('dest_folder')), $short]);

  } elseif ($act === 'copy') {
    $q = db()->prepare("SELECT filename,mime,size,width,height,title,alt FROM images WHERE short=?");
    $q->execute([$short]);
    if ($r = $q->fetch()) {
      db()->prepare("INSERT INTO images(short,filename,mime,size,width,height,title,alt,delkey,created_at,folder)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([shortcode(7), $r['filename'], $r['mime'], $r['size'], $r['width'], $r['height'],
                     $r['title'], $r['alt'], bin2hex(random_bytes(8)), time(),
                     norm_folder(post_str('dest_folder'))]);
    }
  }

  header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query(['q' => get_str('q'), 'p' => get_int('p', 1, 1, 100000)]));
  exit;
}

/* =====================  GET: elenco  ===================== */
$q      = substr(preg_replace('~\s+~', ' ', trim(get_str('q'))), 0, 80);
$per    = 40;
$page   = get_int('p', 1, 1, 100000);
$offset = ($page - 1) * $per;

$folders = db()->query("
  SELECT COALESCE(folder,'') AS folder, COUNT(*) AS c FROM images
  GROUP BY COALESCE(folder,'') ORDER BY (COALESCE(folder,'')='') DESC, folder ASC
")->fetchAll();

$cols  = "short,filename,mime,title,alt,width,height,size,created_at,COALESCE(folder,'') AS folder";
$icols = "i.short,i.filename,i.mime,i.title,i.alt,i.width,i.height,i.size,i.created_at,COALESCE(i.folder,'') AS folder";
if ($q !== '' && fts5_available()) {
  $fts = build_fts_query($q);
  $cnt = db()->prepare("SELECT COUNT(*) FROM images_fts JOIN images i ON i.id=images_fts.rowid WHERE images_fts MATCH ?");
  $cnt->execute([$fts]); $total = (int)$cnt->fetchColumn();
  $st = db()->prepare("SELECT $icols
    FROM images_fts JOIN images i ON i.id=images_fts.rowid WHERE images_fts MATCH ?
    ORDER BY bm25(images_fts), i.created_at DESC LIMIT $per OFFSET $offset");
  $st->execute([$fts]); $rows = $st->fetchAll();
} elseif ($q !== '') {
  $like = "%$q%";
  $cnt = db()->prepare("SELECT COUNT(*) FROM images WHERE short LIKE ? OR title LIKE ? OR alt LIKE ? OR filename LIKE ? OR COALESCE(folder,'') LIKE ?");
  $cnt->execute([$like,$like,$like,$like,$like]); $total = (int)$cnt->fetchColumn();
  $st = db()->prepare("SELECT $cols FROM images
    WHERE short LIKE ? OR title LIKE ? OR alt LIKE ? OR filename LIKE ? OR COALESCE(folder,'') LIKE ?
    ORDER BY created_at DESC LIMIT $per OFFSET $offset");
  $st->execute([$like,$like,$like,$like,$like]); $rows = $st->fetchAll();
} else {
  $total = (int) db()->query("SELECT COUNT(*) FROM images")->fetchColumn();
  $rows = db()->query("SELECT $cols FROM images ORDER BY created_at DESC LIMIT $per OFFSET $offset")->fetchAll();
}
$pages = max(1, (int)ceil($total / $per));
$B = $BASE_URL;

theme_head('Gallery · Admin', $total . ' record · utente ' . htmlspecialchars(current_user() ?? '?'));
?>

<div class="bar">
  <form class="search" method="get" style="margin-left:0">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="folder:Viaggi title:mare id:abc123" style="min-width:300px">
    <button type="submit">Cerca</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">Reset</a><?php endif; ?>
    <a class="btn ghost" href="<?= htmlspecialchars($B) ?>/">↗ galleria</a>
  </form>
  <?= theme_toggle() ?>
</div>

<p class="note">Album:
<?php
$out = [];
foreach ($folders as $fo) {
  $name = $fo['folder'] === '' ? 'root' : $fo['folder'];
  $out[] = htmlspecialchars($name) . ' (' . $fo['c'] . ')';
}
echo implode('  ·  ', $out);
?>
</p>

<div class="tbl-scroll">
<table class="ws">
<tr><th>Provino</th><th>Short / data</th><th>Album · titolo · alt</th><th>Link &amp; embed</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $full  = $B . "/i/" . $r['short'];
  $thumb = $B . "/i.php?c=" . $r['short'] . "&thumb=1";
  $tfile = $THUMBS . "/" . $r['filename'];
  $v = is_file($tfile) ? (int)@filemtime($tfile) : 0;
  $tb = $thumb . "&v=" . $v;
  $md = "[![" . ($r['alt'] ?: $r['short']) . "]({$thumb})]({$full})";
  $bb = "[url={$full}][img]{$thumb}[/img][/url]";
  $dim = ($r['width'] && $r['height']) ? "{$r['width']}×{$r['height']}" : "?";
?>
<tr>
  <td><a href="<?= $full ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($tb) ?>" alt=""></a><br>
      <span style="color:var(--muted)"><?= $dim ?> · <?= (int)round(($r['size'] ?? 0)/1024) ?> KB</span></td>

  <td><code><?= htmlspecialchars($r['short']) ?></code><br>
      <span style="color:var(--muted)"><?= date('Y-m-d H:i', $r['created_at']) ?></span></td>

  <td>
    <form method="post" class="mini">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="meta">
      <input type="hidden" name="short" value="<?= htmlspecialchars($r['short']) ?>">
      <input type="text" name="folder" value="<?= htmlspecialchars($r['folder']) ?>" placeholder="album">
      <input type="text" name="title"  value="<?= htmlspecialchars($r['title'] ?? '') ?>" placeholder="titolo">
      <input type="text" name="alt"    value="<?= htmlspecialchars($r['alt'] ?? '') ?>" placeholder="alt">
      <button type="submit">Salva</button>
    </form>
  </td>

  <td>
    <div class="mini">
      <button type="button" data-copy="<?= htmlspecialchars($full, ENT_QUOTES) ?>">copia URL</button>
      <button type="button" data-copy="<?= htmlspecialchars($thumb, ENT_QUOTES) ?>">copia thumb</button>
      <button type="button" data-copy="<?= htmlspecialchars($md, ENT_QUOTES) ?>">copia MD</button>
      <button type="button" data-copy="<?= htmlspecialchars($bb, ENT_QUOTES) ?>">copia BBCode</button>
    </div>
  </td>

  <td>
    <div class="act-grid">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="retthumb">
        <input type="hidden" name="short" value="<?= htmlspecialchars($r['short']) ?>">
        <button type="submit" class="ghost">Rigenera thumb</button>
      </form>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="move">
        <input type="hidden" name="short" value="<?= htmlspecialchars($r['short']) ?>">
        <input type="text" name="dest_folder" placeholder="→ album">
        <button type="submit" class="ghost">Sposta</button>
      </form>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="copy">
        <input type="hidden" name="short" value="<?= htmlspecialchars($r['short']) ?>">
        <input type="text" name="dest_folder" placeholder="→ album">
        <button type="submit" class="ghost">Copia</button>
      </form>
      <form method="post" onsubmit="return confirm('Eliminare definitivamente <?= htmlspecialchars($r['short']) ?>?')"><?= csrf_field() ?>
        <input type="hidden" name="act" value="delete">
        <input type="hidden" name="short" value="<?= htmlspecialchars($r['short']) ?>">
        <button type="submit" style="background:var(--grease);border-color:var(--grease)">Elimina</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php
$pager = '';
if ($pages > 1) {
  $mk = fn($p) => htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query(['q' => $q, 'p' => $p]));
  $pager .= '<span class="pager">';
  $pager .= $page > 1 ? '<a href="' . $mk($page - 1) . '">‹ prec</a>' : '<span>‹ prec</span>';
  $pager .= " &nbsp; $page / $pages &nbsp; ";
  $pager .= $page < $pages ? '<a href="' . $mk($page + 1) . '">succ ›</a>' : '<span>succ ›</span>';
  $pager .= '</span>';
}
theme_foot($pager ?: ($total . ' record'), 'Pannello admin', false);
