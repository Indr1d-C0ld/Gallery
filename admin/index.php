<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../_theme.php";
require_once __DIR__ . "/../_images.php";
require_once __DIR__ . "/../_archive.php";
require_once __DIR__ . "/../_stats.php";
require_once __DIR__ . "/../_api.php";
require_once __DIR__ . "/../_telegram.php";

require_login();
csrf_token();

/* Viste: foglio di lavoro (default), album, etichette, link a scadenza,
 * cruscotto, strumenti (API, screenshot, Telegram), cestino */
$VIEWS = ['' => 'Foglio di lavoro', 'album' => 'Album', 'etichette' => 'Etichette', 'link' => 'Link',
          'cruscotto' => 'Cruscotto', 'strumenti' => 'Strumenti', 'cestino' => 'Cestino'];
$view  = get_str('v');
if (!isset($VIEWS[$view])) $view = '';

function flash(string $msg): void { $_SESSION['flash'] = $msg; }
/* "1 immagine spostata" / "3 immagini spostate" */
function imgs(int $n, string $one, string $many): string { return $n === 1 ? "1 immagine $one" : "$n immagini $many"; }
function back(array $keep, string $anchor = ''): void {
  header("Location: index.php?" . http_build_query(array_filter($keep, fn($v) => $v !== '' && $v !== null)) . $anchor, true, 303);
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
      case 'private':     // come il cestino per chi ha incollato il link: stesso avviso
        if (hold_if_in_use($ids, ['act' => 'bulk', 'op' => 'private', 'ids' => $ids], 'Rendi comunque private')) back($keep);
        $n = set_private($ids, true); flash(imgs($n, 'resa privata', 'rese private')); break;
      case 'public':
        $n = set_private($ids, false); flash(imgs($n, 'resa pubblica', 'rese pubbliche')); break;
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

  } elseif ($act === 'private') {
    $on = post_str('on') === '1';
    if ($on && hold_if_in_use([$short], ['act' => 'private', 'short' => $short, 'on' => '1'], 'Rendi comunque privata')) back($keep);
    $n = set_private([$short], $on);
    flash($n ? ($on ? "$short ora è privata: /i/ e /t/ rispondono 404, si condivide con un link a scadenza" : "$short di nuovo pubblica") : "Nessun cambiamento");

  } elseif ($act === 'share') {
    $sec = (int) post_str('dur');
    $s = isset(SHARE_DURATIONS[$sec]) ? share_create($short, $sec) : null;
    if ($s) $_SESSION['shared'] = $s; else flash("Link non creato (immagine nel cestino o durata non valida)");

  } elseif ($act === 'share_revoke') {
    flash(share_revoke(post_str('token')) ? "Link revocato: da adesso risponde 404" : "Link non trovato o già revocato");

  } elseif ($act === 'tg_webhook') {
    $on = post_str('on') === '1';
    $r = $on ? tg_call('setWebhook', ['url' => tg_webhook_url(), 'secret_token' => tg_secret(),
                                       'allowed_updates' => ['message'], 'drop_pending_updates' => true])
             : tg_call('deleteWebhook', ['drop_pending_updates' => true]);
    flash(!empty($r['ok']) ? ($on ? "Webhook collegato: " . tg_webhook_url() : "Webhook scollegato: il bot non riceve più nulla")
                           : "Telegram ha risposto con un errore: " . tg_mask((string) ($r['description'] ?? '?')));

  } elseif ($act === 'tg_pair') {
    $_SESSION['tg_code'] = tg_pair_new();

  } elseif ($act === 'tg_user_remove') {
    flash(tg_user_remove((int) post_str('tg_id')) ? "Utente scollegato dal bot" : "Utente non trovato");

  } elseif ($act === 'tag_rename') {
    $from = post_str('from'); $res = tag_rename($from, post_str('to'));
    flash(['renamed' => "Etichetta rinominata", 'merged' => "Etichette unite", 'deleted' => "Etichetta «{$from}» eliminata", 'none' => "Etichetta non trovata"][$res]);
  }

  // le azioni sul bot tornano alla sua scheda (il codice compare li')
  back($keep, str_starts_with($act, 'tg_') ? '#telegram' : '');
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
shares_purge(30);                 // link scaduti o revocati da oltre 30 giorni
$flash  = $_SESSION['flash'] ?? '';
$hold   = $_SESSION['hold'] ?? null;
$shared = $_SESSION['shared'] ?? null;
$tgCode = $_SESSION['tg_code'] ?? null;
unset($_SESSION['flash'], $_SESSION['hold'], $_SESSION['shared'], $_SESSION['tg_code']);
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
} elseif ($view === 'link') {
  $links = shares_list();
  $sub = count(array_filter($links, fn($l) => $l['state'] === 'attivo')) . ' link attivi';
} elseif ($view === 'strumenti') {
  $sub = 'strumenti';
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

<?php if ($shared): /* link a scadenza appena creato */ ?>
<div class="flash sharebox">Link a scadenza per <code><?= h($shared['short']) ?></code>, valido fino al
  <?= h(date('d/m/Y H:i', $shared['expires_at'])) ?> (<?= h(time_left($shared['expires_at'])) ?>):<br>
  <code class="sharelink"><?= h($shared['url']) ?></code>
  <button type="button" class="ghost" data-copy="<?= h($shared['url']) ?>">copia</button>
  <span class="note">Funziona anche se l'immagine è privata; dopo la scadenza risponde 410. Si revoca dalla vista Link.</span>
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
    <option value="private">rendi private</option>
    <option value="public">rendi pubbliche</option>
    <option value="trash">sposta nel cestino</option>
  </select>
  <input type="text" name="value" data-bulk-value list="albums" placeholder="album (vuoto: senza album)">
  <button type="submit" data-bulk-go disabled>Applica</button>
</form>

<div class="tbl-scroll">
<table class="ws">
<tr><th><input type="checkbox" data-bulk-all aria-label="Seleziona tutte"></th><th>Provino</th><th>Short / data / uso</th><th>Album · titolo · alt · etichette</th><th>Link &amp; embed</th><th>Azioni</th></tr>
<?php foreach ($rows as $r):
  $full  = ui_full_url($r);
  $tb    = ui_thumb_url($r);
  $dim   = ($r['width'] && $r['height']) ? "{$r['width']}×{$r['height']}" : "?";
  $sh    = htmlspecialchars($r['short']);
  $note  = $inUse[$r['short']] ?? null;
  $priv  = !empty($r['private']);
?>
<tr>
  <td><input type="checkbox" name="ids[]" value="<?= $sh ?>" form="bulk" data-bulk-item aria-label="Seleziona <?= $sh ?>"<?= $note ? ' data-in-use="' . h($note) . '"' : '' ?>></td>
  <td><a href="<?= htmlspecialchars($full) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($tb) ?>" alt=""></a><br>
      <span style="color:var(--muted)"><?= $dim ?> · <?= (int)round(($r['size'] ?? 0)/1024) ?> KB</span></td>

  <td><code><?= $sh ?></code><br>
      <span style="color:var(--muted)"><?= date('Y-m-d H:i', $r['created_at']) ?></span>
      <?php if ($priv): ?><br><span class="use priv" title="/i/ e /t/ rispondono 404: si condivide con un link a scadenza">privata</span><?php endif; ?>
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
      <form method="post" class="share-form"><?= csrf_field() ?>
        <input type="hidden" name="act" value="share"><input type="hidden" name="short" value="<?= $sh ?>">
        <select name="dur" aria-label="Durata del link per <?= $sh ?>">
          <?php foreach (SHARE_DURATIONS as $sec => $lbl): ?><option value="<?= $sec ?>" <?= $sec === 86400 ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="ghost" title="Link che smette di funzionare alla scadenza, anche per un'immagine privata">Link a scadenza</button>
      </form>
      <form method="post"<?= !$priv && $note ? ' data-confirm="' . h("«{$r['short']}» è ancora in uso: $note.\n\nRenderla privata rompe i link già incollati. Procedere?") . '"' : '' ?>><?= csrf_field() ?>
        <input type="hidden" name="act" value="private"><input type="hidden" name="short" value="<?= $sh ?>">
        <input type="hidden" name="on" value="<?= $priv ? '0' : '1' ?>"><input type="hidden" name="in_use_ok" value="0">
        <button type="submit" class="ghost"><?= $priv ? 'Rendi pubblica' : 'Rendi privata' ?></button>
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
    $st = db()->prepare("SELECT short, filename, mime, title, COALESCE(folder,'') AS folder, deleted_at, private FROM images WHERE short IN (" . in_list($want) . ")");
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
        <td><?php if ($im): ?><a href="<?= h(ui_full_url($im)) ?>" target="_blank" rel="noopener"><img src="<?= h(ui_thumb_url($im)) ?>" alt=""></a><?php endif; ?></td>
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

<?php elseif ($view === 'link'): /* ===================== LINK A SCADENZA ===================== */ ?>

<p class="note">Un link a scadenza (<code>/i/TOKEN</code>) mostra l'immagine anche se è privata, fino alla scadenza;
  poi risponde 410. Si crea dal foglio di lavoro; revocarlo lo spegne subito. Quelli scaduti o revocati da oltre 30 giorni spariscono.</p>
<?php if (!$links): ?><p class="note">Nessun link, per ora.</p><?php else: ?>
<div class="tbl-scroll">
<table class="ws">
<tr><th>Provino</th><th>Immagine</th><th>Link</th><th>Scadenza</th><th>Azioni</th></tr>
<?php foreach ($links as $l): $act = $l['state'] === 'attivo'; ?>
<tr>
  <td><img src="<?= h(ui_thumb_url($l)) ?>" alt="" class="cover-thumb"></td>
  <td><code><?= h($l['short']) ?></code><?= !empty($l['private']) ? ' <span class="use priv">privata</span>' : '' ?><br>
      <span style="color:var(--muted)"><?= h(($l['folder'] === '' ? 'senza album' : $l['folder']) . ($l['title'] ? ' · ' . $l['title'] : '')) ?></span></td>
  <td><code class="sharelink"><?= h($l['url']) ?></code><?php if ($act): ?><br><button type="button" class="ghost" data-copy="<?= h($l['url']) ?>">copia</button><?php endif; ?></td>
  <td><?= h(date('d/m/Y H:i', (int) $l['expires_at'])) ?><br>
      <span class="use <?= $act ? 'on' : 'off' ?>"><?= h($l['state']) ?><?= $act ? ' · ' . h(time_left((int) $l['expires_at'])) : '' ?></span></td>
  <td><?php if ($act || $l['state'] === 'nel cestino'): ?>
    <form method="post" data-confirm="Revocare il link? Chi lo ha ricevuto vedrà un'immagine mancante."><?= csrf_field() ?>
      <input type="hidden" name="act" value="share_revoke"><input type="hidden" name="token" value="<?= h($l['token']) ?>">
      <button type="submit" class="danger">Revoca</button>
    </form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php elseif ($view === 'strumenti'): /* ===================== STRUMENTI ===================== */
  $apiOk  = api_token_ok();
  $apache = api_apache_state();
  $tgOn   = tg_enabled();
  $me = $tgOn ? tg_call('getMe', [], 5) : [];
  $wh = $tgOn ? tg_call('getWebhookInfo', [], 5) : [];
  $whUrl = (string) ($wh['result']['url'] ?? '');
  $tgUsers = tg_users();
?>
<div class="dash">
  <section class="card wide">
    <h2>API</h2>
    <?php if (!$apiOk): ?>
      <div class="warnbox">API disattivata: in <code>secret.php</code> manca un <code>API_TOKEN</code> robusto
        (<code>php -r 'echo bin2hex(random_bytes(24));'</code>).</div>
    <?php elseif ($apache === 'basic'): ?>
      <div class="warnbox">Apache chiede ancora la password su <code>/gallery/api/</code>: ShareX, Flameshot e Telegram non
        possono usare il solo token. Una volta, da root: <code>sudo bash <?= h(dirname(__DIR__)) ?>/apply_root_tasks.sh --yes</code></div>
    <?php elseif ($apache === 'token'): ?>
      <p class="note">✓ L'API risponde con il solo token, senza password di Apache.</p>
    <?php else: ?>
      <p class="note">Non sono riuscito a verificare come Apache tratta <code>/gallery/api/</code> (controllo dal server verso se stesso).</p>
    <?php endif; ?>
    <p class="note">Il token va nell'intestazione <code>X-Api-Token</code>, mai nell'indirizzo (finirebbe nei log). È in
      <code>secret.php</code>; i file scaricati qui sotto lo contengono già: trattali come una password.</p>
    <div class="tbl-scroll"><table class="dt">
      <tr><th>Richiesta</th><th>Cosa fa</th></tr>
      <tr><td><code>POST <?= h($B) ?>/api/upload.php</code></td><td>carica: campo <code>img</code>; facoltativi <code>folder</code>, <code>title</code>, <code>alt</code>, <code>private=1</code></td></tr>
      <tr><td><code>GET <?= h($B) ?>/api/images.php?q=&amp;folder=&amp;tag=&amp;page=&amp;per=</code></td><td>elenco e ricerca (stessa sintassi della galleria); <code>trash=1</code> il cestino, <code>private=1</code> le private</td></tr>
      <tr><td><code>GET <?= h($B) ?>/api/image.php?c=CODICE</code></td><td>dettaglio: etichette, uso negli ultimi <?= USAGE_DAYS ?> giorni, link a scadenza</td></tr>
      <tr><td><code>POST …/api/image.php?c=CODICE</code> <code>action=update</code></td><td><code>title</code>, <code>alt</code>, <code>folder</code>, <code>tags</code>, <code>private</code></td></tr>
      <tr><td><code>DELETE …/api/image.php?c=CODICE</code></td><td>nel cestino; se è in uso risponde 409, <code>force=1</code> per farlo comunque. <code>action=restore</code> la ripristina</td></tr>
      <tr><td><code>POST …/api/image.php?c=CODICE</code> <code>action=share</code></td><td>link a scadenza: <code>seconds</code> (da 60 secondi a 90 giorni); <code>action=unshare</code> + <code>token</code> lo revoca</td></tr>
    </table></div>
    <pre class="code">curl -H "X-Api-Token: $TOKEN" -F img=@foto.jpg -F folder=Blog <?= h($B) ?>/api/upload.php
curl -H "X-Api-Token: $TOKEN" "<?= h($B) ?>/api/images.php?q=tag:forum"</pre>
  </section>

  <section class="card">
    <h2>Screenshot dal desktop</h2>
    <?php if (!$apiOk): ?><p class="note">Servono un token API (vedi sopra).</p><?php else: ?>
    <form method="get" action="tools.php" class="mini" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <label class="note" style="margin:0">album <input type="text" name="album" value="Screenshot" list="albums" style="width:140px"></label>
      <button type="submit" name="f" value="sharex">ShareX (.sxcu)</button>
      <button type="submit" name="f" value="flameshot">Flameshot (script)</button>
    </form>
    <p class="note"><b>ShareX</b> (Windows): doppio clic sul file scaricato, poi Destinazioni → Caricamento immagini → Uploader
      personalizzato. Dopo la cattura il link è già negli appunti.</p>
    <p class="note"><b>Flameshot</b> (Linux): <code>chmod 700 gallery-screenshot.sh</code> e assegnalo a una scorciatoia (es. Stamp).
      Selezioni l'area, il link finisce negli appunti (<code>wl-copy</code>, <code>xclip</code> o <code>xsel</code>);
      <code>--full</code> cattura tutto lo schermo.</p>
    <?php endif; ?>
  </section>

  <section class="card" id="telegram">
    <h2>Telegram</h2>
    <?php if (!$tgOn): ?>
      <p class="note">Il bot è spento. Per accenderlo:</p>
      <ol class="note">
        <li>su Telegram scrivi a <b>@BotFather</b>: <code>/newbot</code>, scegli nome e username, copia il token;</li>
        <li>aggiungilo a <code>secret.php</code>: <code>'TELEGRAM_BOT_TOKEN' => '123456:ABC…',</code>;</li>
        <li>torna qui: «Collega il webhook», poi genera il codice e mandalo al bot.</li>
      </ol>
    <?php else: ?>
      <p class="note">Bot: <b><?= !empty($me['ok']) ? '@' . h((string) ($me['result']['username'] ?? '?')) : 'non raggiungibile — ' . h(tg_mask((string) ($me['description'] ?? '?'))) ?></b>
        · album «<?= h($TELEGRAM_ALBUM) ?>»</p>
      <?php if ($whUrl === tg_webhook_url()): ?>
        <p class="note">✓ Webhook collegato<?= !empty($wh['result']['pending_update_count']) ? ' · in coda: ' . (int) $wh['result']['pending_update_count'] : '' ?></p>
        <?php if (!empty($wh['result']['last_error_message'])): ?>
          <div class="warnbox">Ultimo errore di consegna (<?= h(date('d/m H:i', (int) ($wh['result']['last_error_date'] ?? 0))) ?>):
            <?= h((string) $wh['result']['last_error_message']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <p class="note">Webhook <b>non collegato</b><?= $whUrl !== '' ? ' (punta altrove: ' . h($whUrl) . ')' : '' ?>.</p>
      <?php endif; ?>
      <?php if ($apache === 'basic'): ?><div class="warnbox">Finché Apache chiede la password su <code>/gallery/api/</code>, Telegram non può consegnare i messaggi (vedi API).</div><?php endif; ?>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="act" value="tg_webhook"><input type="hidden" name="on" value="1">
        <button type="submit"><?= $whUrl === tg_webhook_url() ? 'Ricollega il webhook' : 'Collega il webhook' ?></button></form>
      <?php if ($whUrl !== ''): ?><form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="act" value="tg_webhook"><input type="hidden" name="on" value="0">
        <button type="submit" class="ghost">Scollega</button></form><?php endif; ?>

      <h2 style="margin-top:16px">Chi può mandare foto</h2>
      <?php if ($tgCode): ?>
        <div class="flash">Manda al bot: <code class="sharelink">/collega <?= h($tgCode) ?></code>
          <button type="button" class="ghost" data-copy="/collega <?= h($tgCode) ?>">copia</button>
          <span class="note">valido <?= intdiv(TG_PAIR_TTL, 60) ?> minuti, una volta sola</span></div>
      <?php endif; ?>
      <?php if (!$tgUsers): ?><p class="note">Nessuno: genera un codice e mandalo al bot dal tuo account Telegram.</p><?php else: ?>
      <table class="dt">
        <?php foreach ($tgUsers as $tu): ?>
        <tr><td><?= h($tu['name'] !== '' ? $tu['name'] : 'senza nome') ?> <span class="note">id <?= (int) $tu['tg_id'] ?> · dal <?= h(date('d/m/Y', (int) $tu['added_at'])) ?></span></td>
          <td class="num"><form method="post" data-confirm="Scollegare <?= h($tu['name']) ?> dal bot?"><?= csrf_field() ?>
            <input type="hidden" name="act" value="tg_user_remove"><input type="hidden" name="tg_id" value="<?= (int) $tu['tg_id'] ?>">
            <button type="submit" class="ghost">Scollega</button></form></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="tg_pair">
        <button type="submit" class="ghost">Genera un codice di collegamento</button></form>
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
  <td><?php if ($cov): ?><img class="cover-thumb" src="<?= htmlspecialchars(ui_thumb_url($cov)) ?>" alt=""><?php endif; ?></td>
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
    if (f.hasAttribute('data-bulk') && (f.elements.op.value === 'trash' || f.elements.op.value === 'private')) {
      var sel = [].slice.call(document.querySelectorAll('[data-bulk-item]:checked'));
      var busy = sel.filter(function (c) { return c.hasAttribute('data-in-use'); });
      msg = (f.elements.op.value === 'trash' ? 'Spostare nel cestino ' : 'Rendere private ') + (sel.length === 1 ? '1 immagine?' : sel.length + ' immagini?');
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

  // pulsanti "copia" (link a scadenza, codice del bot)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b || !window.gallerySnip) return;
    gallerySnip.copy(b.getAttribute('data-copy')).then(function () { b.textContent = 'copiato ✓'; },
                                                          function () { b.textContent = 'copia a mano'; });
  });

  // selezione multipla
  var bar = document.querySelector('[data-bulk]');
  if (!bar) return;
  var all = document.querySelector('[data-bulk-all]'), count = bar.querySelector('[data-bulk-count]');
  var go = bar.querySelector('[data-bulk-go]'), op = bar.querySelector('[data-bulk-op]'), val = bar.querySelector('[data-bulk-value]');
  var items = [].slice.call(document.querySelectorAll('[data-bulk-item]'));
  var HINT = { move: 'album (vuoto: senza album)', tag: 'etichetta da aggiungere', untag: 'etichetta da togliere', trash: '', 'private': '', 'public': '' };
  function refresh() {
    var n = items.filter(function (c) { return c.checked; }).length;
    count.textContent = n ? n + (n === 1 ? ' selezionata' : ' selezionate') : 'nessuna selezionata';
    go.disabled = !n || ((op.value === 'tag' || op.value === 'untag') && !val.value.trim());
    all.checked = n > 0 && n === items.length;
    all.indeterminate = n > 0 && n < items.length;
    bar.classList.toggle('on', n > 0);
  }
  function setOp() {
    val.hidden = !HINT[op.value];
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
