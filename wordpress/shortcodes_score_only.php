<?php
/**
 * WordPress shortcodes — Score only
 *
 * Define AG_API_URL and AG_API_KEY in wp-config.php
 */
if (!defined('AG_API_URL') || !defined('AG_API_KEY')) return;

function ag_fetch(array $params) {
  $ttl = defined('AG_CACHE_TTL') ? (int)AG_CACHE_TTL : 60;
  $cacheKey = 'ag_' . md5(json_encode($params));
  $cached = get_transient($cacheKey);
  if ($cached !== false) return $cached;

  $url = add_query_arg(array_merge($params, ['key' => AG_API_KEY]), AG_API_URL);
  $res = wp_remote_get($url, ['timeout' => 8]);
  if (is_wp_error($res)) return null;

  $body = wp_remote_retrieve_body($res);
  $json = json_decode($body, true);
  if (!is_array($json) || empty($json['ok'])) return null;

  set_transient($cacheKey, $json, $ttl);
  return $json;
}

function ag_table(array $headers, array $rows) {
  $th = '';
  foreach ($headers as $h) $th .= '<th>' . esc_html($h) . '</th>';

  $tb = '';
  foreach ($rows as $r) {
    $tb .= '<tr>';
    foreach ($r as $cell) $tb .= '<td>' . esc_html((string)$cell) . '</td>';
    $tb .= '</tr>';
  }
  return '<table class="ag-table"><thead><tr>' . $th . '</tr></thead><tbody>' . $tb . '</tbody></table>';
}

add_shortcode('ag_recent', function($atts) {
  $atts = shortcode_atts(['limit' => '12'], $atts);
  $limit = max(1, min(200, (int)$atts['limit']));
  $json = ag_fetch(['type' => 'recent', 'limit' => $limit]);
  if (!$json) return '<div>Erreur chargement.</div>';

  $rows = $json['data'] ?? [];
  $out = [];
  $i = 1;
  foreach ($rows as $r) $out[] = [$i++, $r['team'] ?? '', $r['score'] ?? 0];
  return ag_table(['#','Équipe','Score'], $out);
});

add_shortcode('ag_totals', function() {
  $json = ag_fetch(['type' => 'totals']);
  if (!$json) return '<div>Erreur chargement.</div>';

  $d = $json['data'] ?? [];
  $best = $d['best'] ?? null;

  $html = '<div class="ag-totals">';
  $html .= '<div class="ag-card"><div class="ag-label">Parties</div><div class="ag-value">' . esc_html((string)($d['total_games'] ?? 0)) . '</div></div>';
  $html .= '<div class="ag-card"><div class="ag-label">Score total</div><div class="ag-value">' . esc_html((string)($d['total_score'] ?? 0)) . '</div></div>';
  $html .= '</div>';

  if ($best) $html .= '<div class="ag-best">Meilleur: <strong>' . esc_html((string)($best['team'] ?? '')) . '</strong></div>';
  return $html;
});

add_shortcode('ag_top', function($atts) {
  $atts = shortcode_atts(['limit' => '10','period' => 'week','layout' => 'premium','title' => ''], $atts);

  $limit = max(3, min(200, (int)$atts['limit']));
  $period = strtolower(trim($atts['period']));
  if (!in_array($period, ['all','week','month','year'], true)) $period = 'all';

  $json = ag_fetch(['type' => 'top', 'limit' => $limit, 'window' => $period]);
  if (!$json) return '<div>Erreur chargement.</div>';

  $rows = $json['data'] ?? [];
  if (empty($rows)) return '<div>Aucune donnée.</div>';

  $out = [];
  $i = 1;
  foreach ($rows as $r) $out[] = [$i++, $r['team'] ?? '', $r['score'] ?? 0];
  return ag_table(['#','Équipe','Score'], $out);
});
