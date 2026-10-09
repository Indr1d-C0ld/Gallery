<?php
if (defined('GALLERY_THEME_LOADED')) return;
define('GALLERY_THEME_LOADED', 1);
/* =========================================================================
 * _theme.php – tema condiviso "Contact Sheet / Provini fotografici"
 * Uso:
 *   theme_head('Titolo', 'sottotitolo');   ... contenuto ...   theme_foot();
 * Nessuna dipendenza esterna, nessun font remoto.
 * ========================================================================= */

function theme_head(string $title, string $subtitle = '', string $variant = 'public'): void {
  $t = htmlspecialchars($title, ENT_QUOTES);
  $s = htmlspecialchars($subtitle, ENT_QUOTES);
  ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title><?= $t ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script>
/* applica la scelta di tema salvata prima del primo paint (niente flash) */
try{var _t=localStorage.getItem('gallery-theme');
if(_t==='light'||_t==='dark')document.documentElement.setAttribute('data-theme',_t);}catch(e){}
</script>
<style>
:root{
  --paper:#f5f0e4; --paper-2:#efe8d6; --edge:#e2d8bf;
  --ink:#2b2620; --muted:#8b8069; --rule:#d8ccb0;
  --frame:#fffdf6; --frame-line:rgba(43,38,32,.14);
  --grease:#c0392b; --accent:#1f4e79; --accent-ink:#14385a;
  --ok-bg:#e8f2e2; --ok-line:#b9d6a6;
  --well:#e9e2cd;                    /* fondo dietro le immagini */
  --mat:#ffffff;                     /* passe-partout thumb in admin */
  --glow:rgba(255,255,255,.5);       /* riflesso carta nello sfondo */
  --grid:rgba(0,0,0,.025);           /* reticolo di fondo */
  --shadow:0 10px 22px -12px rgba(40,33,20,.45);
  --mono:ui-monospace,"SF Mono","Cascadia Mono","JetBrains Mono",Menlo,Consolas,monospace;
  --serif:"Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,"Times New Roman",serif;
}
/* ---- Camera oscura ----
 * Regole (in ordine di precedenza crescente):
 *  1. OS in dark E nessuna scelta manuale, oppure scelta manuale = dark
 *  2. scelta manuale = light  ->  resta il blocco :root chiaro qui sopra
 * Il set di token è duplicato perché una regola non può stare
 * contemporaneamente dentro e fuori da @media.
 */
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    --paper:#181410; --paper-2:#211b15; --edge:#3b322a;
    --ink:#ece2d0; --muted:#9c8f79; --rule:#372f28;
    --frame:#241e17; --frame-line:rgba(255,248,235,.13);
    --grease:#e26a5b; --accent:#8bb6e6; --accent-ink:#aecce8;
    --ok-bg:#1d2a1a; --ok-line:#3d5834;
    --well:#100c14; --mat:#0f0c09;
    --glow:rgba(255,224,178,.05);
    --grid:rgba(255,255,255,.03);
    --shadow:0 12px 26px -12px rgba(0,0,0,.7);
  }
}
:root[data-theme="dark"]{
  --paper:#181410; --paper-2:#211b15; --edge:#3b322a;
  --ink:#ece2d0; --muted:#9c8f79; --rule:#372f28;
  --frame:#241e17; --frame-line:rgba(255,248,235,.13);
  --grease:#e26a5b; --accent:#8bb6e6; --accent-ink:#aecce8;
  --ok-bg:#1d2a1a; --ok-line:#3d5834;
  --well:#100c14; --mat:#0f0c09;
  --glow:rgba(255,224,178,.05);
  --grid:rgba(255,255,255,.03);
  --shadow:0 12px 26px -12px rgba(0,0,0,.7);
}
*{box-sizing:border-box}
figure{margin:0}   /* reset margine UA di default (16px 40px) su <figure class="frame"> */
html{-webkit-text-size-adjust:100%}
body{
  margin:0; padding:28px 20px 64px; color:var(--ink);
  font-family:var(--serif); font-size:16px; line-height:1.5;
  background:var(--paper);
  background-image:
    linear-gradient(0deg,var(--grid) 1px,transparent 1px),
    linear-gradient(90deg,var(--grid) 1px,transparent 1px),
    radial-gradient(circle at 20% 10%,var(--glow),transparent 60%);
  background-size:44px 44px,44px 44px,auto;
}
@media (prefers-reduced-motion:no-preference){
  body{transition:background-color .2s ease,color .2s ease}
}
.wrap{max-width:1220px;margin:0 auto}

