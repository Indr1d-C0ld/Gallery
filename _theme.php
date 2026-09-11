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
  .cap .copies button{padding:8px 11px;font-size:11px}
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

function theme_foot(string $footLeft = '', string $footRight = 'Archivio privato', bool $withLightbox = true): void {
  ?>
<footer class="foot">
  <div><?= $footLeft ?: '&nbsp;' ?></div>
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
