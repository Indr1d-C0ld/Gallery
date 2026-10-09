<?php
/* Elenco e ricerca: GET api/images.php?q=&folder=&tag=&page=&per=&trash=1&private=0|1
 * q accetta la stessa sintassi della galleria (folder:, title:, tag:, id:). */
require_once __DIR__ . "/../_api.php";
api_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_fail(405, 'solo GET');

$o = ['q' => get_str('q'), 'tag' => get_str('tag'), 'page' => get_int('page', 1, 1, 100000),
      'per' => get_int('per', 50, 1, 100), 'trash' => get_str('trash') === '1'];
if (isset($_GET['folder'])) $o['folder'] = norm_folder(get_str('folder'));
if (in_array(get_str('private'), ['0', '1'], true)) $o['private'] = get_str('private') === '1';
$res = gallery_search($o);
api_out(200, ['ok' => true, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'per' => $o['per'],
              'images' => array_map(fn($r) => api_image($r), $res['rows'])]);