/* ---- Masthead ---- */
.mast{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 22px;
  border-bottom:2px solid var(--ink);padding-bottom:12px;margin-bottom:18px}
.mast h1{font-size:30px;letter-spacing:.14em;text-transform:uppercase;margin:0;font-weight:600}
.mast .stamp{font-family:var(--mono);font-size:11px;letter-spacing:.18em;text-transform:uppercase;
  color:var(--grease);border:1.5px dashed var(--grease);border-radius:3px;padding:4px 8px;transform:rotate(-1.2deg)}
.mast .sub{font-family:var(--mono);font-size:12px;color:var(--muted);margin-left:auto;letter-spacing:.06em}

/* ---- Toggle tema ---- */
.theme-toggle{font-family:var(--mono);font-size:12px;letter-spacing:.05em;text-transform:uppercase;
  background:var(--paper-2);color:var(--accent-ink);border:1px solid var(--edge);border-radius:6px;
  padding:6px 10px;cursor:pointer;line-height:1;margin-left:auto}
.theme-toggle:hover{background:var(--frame);color:var(--accent-ink)}
.theme-toggle .lbl-dark{display:none}
:root[data-theme="dark"] .theme-toggle .lbl-dark{display:inline}
:root[data-theme="dark"] .theme-toggle .lbl-light{display:none}
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]) .theme-toggle .lbl-dark{display:inline}
  :root:not([data-theme="light"]) .theme-toggle .lbl-light{display:none}
}

/* ---- Toolbar ---- */
.bar{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;margin:14px 0}
.tabs{display:flex;flex-wrap:wrap;gap:6px}
.tab{font-family:var(--mono);font-size:12px;letter-spacing:.04em;text-decoration:none;color:var(--accent-ink);
  background:var(--paper-2);border:1px solid var(--edge);border-bottom-color:var(--rule);
  padding:5px 11px;border-radius:7px 7px 3px 3px}
.tab:hover{background:var(--frame)}
.tab.on{background:var(--ink);color:var(--paper);border-color:var(--ink)}
.tab .n{opacity:.6;margin-left:4px}

form.search{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto}
input[type=text],input[type=search],input:not([type]){
  font-family:var(--mono);font-size:13px;color:var(--ink);background:var(--frame);
  border:1px solid var(--edge);border-radius:6px;padding:7px 10px}
input:focus{outline:2px solid var(--accent);outline-offset:1px}
button,.btn{font-family:var(--mono);font-size:12px;letter-spacing:.05em;text-transform:uppercase;
  color:var(--paper);background:var(--ink);border:1px solid var(--ink);border-radius:6px;
  padding:7px 12px;cursor:pointer}
button.ghost,.btn.ghost{background:transparent;color:var(--ink)}
button:hover{background:var(--accent-ink);border-color:var(--accent-ink);color:var(--paper)}
a{color:var(--accent)}

.note{font-family:var(--mono);font-size:12px;color:var(--muted);margin:6px 0}
.flash{background:var(--ok-bg);border:1px solid var(--ok-line);border-radius:8px;
  padding:10px 12px;margin:12px 0;font-family:var(--mono);font-size:13px}

/* ---- Upload slot ---- */
.slot{border:1.5px dashed var(--edge);background:var(--paper-2);border-radius:10px;
  padding:12px 14px;margin:14px 0}
.slot h2{font-family:var(--mono);font-size:12px;letter-spacing:.14em;text-transform:uppercase;
  color:var(--muted);margin:0 0 10px}
