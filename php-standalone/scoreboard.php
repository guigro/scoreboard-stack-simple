<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function get_param(string $k, string $default = ''): string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default; }

function cache_path(string $key): string {
  $dir = __DIR__ . '/cache';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir . '/cache_' . $key . '.json';
}

function fetch_api(array $params): array {
  $params['key'] = AG_API_KEY;
  $url = AG_API_URL . (str_contains(AG_API_URL, '?') ? '&' : '?') . http_build_query($params);

  $ttl = defined('CACHE_TTL') ? (int)CACHE_TTL : 60;
  $cacheKey = md5(json_encode($params));
  $nocache = (isset($_GET['nocache']) && $_GET['nocache'] === '1');

  $cacheFile = cache_path($cacheKey);
  if (!$nocache && is_file($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age <= $ttl) {
      $raw = file_get_contents($cacheFile);
      $j = json_decode((string)$raw, true);
      if (is_array($j)) return $j;
    }
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($raw === false) return ['ok' => false, 'error' => 'cURL error: ' . $err];
  if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => 'HTTP ' . $code, 'body_head' => substr((string)$raw, 0, 200)];

  $j = json_decode((string)$raw, true);
  if (!is_array($j)) return ['ok' => false, 'error' => 'Invalid JSON', 'body_head' => substr((string)$raw, 0, 200)];

  @file_put_contents($cacheFile, json_encode($j));
  return $j;
}

function medal(int $rank): string { return $rank === 1 ? "🥇" : ($rank === 2 ? "🥈" : "🥉"); }

function render_table(array $rows, int $startRank = 1): string {
  $out = '<table class="ag-table"><thead><tr><th>#</th><th>Équipe</th><th>Score</th></tr></thead><tbody>';
  $i = $startRank;
  foreach ($rows as $r) {
    $out .= '<tr>';
    $out .= '<td>' . h((string)$i) . '</td>';
    $out .= '<td>' . h((string)($r["team"] ?? "")) . '</td>';
    $out .= '<td>' . h((string)($r["score"] ?? 0)) . '</td>';
    $out .= '</tr>';
    $i++;
  }
  $out .= '</tbody></table>';
  return $out;
}

$view = strtolower(get_param('view', 'top')); // top|recent|totals
$period = strtolower(get_param('period', defined('DEFAULT_PERIOD') ? DEFAULT_PERIOD : 'week'));
$limit = (int)get_param('limit', (string)(defined('DEFAULT_LIMIT') ? DEFAULT_LIMIT : 10));
$limit = max(1, min(200, $limit));

$kiosk = (get_param('kiosk', '') === '1');
if ($kiosk && defined('KIOSK_TOKEN') && KIOSK_TOKEN !== '') {
  $token = get_param('token', '');
  if (!hash_equals(KIOSK_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
  }
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

if ($view === 'recent') {
  $apiParams = ['type' => 'recent', 'limit' => $limit];
  $title = "Dernières parties";
} elseif ($view === 'totals') {
  $apiParams = ['type' => 'totals'];
  $title = "Totaux";
} else {
  if (!in_array($period, ['all','week','month','year'], true)) $period = 'all';
  $apiParams = ['type' => 'top', 'limit' => $limit, 'window' => $period];
  $title = "Classement (" . $period . ")";
}

$data = fetch_api($apiParams);
$updated = date('Y-m-d H:i:s');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo h($title); ?></title>
  <link rel="stylesheet" href="<?php echo h('assets/scoreboard.css'); ?>">
<?php if ($kiosk): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1><?php echo h($title); ?></h1>
      <div class="muted">MAJ: <?php echo h($updated); ?></div>
    </div>

<?php if (!is_array($data) || empty($data['ok'])): ?>
    <div class="panel" style="padding:16px">
      <div>Erreur API</div>
      <pre style="white-space:pre-wrap;color:rgba(255,255,255,.8)"><?php echo h(json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre>
    </div>
<?php else: ?>

<?php if ($view !== 'totals'): ?>
    <div class="panel">
      <div class="panel-title">Top 3</div>
      <div class="ag-top3">
<?php
      $rows = $data['data'] ?? [];
      $top3 = array_slice($rows, 0, 3);
      $rank = 1;
      foreach ($top3 as $r):
?>
        <div class="ag-topcard">
          <div class="ag-topcard__left">
            <span class="ag-medal-emoji" aria-hidden="true"><?php echo h(medal($rank)); ?></span>
            <div class="ag-rank">#<?php echo h((string)$rank); ?></div>
          </div>
          <div class="ag-topcard__main">
            <div class="ag-team"><?php echo h((string)($r['team'] ?? '')); ?></div>
            <div class="ag-stats">
              <div class="ag-stat"><span class="ag-stat__label">Score</span><span class="ag-stat__value"><?php echo h((string)($r['score'] ?? 0)); ?></span></div>
            </div>
          </div>
        </div>
<?php
        $rank++;
      endforeach;
?>
      </div>
      <div class="ag-rest">
<?php
      $rest = array_slice($rows, 3);
      if (!empty($rest)) echo render_table($rest, 4);
?>
      </div>
    </div>

<?php else: ?>
<?php $t = $data['data'] ?? []; ?>
    <div class="cards">
      <div class="card"><div class="label">Parties</div><div class="value"><?php echo h((string)($t['total_games'] ?? 0)); ?></div></div>
      <div class="card"><div class="label">Score total</div><div class="value"><?php echo h((string)($t['total_score'] ?? 0)); ?></div></div>
    </div>
<?php if (!empty($t['best'])): ?>
    <div class="panel" style="padding:16px;margin-top:12px">
      <div class="muted" style="margin-bottom:6px">Meilleur (score)</div>
      <div style="font-size:18px;font-weight:900"><?php echo h((string)($t['best']['team'] ?? '')); ?></div>
      <div class="muted">Score: <?php echo h((string)($t['best']['score'] ?? 0)); ?></div>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
  </div>
</body>
</html>