.slot form{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.slot input[type=file]{font-family:var(--mono);font-size:12px}

hr.rule{border:none;border-top:1px solid var(--rule);margin:18px 0}

/* ---- Contact sheet grid ---- */
.sheet{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:20px}
.frame{background:var(--frame);border:1px solid var(--frame-line);border-radius:2px;
  padding:9px 9px 0;box-shadow:var(--shadow);transition:transform .16s ease,box-shadow .16s ease}
.frame:nth-child(4n+1){transform:rotate(-.5deg)}
.frame:nth-child(4n+3){transform:rotate(.5deg)}
.frame:hover{transform:rotate(0) translateY(-4px);box-shadow:0 18px 30px -14px rgba(40,33,20,.5);z-index:3}
.frame .shot{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:var(--well);
  border:1px solid var(--frame-line);cursor:zoom-in}
.cap{font-family:var(--mono);padding:8px 2px 10px}
.cap .row1{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);letter-spacing:.04em}
.cap .ttl{font-size:12px;color:var(--grease);text-transform:uppercase;letter-spacing:.05em;
  margin:3px 0 1px;min-height:1.2em;word-break:break-word}
.cap .dim{font-size:10.5px;color:var(--muted)}
.cap details{margin-top:6px}
.cap summary{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent-ink);cursor:pointer;list-style:none}
.cap summary::-webkit-details-marker{display:none}
.cap .copies{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.cap .copies button{font-size:9.5px;padding:4px 7px;letter-spacing:.06em}

/* ---- Admin worksheet ---- */
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table.ws{border-collapse:collapse;width:100%;min-width:640px;font-family:var(--mono);font-size:12px;background:var(--frame)}
table.ws th,table.ws td{border:1px solid var(--edge);padding:8px;vertical-align:top}
table.ws th{background:var(--paper-2);text-transform:uppercase;letter-spacing:.08em;font-size:10.5px;color:var(--muted)}
table.ws tr:nth-child(even) td{background:var(--paper-2)}
table.ws img{max-width:150px;height:auto;border:1px solid var(--frame-line);padding:4px;background:var(--mat)}
table.ws .mini{display:flex;flex-direction:column;gap:4px}
table.ws code{background:var(--paper-2);padding:1px 4px;border-radius:3px;word-break:break-all}
.act-grid{display:flex;flex-direction:column;gap:6px}
.act-grid form{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.act-grid input[type=text]{width:96px;padding:5px 7px}

/* ---- Footer ---- */
.foot{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;
  margin-top:24px;border-top:2px solid var(--ink);padding-top:10px;
  font-family:var(--mono);font-size:11px;color:var(--muted);letter-spacing:.06em}
.foot .pager a,.foot .pager span{margin:0 3px}
.foot .arc{color:var(--grease);border:1.5px dashed var(--grease);padding:3px 7px;border-radius:3px;transform:rotate(.8deg)}

/* ---- Lightbox ---- */
.lb{position:fixed;inset:0;background:rgba(28,24,18,.92);display:none;z-index:99;
  align-items:center;justify-content:center;padding:28px}
.lb.on{display:flex}
/* la lightbox è "camera oscura" in entrambi i temi: cromia fissa */
.lb img{max-width:94vw;max-height:82vh;background:#f7f2e6;padding:10px;border:1px solid #000;box-shadow:0 30px 60px -20px #000}
.lb .lb-cap{position:absolute;bottom:16px;left:0;right:0;text-align:center;color:#f2ece0;
  font-family:var(--mono);font-size:12px;letter-spacing:.05em}
.lb button{position:absolute;background:rgba(20,17,12,.55);border:1px solid #f2ece0;color:#f2ece0;
  font-family:var(--mono);font-size:12px;padding:6px 12px;cursor:pointer;line-height:1}
.lb button:hover{background:rgba(20,17,12,.85)}
.lb [data-lb="close"]{top:16px;right:16px}
.lb .nav{top:50%;transform:translateY(-50%);width:44px;height:56px;font-size:24px;padding:0}
.lb .nav.prev{left:16px;right:auto}
.lb .nav.next{right:16px;left:auto}
.lb.single .nav{display:none}
/* ---- Snippet (formati definiti nel JavaScript in fondo) ---- */
[hidden]{display:none!important}
select{font-family:var(--mono);font-size:12px;color:var(--ink);background:var(--frame);
  border:1px solid var(--edge);border-radius:6px;padding:6px 8px}
.fmt{font-family:var(--mono);font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);
  display:inline-flex;gap:6px;align-items:center}
.snip{margin-top:6px}
.snip .snip-copy{font-size:10px;padding:4px 8px;letter-spacing:.05em}
.snip details{margin-top:5px}
.snip summary{font-family:var(--mono);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;
  color:var(--accent-ink);cursor:pointer;list-style:none}
.snip summary::-webkit-details-marker{display:none}
.snip .copies{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.snip .copies button{font-size:9.5px;padding:4px 7px;letter-spacing:.06em}
.flash .snip{margin-top:8px}

/* ---- Caricamento: vassoio, avanzamento, zona di rilascio ---- */
.up-status{font-family:var(--mono);font-size:12px;margin:10px 0 0}
.up-status .up-done{color:var(--ink);background:var(--ok-bg);border:1px solid var(--ok-line);border-radius:5px;padding:3px 7px}
.up-status .up-warn{color:var(--grease)}
.tray{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
.up-item{display:flex;gap:10px;align-items:flex-start;background:var(--frame);border:1px solid var(--edge);
  border-radius:8px;padding:8px}
.up-thumb{width:64px;height:64px;object-fit:cover;background:var(--well);border:1px solid var(--frame-line);flex:none}
.up-thumb:not([src]){visibility:hidden}
.up-body{flex:1;min-width:0;font-family:var(--mono);font-size:12px}
.up-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.up-bar{height:6px;background:var(--paper-2);border:1px solid var(--edge);border-radius:3px;overflow:hidden;margin:6px 0 4px}
.up-bar i{display:block;height:100%;width:0;background:var(--accent)}
@media (prefers-reduced-motion:no-preference){.up-bar i{transition:width .15s ease}}
.up-msg{color:var(--muted)}
.up-item.ok .up-bar i{background:var(--ok-line)}
.up-item.dup .up-bar i{background:var(--muted)}
.up-item.err{border-color:var(--grease)}
.up-item.err .up-bar i{background:var(--grease)}
.up-item.err .up-msg{color:var(--grease)}
.dropzone{position:fixed;inset:0;z-index:98;display:flex;align-items:center;justify-content:center;
  background:rgba(28,24,18,.6);pointer-events:none}
.dropzone span{font-family:var(--mono);font-size:16px;letter-spacing:.14em;text-transform:uppercase;color:#f2ece0;
  border:2px dashed #f2ece0;border-radius:12px;padding:28px 40px;background:rgba(20,17,12,.55)}

/* ---- Etichette, album, azioni multiple (Tranche 3) ---- */
.chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 10px}
.chip{font-family:var(--mono);font-size:11px;letter-spacing:.03em;text-decoration:none;color:var(--accent-ink);
  background:var(--frame);border:1px solid var(--edge);border-radius:999px;padding:3px 10px}
.chip:hover{background:var(--paper-2)}
.chip.on{background:var(--accent-ink);border-color:var(--accent-ink);color:var(--paper)}
.chip .n{opacity:.6;margin-left:5px}
.chip-off{font-family:var(--mono);font-size:11px;color:var(--grease)}
.chips.small{margin:6px 0 0;gap:4px}
.chips.small .chip{font-size:9.5px;padding:1px 7px}
.album-desc{font-size:15px;color:var(--ink);max-width:70ch;margin:4px 0 12px;border-left:3px solid var(--grease);padding-left:12px}
.album-card{display:block;text-decoration:none;color:inherit}
.album-card .cap{display:flex;flex-direction:column;gap:2px}
.album-card .desc{font-family:var(--serif);font-size:13px;color:var(--muted);line-height:1.35;margin-top:4px}
.adminnav{margin:0 0 4px}
.bulkbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0;padding:8px 10px;
  border:1px dashed var(--edge);border-radius:8px;background:var(--paper-2);font-family:var(--mono);font-size:12px}
.bulkbar.on{border-style:solid;border-color:var(--accent);position:sticky;top:0;z-index:5;box-shadow:var(--shadow)}
.bulk-n{color:var(--muted);min-width:11em}
.bulkbar.on .bulk-n{color:var(--ink)}
button.danger,.btn.danger{background:var(--grease);border-color:var(--grease);color:#fff}
button:disabled{opacity:.45;cursor:not-allowed}
textarea{font-family:var(--serif);font-size:14px;color:var(--ink);background:var(--frame);border:1px solid var(--edge);
  border-radius:6px;padding:6px 8px;width:100%;min-width:220px;resize:vertical}
table.ws img.cover-thumb{width:120px;height:90px;object-fit:cover}
table.ws td:first-child input[type=checkbox],table.ws th:first-child input[type=checkbox]{width:18px;height:18px}

/* ---- Uso e cruscotto (Tranche 4) ---- */
.use{display:inline-block;font-family:var(--mono);font-size:10.5px;color:var(--muted);border:1px solid var(--edge);
  border-radius:999px;padding:1px 7px;margin-top:4px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:top}
.use.on{color:var(--accent-ink);border-color:var(--accent);background:var(--frame)}
.use.off{border-style:dashed}
.use.warn{color:var(--grease);border-color:var(--grease);white-space:normal}
.warnbox{border:1.5px solid var(--grease);background:var(--frame);border-radius:8px;padding:10px 12px;margin:12px 0;
  font-family:var(--mono);font-size:12.5px}
.warnbox p{margin:0 0 6px}
.warnbox ul{margin:6px 0 10px;padding-left:18px;word-break:break-word}
.warnbox form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.dash{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:18px;margin-top:12px}
.card{background:var(--frame);border:1px solid var(--edge);border-radius:8px;padding:12px 14px;box-shadow:var(--shadow);min-width:0}
.card.wide{grid-column:1/-1}
.card h2{font-family:var(--mono);font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:6px}
.kpi b{display:block;font-family:var(--serif);font-size:28px;font-weight:normal;line-height:1.1;color:var(--ink)}
.kpi span{font-family:var(--mono);font-size:11px;color:var(--muted)}
table.dt{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12px}
table.dt td,table.dt th{padding:5px 6px;border-bottom:1px dashed var(--edge);vertical-align:middle;text-align:left}
table.dt th{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:normal}
table.dt .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
table.dt .lbl{word-break:break-word}
table.dt img{width:44px;height:44px;object-fit:cover;border:1px solid var(--frame-line);background:var(--mat);padding:2px;display:block}
.hbar{display:block;height:8px;min-width:48px;background:var(--paper-2);border-radius:2px;overflow:hidden}
.hbar i{display:block;height:100%;background:var(--accent)}
.hbar.mut i{background:var(--muted)}
.cols{display:flex;align-items:flex-end;gap:2px;height:110px;border-bottom:1px solid var(--edge)}
.cols span{flex:1;min-width:2px;background:var(--accent);border-radius:2px 2px 0 0}
.cols span.zero{background:var(--edge);height:2px!important}
.cols-x{display:flex;justify-content:space-between;font-family:var(--mono);font-size:10px;color:var(--muted);margin-top:4px}

/* =========================================================================
 * Mobile & tablet (telefono, tablet, o comunque puntatore touch)
 * Regole SOLO additive/di ingrandimento: non tocca nulla fuori da qui,
 * quindi il monitor desktop resta identico a prima.
 * ========================================================================= */
@media (max-width:768px), (pointer:coarse){
  /* niente zoom automatico di iOS Safari al focus (richiede font-size >=16px) */
  input[type=text],input[type=search],input:not([type]){font-size:16px;padding:10px 12px}
  .act-grid input[type=text]{font-size:16px;padding:8px 9px}

  /* bersagli tattili più larghi (linee guida ~40-44px) */
  button,.btn{padding:10px 15px;font-size:13px}
  .tab{padding:8px 14px;font-size:13px}
  .theme-toggle{padding:10px 13px}
  .cap .copies button,.snip .copies button{padding:8px 11px;font-size:11px}
  .snip .snip-copy{padding:8px 12px;font-size:11px}
  select{font-size:16px;padding:9px 10px}
  .snip summary{display:inline-block;padding:6px 0}
  .chip{padding:6px 12px;font-size:12px}
  .chips.small .chip{padding:4px 9px;font-size:11px}
  textarea{font-size:16px}
  .cap summary{display:inline-block;padding:6px 0}
  .lb button{padding:10px 15px}
  .lb .nav{width:52px}

  /* niente hover "appiccicoso" sul tocco: il sollevamento resta solo al tap/apertura */
  .frame:hover{transform:none;box-shadow:var(--shadow)}
}
@media (max-width:560px){.mast h1{font-size:22px}body{padding:18px 12px 52px}}
</style>
</head>
<body>
<div class="wrap">
<header class="mast">
  <h1><?= $t ?></h1>
  <span class="stamp">Provini · Contact Sheet</span>
  <?php if ($s !== ''): ?><span class="sub"><?= $s ?></span><?php endif; ?>
</header>
<?php
}

/* Bottone toggle tema — da inserire nella .bar delle pagine */
function theme_toggle(): string {
  return '<button type="button" class="theme-toggle" data-theme-toggle'
       . ' aria-label="Alterna tema chiaro/scuro" title="Alterna tema chiaro/scuro">'
       . '<span class="lbl-light">&#9790; scuro</span>'
       . '<span class="lbl-dark">&#9728; chiaro</span>'
       . '</button>';
}

/* Piè di pagina.
 * $pager: null oppure ['page'=>int, 'pages'=>int, 'prev'=>?string, 'next'=>?string,
 *                      'label'=>string]  — la marcatura viene costruita QUI e
 * ogni valore passa per htmlspecialchars. In precedenza questa funzione
 * stampava HTML grezzo ricevuto dai chiamanti: nessuno lo sfruttava, ma era
 * un invito all'errore alla prima occasione in cui vi fosse finito dentro un
 * titolo preso dal database (rilievo #10 dell'audit).
 */
function theme_foot(?array $pager = null, string $footRight = 'Archivio privato', bool $withLightbox = true): void {
  $left = '&nbsp;';

  if ($pager !== null) {
    $page  = (int)($pager['page']  ?? 1);
    $pages = (int)($pager['pages'] ?? 1);

    if ($pages > 1) {
      $prev = $pager['prev'] ?? null;
      $next = $pager['next'] ?? null;
      $left  = '<span class="pager">';
      $left .= $prev
        ? '<a href="' . htmlspecialchars((string)$prev, ENT_QUOTES) . '">‹ prec</a>'
        : '<span>‹ prec</span>';
      $left .= ' &nbsp; pagina ' . $page . ' / ' . $pages . ' &nbsp; ';
      $left .= $next
        ? '<a href="' . htmlspecialchars((string)$next, ENT_QUOTES) . '">succ ›</a>'
        : '<span>succ ›</span>';
      $left .= '</span>';
    } elseif (!empty($pager['label'])) {
      $left = htmlspecialchars((string)$pager['label'], ENT_QUOTES);
    }
  }
  ?>
<footer class="foot">
  <div><?= $left ?></div>
  <div class="arc"><?= htmlspecialchars($footRight, ENT_QUOTES) ?></div>
</footer>
</div><!-- /wrap -->
<?php if ($withLightbox): ?>
<div class="lb" id="lb" aria-hidden="true">
  <button type="button" data-lb="close" aria-label="Chiudi">ESC ✕</button>
  <button type="button" class="nav prev" data-lb="prev" aria-label="Precedente">‹</button>
  <img id="lb-img" src="" alt="">
  <button type="button" class="nav next" data-lb="next" aria-label="Successivo">›</button>
  <div class="lb-cap" id="lb-cap"></div>
</div>
<?php endif; ?>
<script>
(function(){
  /* ---- copia negli appunti ---- */
  document.addEventListener('click',function(e){
    var b=e.target.closest('[data-copy]'); if(!b) return;
    var txt=b.getAttribute('data-copy');
    navigator.clipboard.writeText(txt).then(function(){
      var old=b.textContent; b.textContent='copiato ✓';
      setTimeout(function(){b.textContent=old;},1100);
    });
  });

  /* ---- toggle tema chiaro/scuro ---- */
  document.addEventListener('click',function(e){
    var t=e.target.closest('[data-theme-toggle]'); if(!t) return;
    var root=document.documentElement, cur=root.getAttribute('data-theme');
    var dark = cur ? cur==='dark'
                   : (window.matchMedia && matchMedia('(prefers-color-scheme:dark)').matches);
    var next = dark ? 'light' : 'dark';
    root.setAttribute('data-theme',next);
    try{ localStorage.setItem('gallery-theme',next); }catch(err){}
  });

  /* ---- snippet: i formati sono definiti SOLO qui ----
   * Ogni immagine porta i suoi dati in data-snip (JSON preparato da
   * snippet_data() in _images.php); da li' nascono URL, Markdown, BBCode,
   * HTML, Hugo. L'ultimo formato usato resta salvato nel browser. */
  var esc = {
    html: function(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); },
    md:   function(s){ return String(s).replace(/([\\\[\]])/g,'\\$1'); },
    q:    function(s){ return String(s).replace(/"/g,"'"); }
  };
  function alt(s){ return s.alt || s.title || ''; }
  function wh(s){ return s.width ? ' width="'+s.width+'" height="'+s.height+'"' : ''; }
  /* Versione ridotta (WebP, /i/CODICE?w=640): la versione della pipeline
   * nell'indirizzo la rende cacheabile per sempre. */
  function sized(s, w, amp){ return s.url+'?w='+w+(s.pv ? (amp ? '&amp;' : '&')+'v='+s.pv : ''); }
  /* HTML responsive: i telefoni scaricano 480-640 px invece dell'originale.
   * La colonna dei contenuti del blog e' ~800 px. */
  function htmlImg(s){
    var src = s.url, set = '', sizes = s.sizes || [];
    if (sizes.length) {
      var col = Math.min(s.width, 800);
      set = ' srcset="'+sizes.map(function(w){ return sized(s, w, true)+' '+w+'w'; }).concat([s.url+' '+s.width+'w']).join(', ')+'"'
          + ' sizes="(max-width: '+col+'px) 100vw, '+col+'px"';
      var best = sizes.filter(function(w){ return w <= 1280; }).pop();
      if (best) src = sized(s, best, true);
    }
    return '<img src="'+src+'"'+set+' alt="'+esc.html(alt(s))+'"'+wh(s)+' loading="lazy" decoding="async">';
  }
  var FORMATS = [
    ['url',     'URL',                 function(s){ return s.url; }],
    ['md',      'Markdown · link',     function(s){ return '['+esc.md(s.title || s.alt || 'immagine')+']('+s.url+')'; }],
    ['mdimg',   'Markdown · immagine', function(s){ return '!['+esc.md(alt(s))+']('+s.url+')'; }],
    ['mdthumb', 'Markdown · miniatura',function(s){ return '[!['+esc.md(alt(s))+']('+s.thumb+')]('+s.url+')'; }],
    ['bb',      'BBCode',              function(s){ return '[img]'+s.url+'[/img]'; }],
    ['bbthumb', 'BBCode · miniatura',  function(s){ return '[url='+s.url+'][img]'+s.thumb+'[/img][/url]'; }],
    ['html',    'HTML',                function(s){ return htmlImg(s); }],
    ['hugo',    'Hugo · figure',       function(s){ return '{{< figure src="'+s.url+'" alt="'+esc.q(alt(s))+'"'+wh(s)+' >}}'; }],
    ['thumb',   'URL miniatura',       function(s){ return s.thumb; }]
  ];
  var FMT = {};
  FORMATS.forEach(function(f){ FMT[f[0]] = f; });
  var KEY = 'gallery-snippet';
  function current(){ var k = null; try{ k = localStorage.getItem(KEY); }catch(e){} return FMT[k] ? k : 'url'; }
  function setCurrent(k){ try{ localStorage.setItem(KEY, k); }catch(e){} sync(); }
  function sync(){
    var k = current();
    [].forEach.call(document.querySelectorAll('[data-snip-copy]'), function(b){
      if (!b.hasAttribute('data-busy')) b.textContent = 'copia · ' + FMT[k][1];
    });
    [].forEach.call(document.querySelectorAll('select[data-snip-format]'), function(sel){ sel.value = k; });
  }
  function render(root){
    [].forEach.call((root || document).querySelectorAll('[data-snip-all]'), function(box){
      if (box.children.length) return;
      FORMATS.forEach(function(f){
        var b = document.createElement('button');
        b.type = 'button'; b.setAttribute('data-fmt', f[0]); b.textContent = f[1];
        box.appendChild(b);
      });
    });
    [].forEach.call(document.querySelectorAll('select[data-snip-format]'), function(sel){
      if (!sel.options.length) FORMATS.forEach(function(f){ sel.add(new Option(f[1], f[0])); });
    });
    sync();
  }
  /* Clipboard API se c'e' e la concede; altrimenti (permesso negato, browser
   * incorporati, contesto non sicuro) il vecchio metodo con execCommand. */
  function legacyCopy(t){
    var ta = document.createElement('textarea'), done = false, prev = document.activeElement;
    ta.value = t; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.top = '-1000px';
    document.body.appendChild(ta); ta.select();
    try{ done = document.execCommand('copy'); }catch(e){}
    ta.remove();
    if (prev && prev.focus) prev.focus();
    return done ? Promise.resolve() : Promise.reject(new Error('copia non riuscita'));
  }
  function copyText(t){
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(t).catch(function(){ return legacyCopy(t); });
    }
    return legacyCopy(t);
  }
  function textOf(data, k){ return FMT[k || current()][2](data); }
  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-snip-copy],[data-fmt]'); if (!b) return;
    var box = b.closest('[data-snip]'), data = null;
    try{ data = JSON.parse(box.getAttribute('data-snip')); }catch(err){ return; }
    var k = b.getAttribute('data-fmt') || current();
    if (b.hasAttribute('data-fmt')) setCurrent(k);
    var main = box.querySelector('[data-snip-copy]') || b;
    copyText(textOf(data, k)).then(function(){ return 'copiato ✓'; }, function(){ return 'copia non riuscita'; })
      .then(function(msg){
        main.setAttribute('data-busy', ''); main.textContent = msg + ' · ' + FMT[k][1];
        setTimeout(function(){ main.removeAttribute('data-busy'); sync(); }, 1300);
      });
  });
  document.addEventListener('change', function(e){
    if (e.target.matches && e.target.matches('select[data-snip-format]')) setCurrent(e.target.value);
  });
  window.gallerySnip = {
    current: current, label: function(k){ return FMT[k || current()][1]; },
    text: textOf, copy: copyText, render: render
  };
  render(document);

  /* ---- lightbox ---- */
  var lb=document.getElementById('lb'); if(!lb) return;
  var img=document.getElementById('lb-img'), cap=document.getElementById('lb-cap');
  var shots=[].slice.call(document.querySelectorAll('[data-full]')), cur=-1;
  if(shots.length<2) lb.classList.add('single');
  function show(i){
    if(i<0||i>=shots.length) return; cur=i;
    var el=shots[i];
    img.src=el.getAttribute('data-full');
    img.alt=el.getAttribute('data-title')||'';
    cap.textContent=(el.getAttribute('data-title')||'')+'  ·  '+(el.getAttribute('data-meta')||'');
    lb.classList.add('on'); lb.setAttribute('aria-hidden','false');
  }
  function close(){lb.classList.remove('on');lb.setAttribute('aria-hidden','true');img.src='';}
  document.addEventListener('click',function(e){
    var s=e.target.closest('[data-full]');
    if(s){e.preventDefault();show(shots.indexOf(s));return;}
    var a=e.target.closest('[data-lb]'); if(!a) return;
    var k=a.getAttribute('data-lb');
    if(k==='close') close();
    else if(k==='prev') show(cur-1);
    else if(k==='next') show(cur+1);
  });
  lb.addEventListener('click',function(e){if(e.target===lb) close();});
  document.addEventListener('keydown',function(e){
    if(!lb.classList.contains('on')) return;
    if(e.key==='Escape') close();
    else if(e.key==='ArrowLeft') show(cur-1);
    else if(e.key==='ArrowRight') show(cur+1);
  });
})();
</script>
</body>
</html>
<?php
}
