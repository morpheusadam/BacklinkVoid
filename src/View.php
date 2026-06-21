<?php
/**
 * View — every HTML page the app renders: the top navigation, the results
 * report (with PDF/Excel/CSV export + Google Disavow tab), the input form
 * (with the loading overlay), the Backlink Notif page, and the login page.
 *
 * Presentation only; it pulls data from Engine/Monitor/Security but performs no
 * analysis or storage of its own.
 */
class View
{
    /** Top-of-page navigation shared by the Scorer form and the Notif tab. */
    public static function topNav($active): string
    {
        $a = $active === 'notif';
        $logout = Security::authEnabled()
            ? '<a href="?logout=1" class="logout" title="Log out of this browser">Log out</a>' : '';
        return '<nav class="topnav">'
            . '<a href="?"' . ($a ? '' : ' class="active"') . '>Scorer</a>'
            . '<a href="?tab=notif"' . ($a ? ' class="active"' : '') . '>Backlink Notif</a>'
            . $logout
            . '</nav>';
    }

    /**
     * Human sentence explaining the submitted-URLs → unique-rows collapse, e.g.
     * "Submitted 462 URLs → 108 unique domains (354 duplicate URLs merged)."
     * Shown on the building page and the report so the count is never unexplained.
     */
    public static function countLine($submitted, $unique, $merged, $per_url): string
    {
        $submitted = (int)$submitted;
        $unique = (int)$unique;
        $merged = (int)$merged;
        if ($submitted <= 0) {
            return '';
        }
        $u = $submitted === 1 ? 'URL' : 'URLs';
        if ($per_url) {
            $unit = $unique === 1 ? 'unique URL' : 'unique URLs';
            $tail = $merged > 0 ? ' (' . $merged . ' exact-duplicate ' . ($merged === 1 ? 'URL' : 'URLs') . ' merged)' : '';
            return 'Submitted ' . $submitted . ' ' . $u . ' → ' . $unique . ' ' . $unit . ' analysed' . $tail . '.';
        }
        $unit = $unique === 1 ? 'unique domain' : 'unique domains';
        $tail = $merged > 0 ? ' (' . $merged . ' duplicate ' . ($merged === 1 ? 'URL' : 'URLs') . ' merged)' : '';
        return 'Submitted ' . $submitted . ' ' . $u . ' → ' . $unique . ' ' . $unit . $tail . '.';
    }

    /** The full results report: prospects table, avoid list, exports, disavow. */
    public static function report($r, $opts): string
    {
        $prospects = $r['prospects'];
        $avoid = $r['avoid'];
        $avoidcount = count($avoid);
        $avg = $prospects ? round(array_sum(array_map(fn($b) => $b['score'], $prospects)) / count($prospects), 1) : 0.0;
        $friendly_n = count(array_filter($prospects, fn($b) => $b['link_friendly']));
        $target = Support::h($opts['target_url']);
        $profile = Support::h(implode(', ', array_slice($r['niche'], 0, 18)) ?: '—');
        $generated = date('Y-m-d H:i:s');
        $debug_badge = Debug::badge();

        // Input-collapse transparency (FIX 1/4): explain how the input list became
        // the analysed count, and whether rows are per-domain or per-URL.
        $per_url = !empty($opts['per_url']);
        $count_line = self::countLine($opts['submitted'] ?? 0, $opts['unique'] ?? ($r['total'] ?? 0), $opts['merged'] ?? 0, $per_url);
        $count_html = $count_line !== '' ? '<p class="muted small">' . Support::h($count_line) . '</p>' : '';
        $checked_label = $per_url ? 'URLs checked' : 'Domains checked';

        $live = !empty($r['live']);
        $i = count($prospects);   // "Good prospects" card + initial "Showing 0 of N"

        // FIX (render weight) — the shell ships NO prospect rows inline. Embedding
        // the whole dataset (~135 KB) stalled the browser's HTML parse BEFORE
        // report.js could run (the report.js request lagged ~76 s in the live log).
        // Rows are now fetched in small slices from ?report=data and rendered
        // incrementally, so the first response stays tiny (<~20 KB). The compact
        // row shape lives in View::prospectRows(), reused by the data endpoint.

        $avoid_sorted = $avoid;
        usort($avoid_sorted, fn($a, $b) => strcmp($a['registrable_domain'], $b['registrable_domain']));
        $avoid_rows = '';
        foreach ($avoid_sorted as $bl) {
            $dom = Support::h($bl['registrable_domain'] ?: $bl['raw']);
            $why = Support::h(implode('; ', $bl['avoid_reasons']));
            $avoid_rows .= '<tr><td>' . $dom . '</td><td class="small muted">' . $why . '</td></tr>';
        }
        if ($avoid_rows === '') {
            $avoid_rows = '<tr><td colspan="2" class="muted" style="padding:1rem;text-align:center">Nothing excluded.</td></tr>';
        }

        $total = $r['total'];

        // ----- Backlink-removal risk audit: DISAVOW / REVIEW / KEEP ----------
        // Conservative by design: only HARD, confirmable spam auto-disavows;
        // soft signals go to REVIEW; everything else is KEEP. Covers EVERY
        // domain (the audit is string-based, independent of the live fetch).
        $audit_disavow = [];
        $audit_review = [];
        $keep_count = 0;
        foreach (array_merge($prospects, $avoid) as $bl) {
            $dom = $bl['registrable_domain'];
            if ($dom === '') {
                continue;
            }
            $a = $bl['audit'] ?? null;
            if (!$a) {
                continue;
            }
            if ($a['tier'] === 'disavow') {
                if (!isset($audit_disavow[$dom])) {
                    $audit_disavow[$dom] = $a;
                }
            } elseif ($a['tier'] === 'review') {
                if (!isset($audit_review[$dom]) && !isset($audit_disavow[$dom])) {
                    $audit_review[$dom] = $a;
                }
            } else {
                $keep_count++;
            }
        }
        ksort($audit_disavow);
        ksort($audit_review);
        $disavow_count = count($audit_disavow);
        $review_count = count($audit_review);

        // DISAVOW table rows (domain | matched signals | confidence).
        $disavow_rows = '';
        foreach ($audit_disavow as $dom => $a) {
            $disavow_rows .= '<tr><td>' . Support::h($dom) . '</td><td class="small">'
                . Support::h(implode('; ', $a['signals'])) . '</td><td class="small">'
                . Support::h($a['confidence'] ?: 'high') . '</td></tr>';
        }
        if ($disavow_rows === '') {
            $disavow_rows = '<tr><td colspan="3" class="muted" style="padding:1rem;text-align:center">No hard-spam domains — nothing to disavow.</td></tr>';
        }

        // REVIEW table rows (domain | risk | reasons).
        $review_rows = '';
        foreach ($audit_review as $dom => $a) {
            $review_rows .= '<tr><td>' . Support::h($dom) . '</td><td data-sort="' . (int)$a['risk'] . '">'
                . (int)$a['risk'] . '</td><td class="small muted">'
                . Support::h(implode('; ', $a['signals'])) . '</td></tr>';
        }
        if ($review_rows === '') {
            $review_rows = '<tr><td colspan="3" class="muted" style="padding:1rem;text-align:center">Nothing to review.</td></tr>';
        }

        // The ready-to-upload Google Disavow file = DISAVOW tier ONLY.
        $disavow_text  = '# Disavow generated ' . $generated . "\n";
        $disavow_text .= '# Site: ' . $opts['target_url'] . "\n";
        $disavow_text .= "# Source: Backlink Prospect Scorer — removal audit (hard-spam only)\n";
        $disavow_text .= "# Upload in Google Search Console > Disavow Links\n";
        $disavow_text .= "# (https://search.google.com/search-console/disavow-links).\n";
        $disavow_text .= "# Review every entry first — disavowing healthy links can hurt rankings.\n#\n";
        if ($disavow_count === 0) {
            $disavow_text .= "# No hard-spam domains were detected in this list.\n";
        } else {
            foreach ($audit_disavow as $dom => $a) {
                $disavow_text .= '# ' . str_replace(["\r", "\n"], ' ', implode('; ', $a['signals'])) . "\n";
                $disavow_text .= 'domain:' . $dom . "\n";
            }
        }
        $disavow_textarea = Support::h($disavow_text);

        // Transparency banner when the live fetch could not cover the whole list.
        $fetch_banner = '';
        if (!empty($r['live']) && (int)($r['fetchable'] ?? 0) > (int)($r['fetched'] ?? 0)) {
            $skipped = (int)$r['fetchable'] - (int)$r['fetched'];
            $fetch_banner = '<div class="warnbar">⏱ Live fetch processed <strong>' . (int)$r['fetched']
                . '</strong> of <strong>' . (int)$r['fetchable'] . '</strong> domains within the time limit; '
                . $skipped . ' were not live-checked. Raise <strong>Workers</strong>, raise PHP '
                . '<code>max_execution_time</code>, or split the list. Every domain is still analysed — '
                . 'the time-skipped ones are ranked on name/TLD signals (shown as <em>not verified</em>).</div>';
        }

        // Exact analysis coverage line (shown in the removal tab).
        $fetched_n = (int)($r['fetched'] ?? 0);
        $offline_n = max(0, (int)($r['fetchable'] ?? 0) - $fetched_n);
        $analyzed_note = $live
            ? $total . ' domains analysed — ' . $fetched_n . ' live-checked, ' . $offline_n . ' by name/TLD only'
            : $total . ' domains analysed (offline name/TLD mode)';


        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Backlink Prospect Report</title>
<style>
  :root{ --blue:#2271b1; --blued:#135e96; --line:#c3c4c7; --bg:#f0f0f1;
         --text:#1d2327; --muted:#50575e; --stripe:#f6f7f7;
         --good:#008a20; --accent:#2271b1; --warn:#bd5b00; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
  .wrap { max-width:1100px; margin:0 auto; padding:24px 20px 60px; }
  h1 { font-size:23px; font-weight:400; margin:0 0 6px; line-height:1.3; }
  h2 { font-size:15px; font-weight:600; margin:26px 0 8px; }
  h2.danger { color:#b32d2e; }
  p { margin:3px 0; } .muted { color:var(--muted); } .small { font-size:12px; }
  a { color:var(--blue); text-decoration:none; }
  a:hover { color:var(--blued); text-decoration:underline; }
  .backbtn { font-size:12px; }
  .cards { display:flex; flex-wrap:wrap; gap:12px; margin:16px 0 8px; }
  .cards div { background:#fff; border:1px solid var(--line); border-radius:4px;
               padding:10px 16px; min-width:120px; }
  .cards .n { font-size:21px; font-weight:600; line-height:1.2; }
  .cards .l { color:var(--muted); font-size:11px; text-transform:uppercase;
              letter-spacing:.02em; }
  .warnbar { background:#fff8e5; border:1px solid #f0d98c; border-left:4px solid #d9a200;
             padding:10px 14px; border-radius:4px; margin:16px 0 0; font-size:12.5px; }
  .warnbar code { background:#f6ecca; }
  .sortbar { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:4px 0 10px; }
  .unv { color:#bd5b00; font-weight:600; }
  .urlbadge { display:inline-block; font-size:11px; font-weight:600; color:#135e96;
              background:#e9f2fb; border:1px solid #bcdcf5; border-radius:10px;
              padding:0 7px; margin-left:6px; vertical-align:1px; white-space:nowrap; }
  table { width:100%; border-collapse:collapse; background:#fff;
          border:1px solid var(--line); border-radius:4px; overflow:hidden; }
  th,td { text-align:left; padding:9px 12px; border-bottom:1px solid #f0f0f1;
          vertical-align:top; }
  th { font-weight:600; border-bottom:1px solid var(--line); cursor:pointer; }
  th:hover { color:var(--blue); }
  tbody tr:nth-child(odd) { background:var(--stripe); }
  tr:last-child td { border-bottom:none; }
  td.rank { color:var(--muted); width:40px; }
  td.src a { font-weight:600; word-break:break-all; }
  .score { font-weight:700; }
  .score.good { color:#008a20; } .score.ok { color:#bd5b00; } .score.warn { color:#b32d2e; }
  .tag { display:inline-block; font-size:11px; font-weight:600; padding:2px 7px; border-radius:3px; }
  .tag.yes { background:#edfaef; color:#008a20; border:1px solid #b8e6c1; }
  .tag.no { color:#a7aaad; }
  .bar { margin:24px 0 0; }
  .btn { display:inline-block; background:var(--blue); color:#fff; border:1px solid var(--blue);
         border-radius:3px; padding:7px 16px; font-size:13px; font-weight:500;
         cursor:pointer; text-decoration:none; }
  .btn:hover { background:var(--blued); border-color:var(--blued); color:#fff; }
  .btn.secondary { background:#f6f7f7; color:var(--blue); margin-left:6px; }
  .btn.secondary:hover { background:#f0f0f1; color:var(--blued); }
  .tabs { display:flex; gap:4px; margin:20px 0 0; border-bottom:1px solid var(--line); }
  .tab { background:transparent; border:1px solid transparent; border-bottom:none;
         color:var(--muted); padding:8px 16px; font:inherit; font-weight:600;
         cursor:pointer; border-radius:4px 4px 0 0; }
  .tab:hover { color:var(--blue); }
  .tab.active { background:#fff; border-color:var(--line); color:var(--text); margin-bottom:-1px; }
  .tabpanel { display:none; padding-top:8px; }
  .tabpanel.active { display:block; }
  .scopelbl { font-size:12px; color:var(--muted); margin-right:10px; }
  .scopelbl select { font:inherit; padding:4px 6px; border:1px solid #8c8f94;
                     border-radius:3px; background:#fff; color:var(--text); }
  textarea.disavow { width:100%; min-height:320px; margin-top:10px; resize:vertical;
         font-family:ui-monospace,Consolas,monospace; font-size:12px; line-height:1.5;
         background:#fff; color:var(--text); border:1px solid #8c8f94;
         border-radius:4px; padding:10px; }
  @media print {
    body { background:#fff; } .bar, .btn, .backbtn, .tabs { display:none !important; }
    .tabpanel { display:block !important; }
    .cards div, table { border-color:#999; } th { background:#eee; }
  }
</style>
</head>
<body>
{$debug_badge}
<div class="wrap">
  <header>
    <p><a href="?" class="backbtn">&larr; New analysis</a></p>
    <h1>Backlink Prospects</h1>
    {$count_html}
    <p class="muted small">Your site: <strong>{$target}</strong></p>
    <p class="muted small">Topic profile: {$profile}</p>
    <p class="muted small" id="gen">Generated {$generated}</p>
  </header>

  <nav class="tabs">
    <button type="button" class="tab active" data-tab="prospects">Prospects</button>
    <button type="button" class="tab" data-tab="disavow">For Removal / Disavow ({$disavow_count})</button>
  </nav>

  <section id="tab-prospects" class="tabpanel active">
    {$fetch_banner}
    <section class="cards">
      <div><div class="n">{$total}</div><div class="l">{$checked_label}</div></div>
      <div><div class="n" style="color:var(--good)">{$i}</div><div class="l">Good prospects</div></div>
      <div><div class="n" style="color:var(--accent)">{$friendly_n}</div><div class="l">Guest-post friendly</div></div>
      <div><div class="n">{$avg}</div><div class="l">Avg score</div></div>
      <div><div class="n" style="color:var(--warn)">{$avoidcount}</div><div class="l">Avoid</div></div>
    </section>

    <h2>Best prospects</h2>
    <div class="sortbar">
      <label class="scopelbl">Sort by
        <select id="sortby">
          <option value="s-desc">Score (high → low)</option>
          <option value="s-asc">Score (low → high)</option>
          <option value="d-asc">Domain (A → Z)</option>
          <option value="rel-desc">Relevance (high → low)</option>
          <option value="au-desc">Authority (high → low)</option>
          <option value="g-desc">Guest-post first</option>
          <option value="o-asc">Rank (default)</option>
        </select>
      </label>
      <label class="scopelbl">Show
        <select id="pagesize">
          <option value="50">50</option>
          <option value="100">100</option>
          <option value="0">All</option>
        </select>
      </label>
      <span class="muted small" id="showing">Showing 0 of {$i}</span>
      <span class="muted small">· click any column header to sort the full set</span>
    </div>
    <noscript><p class="muted small">JavaScript is required to view the ranked prospects (loaded client-side for speed). Exports and the full report data are available with JavaScript on.</p></noscript>
    <table id="t">
      <thead><tr>
        <th data-col="o" data-type="num">#</th>
        <th data-col="d" data-type="str">Domain</th>
        <th data-col="s" data-type="num">Score</th>
        <th data-col="rel" data-type="num">Relevance</th>
        <th data-col="au" data-type="num">Authority</th>
        <th data-col="g" data-type="num">Outreach</th>
        <th data-col="w" data-type="str">Why</th>
      </tr></thead>
      <tbody id="prows"><tr id="prows-loading"><td colspan="7" class="muted" style="padding:1.5rem;text-align:center">Rendering {$i} prospects…</td></tr></tbody>
    </table>
    <div class="bar" style="margin-top:10px">
      <button class="btn secondary" id="loadMore" type="button" style="display:none">Load more</button>
    </div>

    <h2 class="danger">Avoid ({$avoidcount})</h2>
    <table id="avoid"><thead><tr><th>Domain</th><th>Reason</th></tr></thead>
      <tbody>{$avoid_rows}</tbody></table>

    <div class="bar" id="pdfbar">
      <label class="scopelbl">Export:
        <select id="scope">
          <option value="current">Current results</option>
          <option value="guest">Guest-post only</option>
        </select>
      </label>
      <button class="btn" id="btnPdf" type="button">PDF</button>
      <button class="btn secondary" id="btnExcel" type="button">Excel</button>
      <button class="btn secondary" id="btnCsv" type="button">CSV</button>
    </div>
  </section>

  <section id="tab-disavow" class="tabpanel">
    <h2>Backlink removal audit</h2>
    <p class="muted small"><strong>{$analyzed_note}.</strong> A conservative 3-tier classification of every
      domain — only <strong>hard, confirmable spam</strong> is auto-listed for removal; borderline links go
      to <strong>Review</strong> so a healthy link is never disavowed by mistake.</p>
    <section class="cards">
      <div><div class="n" style="color:var(--warn)">{$disavow_count}</div><div class="l">Disavow (remove)</div></div>
      <div><div class="n">{$review_count}</div><div class="l">Review manually</div></div>
      <div><div class="n" style="color:var(--good)">{$keep_count}</div><div class="l">Keep (healthy)</div></div>
    </section>

    <h2 class="danger">🚫 For removal — disavow ({$disavow_count})</h2>
    <table><thead><tr><th>Domain</th><th>Matched signal(s)</th><th>Confidence</th></tr></thead>
      <tbody>{$disavow_rows}</tbody></table>

    <h2>⚠️ Review manually ({$review_count})</h2>
    <p class="muted small">Soft signals only — these need a human decision. <strong>Not</strong> auto-disavowed.</p>
    <table><thead><tr><th>Domain</th><th>Risk</th><th>Reasons</th></tr></thead>
      <tbody>{$review_rows}</tbody></table>

    <h2>Google Disavow file <span class="muted small">(disavow tier only)</span></h2>
    <p class="muted small"><strong>Disavow is always domain-level</strong> (one <code>domain:</code> line per
      domain, de-duplicated) — even when per-URL analysis is on, because per-URL disavow lines are harmful.</p>
    <p class="muted small">Download and upload in
      <a href="https://search.google.com/search-console/disavow-links" target="_blank" rel="noopener">Search Console &rsaquo; Disavow Links</a>.
      <strong>Review every entry first</strong> — disavowing healthy links can hurt your rankings.</p>
    <textarea id="disavowText" class="disavow" readonly spellcheck="false">{$disavow_textarea}</textarea>
    <div class="bar">
      <button class="btn" id="btnDisavow" type="button">Download disavow.txt</button>
      <button class="btn secondary" id="copyBtn" type="button">Copy to clipboard</button>
    </div>
  </section>
</div>
<!-- The shell carries NO row data. report.js fetches rows in slices from
     ?report=data and renders them incrementally, so this first response is tiny. -->
<script src="?asset=report.js"></script>
</body>
</html>
HTML;
    }

    /**
     * Compact prospect rows for the ?report=data slice endpoint. One small object
     * per prospect (display + export fields). 'o' is the default-order index so a
     * "Rank (default)" sort can be restored. Reused by Router::reportData().
     */
    public static function prospectRows($r): array
    {
        $live = !empty($r['live']);
        $rows = [];
        $o = 0;
        foreach ($r['prospects'] as $bl) {
            $rows[] = [
                'o'   => $o++,
                'd'   => $bl['registrable_domain'] ?: $bl['source_url'],
                'u'   => $bl['final_url'] ?: $bl['source_url'],
                't'   => (string)$bl['title'],
                's'   => round((float)$bl['score'], 1),
                'rel' => (int)round(($bl['factor_values']['relevance'] ?? 0) * 100),
                'au'  => (int)round(($bl['factor_values']['authority'] ?? 0) * 100),
                'g'   => $bl['link_friendly'] ? 1 : 0,
                'w'   => Engine::whyStr($bl),
                'uc'  => (int)($bl['url_count'] ?? 1),
                'nv'  => ($live && !$bl['reachable']) ? 1 : 0,
            ];
        }
        return $rows;
    }

    /**
     * The report page's interactive logic, served as an EXTERNAL file via
     * ?asset=report.js. It NO LONGER reads inline data — it fetches rows in small
     * slices from ?report=data, renders them incrementally (chunks of 25 via
     * requestAnimationFrame so the main thread never freezes), and updates the
     * "Showing X of N" counter. Sorting re-fetches the full set sorted server-side;
     * exports fetch the full set (limit=0). Pure static JS (no PHP interpolation).
     */
    public static function reportJs(): string
    {
        return <<<'JS'
(function(){
  'use strict';
  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function fmtScore(s){ var n=Math.round((Number(s)||0)*10)/10; var str=''+n; return str.indexOf('.')>=0?str.replace(/\.?0+$/,''):str; }
  function log(m){ if(window.console&&console.log){ console.log('[report] '+m); } }
  function errMsg(e){ return (e&&e.name==='AbortError')?'timed out':(e&&e.message?e.message:(''+e)); }
  var DASH='—';
  var tb = document.querySelector('#prows');
  var PAGE = 50;        // slice size (0 = all)
  var sort = 's-desc';  // current sort (applied server-side)
  var total = 0;        // total prospects (from the endpoint)
  var offset = 0;       // rows loaded so far
  var perUrl = false;
  var busy = false;
  var raf = window.requestAnimationFrame || function(f){ return setTimeout(f, 16); };

  function rowHtml(p, rank){
    var badge = p.s>=70?'good':(p.s>=50?'ok':'warn');
    var friendly = p.g ? '<span class="tag yes">guest&nbsp;post</span>' : '<span class="tag no">'+DASH+'</span>';
    var unv = p.nv ? ' <span class="unv" title="Not live-fetched within the time limit — scored on name/TLD only">· not verified</span>' : '';
    var ub = (!perUrl && p.uc>1) ? ' <span class="urlbadge" title="'+p.uc+' submitted URLs map to this domain (merged for analysis)">'+p.uc+' URLs</span>' : '';
    return '<tr>'
      + '<td class="rank">'+rank+'</td>'
      + '<td class="src"><a href="'+esc(p.u)+'" target="_blank" rel="noopener nofollow">'+esc(p.d)+'</a>'+ub
      + '<div class="muted small">'+esc(p.t)+unv+'</div></td>'
      + '<td><span class="score '+badge+'">'+esc(fmtScore(p.s))+'</span></td>'
      + '<td>'+p.rel+'%</td>'
      + '<td>'+p.au+'%</td>'
      + '<td>'+friendly+'</td>'
      + '<td class="small muted">'+esc(p.w)+'</td>'
      + '</tr>';
  }

  function setShowing(){ var s=$('showing'); if(s){ s.textContent='Showing '+Math.min(offset,total)+' of '+total; } }
  function updateMore(){ var m=$('loadMore'); if(!m) return; if(offset<total){ m.style.display=''; m.textContent='Load more ('+(total-offset)+' left)'; } else { m.style.display='none'; } }

  // Incremental, non-blocking append (chunks of 25 via rAF) so the main thread
  // never freezes, however many rows arrive.
  function appendChunked(rows, startRank, done){
    var i=0;
    (function chunk(){
      try{
        var end=Math.min(i+25, rows.length), html='';
        for(; i<end; i++){ html += rowHtml(rows[i], startRank+i+1); }
        if(html){ tb.insertAdjacentHTML('beforeend', html); }
        log('rendered '+(startRank+i)+' of '+total);
        if(i<rows.length){ raf(chunk); } else if(done){ done(); }
      }catch(e){ busy=false; showError('render error: '+errMsg(e)); }
    })();
  }

  function fetchSlice(off, limit){
    var url='?report=data&offset='+off+'&limit='+limit+'&sort='+encodeURIComponent(sort);
    log('fetch '+url);
    var ctrl=window.AbortController?new AbortController():null;
    var to=ctrl?setTimeout(function(){ if(ctrl) ctrl.abort(); },20000):null;
    var opt={credentials:'same-origin'}; if(ctrl){ opt.signal=ctrl.signal; }
    return fetch(url,opt).then(function(r){ if(to)clearTimeout(to); return r.text(); }, function(e){ if(to)clearTimeout(to); throw e; })
      .then(function(t){ var d; try{ d=JSON.parse(t); }catch(e){ throw new Error('bad JSON from ?report=data'); } if(!d||!d.ok){ throw new Error(d&&d.error?d.error:'data endpoint error'); } return d; });
  }

  function loadFirst(){
    if(busy) return; busy=true;
    tb.innerHTML=''; offset=0;
    fetchSlice(0, (PAGE===0?0:PAGE)).then(function(d){
      total=d.total; perUrl=!!d.per_url;
      var rows=d.rows||[];
      if(!rows.length){ tb.innerHTML='<tr><td colspan="7" class="muted" style="padding:1.5rem;text-align:center">No suitable prospects found.</td></tr>'; setShowing(); updateMore(); busy=false; return; }
      appendChunked(rows, 0, function(){ offset=rows.length; setShowing(); updateMore(); busy=false; });
    }).catch(function(e){ busy=false; showError('Could not load rows: '+errMsg(e)); });
  }
  function loadMore(){
    if(busy || offset>=total) return; busy=true;
    var start=offset;
    fetchSlice(start, (PAGE===0?0:PAGE)).then(function(d){
      total=d.total; perUrl=!!d.per_url;
      var rows=d.rows||[];
      appendChunked(rows, start, function(){ offset=start+rows.length; setShowing(); updateMore(); busy=false; });
    }).catch(function(e){ busy=false; showError('Could not load more: '+errMsg(e)); });
  }

  function showError(msg){
    if(window.console&&console.error){ console.error('[report] '+msg); }
    var sec=$('tab-prospects'); if(!sec) return;
    var bar=$('reportErr');
    if(!bar){ bar=document.createElement('div'); bar.id='reportErr'; bar.className='warnbar'; sec.insertBefore(bar, sec.firstChild); }
    bar.innerHTML = esc(msg)+' <button class="btn" id="retryRows" type="button">Retry</button>';
    var rb=$('retryRows'); if(rb){ rb.onclick=function(){ if(bar.parentNode){ bar.parentNode.removeChild(bar); } loadFirst(); }; }
  }

  // ---- tabs ----
  function showTab(name, btn){
    var ps=document.querySelectorAll('.tabpanel'); for(var i=0;i<ps.length;i++){ ps[i].classList.remove('active'); }
    var ts=document.querySelectorAll('.tab'); for(var j=0;j<ts.length;j++){ ts[j].classList.remove('active'); }
    var panel=$('tab-'+name); if(panel){ panel.classList.add('active'); }
    if(btn){ btn.classList.add('active'); }
  }

  // ---- exports: fetch the FULL set (limit=0) in the current sort, then build ----
  function exportScope(){ var s=$('scope'); return s?s.value:'current'; }
  function fetchAll(){ return fetchSlice(0, 0).then(function(d){ var rows=d.rows||[]; if(exportScope()==='guest'){ rows=rows.filter(function(p){return p.g;}); } return rows; }); }
  function downloadBlob(content, filename, mime){ var b=new Blob([content],{type:mime}); var u=URL.createObjectURL(b); var a=document.createElement('a'); a.href=u; a.download=filename; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(u); }
  function pdfText(el){ return el?el.innerText.trim():''; }

  function downloadCSV(){ fetchAll().then(function(rows){
    var out=[['rank','domain','score','relevance_%','authority_%','guest_post','why']];
    rows.forEach(function(p,i){ out.push([i+1,p.d,fmtScore(p.s),p.rel,p.au,p.g?'yes':'no',p.w]); });
    var csv=out.map(function(r){ return r.map(function(x){ return '"'+String(x).replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
    downloadBlob('﻿'+csv, exportScope()==='guest'?'guest-post-prospects.csv':'prospects.csv','text/csv;charset=utf-8');
  }).catch(function(e){ showError('CSV export failed: '+errMsg(e)); }); }

  function downloadExcel(){ fetchAll().then(function(rows){
    var body=''; rows.forEach(function(p,i){ body+='<tr><td>'+(i+1)+'</td><td>'+esc(p.d)+'</td><td>'+esc(fmtScore(p.s))+'</td><td>'+p.rel+'</td><td>'+p.au+'</td><td>'+(p.g?'yes':'no')+'</td><td>'+esc(p.w)+'</td></tr>'; });
    var head='<tr><th>#</th><th>Domain</th><th>Score</th><th>Relevance %</th><th>Authority %</th><th>Guest post</th><th>Why</th></tr>';
    var html='<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Prospects</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body><table border="1">'+head+body+'</table></body></html>';
    downloadBlob('﻿'+html, exportScope()==='guest'?'guest-post-prospects.xls':'prospects.xls','application/vnd.ms-excel');
  }).catch(function(e){ showError('Excel export failed: '+errMsg(e)); }); }

  var JSPDF_URLS=['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js','https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'];
  function loadScript(src){ return new Promise(function(res,rej){ var s=document.createElement('script'); s.src=src; s.async=true; s.onload=function(){res();}; s.onerror=function(){rej(new Error('load '+src));}; document.head.appendChild(s); }); }
  function ensureJsPDF(){ if(window.jspdf&&window.jspdf.jsPDF){ return Promise.resolve(); } return loadScript(JSPDF_URLS[0]).then(function(){ return loadScript(JSPDF_URLS[1]); }); }
  function generatePDF(){ Promise.all([ensureJsPDF(), fetchAll()]).then(function(res){ doGeneratePDF(res[1]); }).catch(function(){ window.print(); }); }
  function doGeneratePDF(rows){
    if(!(window.jspdf&&window.jspdf.jsPDF)){ window.print(); return; }
    var jsPDF=window.jspdf.jsPDF; var doc=new jsPDF({unit:'pt',format:'a4'});
    var W=doc.internal.pageSize.getWidth(),H=doc.internal.pageSize.getHeight(),M=40;
    var guestOnly=exportScope()==='guest';
    var site=pdfText(document.querySelector('header strong')); var gen=pdfText($('gen'));
    var cards=Array.prototype.map.call(document.querySelectorAll('.cards .n'),function(e){ return e.innerText.trim(); });
    var summary='Domains checked: '+(cards[0]||'')+'    Good prospects: '+(cards[1]||'')+'    Guest-post friendly: '+(cards[2]||'')+'    Avg score: '+(cards[3]||'')+'    Avoid: '+(cards[4]||'');
    function footer(){ doc.setFont('times','italic'); doc.setFontSize(8); doc.setTextColor(140); doc.text('Backlink Prospect Report - '+site,M,H-18); doc.text('Page '+doc.internal.getNumberOfPages(),W-M,H-18,{align:'right'}); }
    doc.setFont('times','bold'); doc.setFontSize(22); doc.setTextColor(20);
    doc.text(guestOnly?'Backlink Prospect Report (Guest-post only)':'Backlink Prospect Report',M,56);
    doc.setDrawColor(30); doc.setLineWidth(1.2); doc.line(M,66,W-M,66);
    doc.setFont('times','normal'); doc.setFontSize(10); doc.setTextColor(90);
    doc.text('Your site: '+site,M,86); doc.text(gen,M,100); doc.setFontSize(9); doc.text(summary,M,116);
    var prows=rows.map(function(p,i){ return [String(i+1),p.d,fmtScore(p.s),String(p.rel),String(p.au),p.g?'Guest post':'',p.w]; });
    doc.autoTable({ startY:130, head:[['#','Domain','Score','Rel %','Auth %','Outreach','Why (top factors)']], body:prows, theme:'grid',
      styles:{font:'times',fontSize:9,textColor:[33,33,33],cellPadding:4,overflow:'linebreak',lineColor:[205,205,205],lineWidth:0.5},
      headStyles:{fillColor:[20,30,55],textColor:255,fontStyle:'bold',halign:'left'}, alternateRowStyles:{fillColor:[246,248,250]},
      columnStyles:{0:{cellWidth:26,halign:'center'},1:{cellWidth:120},2:{cellWidth:38,halign:'center'},3:{cellWidth:40,halign:'center'},4:{cellWidth:42,halign:'center'},5:{cellWidth:56},6:{cellWidth:'auto'}},
      margin:{left:M,right:M,bottom:34}, didDrawPage:footer });
    if(!guestOnly){
      var arows=Array.prototype.filter.call(document.querySelectorAll('#avoid tbody tr'),function(r){ return r.children.length===2; }).map(function(tr){ return [tr.children[0].innerText.trim(),tr.children[1].innerText.trim()]; });
      if(arows.length){ var y=doc.lastAutoTable.finalY+26; if(y>H-90){ doc.addPage(); y=56; }
        doc.setFont('times','bold'); doc.setFontSize(14); doc.setTextColor(150,40,40); doc.text('Avoid - unsafe or unusable link sources',M,y);
        doc.autoTable({ startY:y+10, head:[['Domain','Reason']], body:arows, theme:'grid',
          styles:{font:'times',fontSize:9,textColor:[33,33,33],cellPadding:4,overflow:'linebreak',lineColor:[205,205,205],lineWidth:0.5},
          headStyles:{fillColor:[120,30,30],textColor:255,fontStyle:'bold'}, columnStyles:{0:{cellWidth:175},1:{cellWidth:'auto'}}, margin:{left:M,right:M,bottom:34}, didDrawPage:footer }); }
    }
    doc.save(guestOnly?'guest-post-prospects.pdf':'backlink-prospects.pdf');
  }

  // ---- disavow (server-rendered textarea, full set, domain-level) ----
  function downloadDisavow(){ var el=$('disavowText'); if(el){ downloadBlob(el.value,'disavow.txt','text/plain;charset=utf-8'); } }
  function copyDisavow(){ var ta=$('disavowText'); if(!ta) return; ta.focus(); ta.select(); try{ ta.setSelectionRange(0,999999); }catch(e){} try{ document.execCommand('copy'); }catch(e){} if(navigator.clipboard){ navigator.clipboard.writeText(ta.value).catch(function(){}); } var btn=$('copyBtn'); if(btn){ var t=btn.textContent; btn.textContent='Copied!'; setTimeout(function(){ btn.textContent=t; },1500); } }

  // ---- wire events (no inline handlers → CSP-safe) ----
  function on(id,ev,fn){ var e=$(id); if(e){ e.addEventListener(ev,fn); } }
  function boot(){
    try{
      var tabs=document.querySelectorAll('.tab');
      for(var i=0;i<tabs.length;i++){ (function(t){ t.addEventListener('click',function(){ showTab(t.getAttribute('data-tab'),t); }); })(tabs[i]); }
      var ths=document.querySelectorAll('#t thead th');
      for(var k=0;k<ths.length;k++){ (function(th){
        var f=th.getAttribute('data-col'); if(!f) return;
        th.addEventListener('click',function(){
          var dir=(th.getAttribute('data-dir')==='desc')?'asc':'desc';
          for(var m=0;m<ths.length;m++){ ths[m].removeAttribute('data-dir'); }
          th.setAttribute('data-dir',dir);
          sort=f+'-'+dir; var sb=$('sortby'); if(sb){ sb.value=sort; }
          loadFirst();
        });
      })(ths[k]); }
      on('sortby','change',function(){ sort=$('sortby').value||'s-desc'; loadFirst(); });
      on('pagesize','change',function(){ var v=parseInt($('pagesize').value,10); PAGE=isNaN(v)?50:v; loadFirst(); });
      on('loadMore','click',loadMore);
      on('btnPdf','click',generatePDF);
      on('btnExcel','click',downloadExcel);
      on('btnCsv','click',downloadCSV);
      on('btnDisavow','click',downloadDisavow);
      on('copyBtn','click',copyDisavow);
      log('boot — fetching first slice');
      loadFirst();
    }catch(e){ showError('init error: '+errMsg(e)); }
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',boot); } else { boot(); }
})();
JS;
    }

    /**
     * Terminal "report not ready" page. Shown only when ?report=1 has no cached
     * report AND cannot rebuild one (e.g. the job was cleared). It NEVER polls,
     * auto-redirects, or re-enters the building state — so it cannot loop.
     */
    public static function reportMissing($resumable = false): string
    {
        $debug_badge = Debug::badge();
        $resume = $resumable
            ? '<a class="btn" href="?building=1">Resume analysis</a> '
            : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Report not ready</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#f0f0f1; color:#1d2327;
         font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif; }
  .card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:28px 30px; max-width:460px; }
  h1 { font-size:19px; font-weight:600; margin:0 0 8px; }
  p { color:#50575e; margin:0 0 16px; }
  .btn { display:inline-block; background:#2271b1; color:#fff; border:1px solid #2271b1;
         border-radius:4px; padding:8px 16px; font-weight:500; text-decoration:none; }
  .btn:hover { background:#135e96; }
  .btn.secondary { background:#f6f7f7; color:#2271b1; }
</style>
</head>
<body>
{$debug_badge}
<div class="card">
  <h1>No finished report to show yet</h1>
  <p>This browser has no completed analysis cached. Nothing is loading — start a new
     analysis (or resume the one in progress).</p>
  {$resume}<a class="btn secondary" href="?">Start new analysis</a>
</div>
</body>
</html>
HTML;
    }

    /** The input form (plain POST → progressive report shell). */
    public static function form($cfg, $error = '', $prefill = []): string
    {
        $target = Support::h($prefill['target_url'] ?? $cfg['TARGET_URL']);
        $domains = Support::h($prefill['domains'] ?? '');
        $niche = Support::h($prefill['niche'] ?? '');
        $pw_field = '';
        if (ACCESS_PASSWORD !== '') {
            $pw_field = '<label>Password <input type="password" name="pw" required></label>';
        }
        $err = $error ? '<div class="err">' . Support::h($error) . '</div>' : '';
        $curl_ok = function_exists('curl_multi_init')
            ? '' : '<div class="err">⚠️ The cURL extension is not enabled on this host — live fetching will not work. Ask your host to enable php-curl.</div>';
        $ossl_ok = function_exists('openssl_encrypt')
            ? '' : '<div class="err">⚠️ OpenSSL is not enabled — the progressive, refresh-safe analysis is unavailable and the tool falls back to a single request. Ask your host to enable php-openssl.</div>';
        $topnav = self::topNav('scorer');
        $debug_badge = Debug::badge();
        $per_url_checked = !empty($prefill['per_url']) ? ' checked' : '';   // default OFF

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Backlink Prospect Scorer</title>
<style>
  :root{ --blue:#2271b1; --blued:#135e96; --line:#c3c4c7; --bg:#f0f0f1;
         --text:#1d2327; --muted:#50575e; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
  .wrap { max-width:680px; margin:0 auto; padding:30px 20px 60px; }
  h1 { font-size:23px; font-weight:400; margin:0 0 4px; }
  p.lead { color:var(--muted); margin:0 0 18px; }
  form { background:#fff; border:1px solid var(--line); border-radius:4px; padding:20px 22px; }
  label { display:block; margin:14px 0 4px; font-weight:600; }
  input[type=text], input[type=url], input[type=number], input[type=password], textarea, input[type=file] {
    width:100%; background:#fff; color:var(--text); border:1px solid #8c8f94;
    border-radius:4px; padding:7px 9px; font:inherit; }
  input:focus, textarea:focus { border-color:var(--blue); outline:2px solid rgba(34,113,177,.25); }
  textarea { min-height:180px; font-family:ui-monospace,Consolas,monospace; font-size:12px; }
  .row { display:flex; gap:16px; flex-wrap:wrap; }
  .row > div { flex:1; min-width:150px; }
  .check { display:flex; align-items:center; gap:8px; margin-top:12px; }
  .check input { width:auto; }
  .muted { color:var(--muted); }
  .err { background:#fcf0f1; border-left:4px solid #d63638; color:#1d2327;
         padding:10px 12px; border-radius:2px; margin-bottom:14px; }
  button { margin-top:18px; background:var(--blue); color:#fff; border:1px solid var(--blue);
           border-radius:3px; padding:8px 18px; font-size:14px; font-weight:500; cursor:pointer; }
  button:hover { background:var(--blued); border-color:var(--blued); }
  code { background:#f0f0f1; padding:1px 5px; border-radius:3px; }
  a { color:var(--blue); text-decoration:none; } a:hover { text-decoration:underline; }
  .topnav { display:flex; gap:4px; margin:0 0 18px; border-bottom:1px solid var(--line); }
  .topnav a { padding:9px 18px; font-weight:600; color:var(--muted);
              border:1px solid transparent; border-bottom:none; border-radius:4px 4px 0 0; }
  .topnav a:hover { color:var(--blue); text-decoration:none; }
  .topnav a.active { background:#fff; border-color:var(--line); color:var(--text); margin-bottom:-1px; }
  .topnav a.logout { margin-left:auto; color:#b32d2e; }
  [hidden] { display:none !important; }
</style>
</head>
<body>
{$debug_badge}
<div class="wrap">
  {$topnav}
  <h1>Backlink Prospect Scorer</h1>
  <p class="lead">Paste candidate domains and find the best ones to get a backlink from — ranked, with all factors checked.</p>
  {$err}{$curl_ok}{$ossl_ok}
  <form method="post" enctype="multipart/form-data">
    {$pw_field}
    <label>Your website (defines relevance)</label>
    <input type="url" name="target_url" value="{$target}" placeholder="https://your-site.com" required>

    <label>Candidate domains (one per line; optional <code>domain,DR,spam</code>)</label>
    <textarea name="domains" placeholder="example.com&#10;another-site.co.uk&#10;site.com,55,8">{$domains}</textarea>

    <label>…or upload a .txt / .csv file</label>
    <input type="file" name="file" accept=".txt,.csv">

    <label>Extra niche keywords (optional, comma-separated)</label>
    <input type="text" name="niche" value="{$niche}" placeholder="marketing, technology, finance">

    <div class="row">
      <div><label>Limit (0 = all)</label><input type="number" name="limit" value="0" min="0"></div>
      <div><label>Workers (parallel)</label><input type="number" name="workers" value="24" min="1" max="64"></div>
    </div>

    <div class="check"><input type="checkbox" name="live" value="1" checked> Fetch each site live (recommended)</div>
    <div class="check"><input type="checkbox" name="verify_ssl" value="1" checked> Verify SSL (uncheck if your host has CA errors)</div>
    <div class="check"><input type="checkbox" name="per_url" value="1"{$per_url_checked}> Don't merge domains (analyze every URL)</div>
    <p class="muted small" style="margin:4px 0 0">Off (recommended for Disavow): one row per <strong>domain</strong>. On: one row per <strong>URL</strong> — more rows, useful to see which exact pages link to you. <strong>Disavow is always domain-level</strong>, even when this is on.</p>

    <button type="submit">Analyze &amp; rank</button>
    <p class="muted" style="margin-top:1rem">Tip: for large lists (hundreds of domains), raise <strong>Workers</strong> so more sites are fetched per batch. The analysis runs progressively in small batches, so it finishes the whole list regardless of the host's time limit — and the <strong>For Removal / Disavow</strong> audit classifies <em>every</em> domain you paste.</p>
  </form>
</div>
</body>
</html>
HTML;
    }

    /**
     * The progressive report shell (?building=1). Renders instantly, then drives
     * ?job=batch over AJAX: it appends each checked row to a live table and shows
     * a "Checked X of N" counter, and when every batch is done it does ONE final
     * navigation to the complete, filtered report (?report=1).
     *
     * Why this design eliminates the hang:
     *   - No request it issues runs the whole list — each ?job=batch is a short,
     *     bounded slice, so a host time limit can never kill "the analysis".
     *   - Every fetch has a hard client-side timeout (AbortController), so a
     *     stalled batch becomes a retryable error instead of an eternal spinner.
     *   - After repeated failures it stops at a CLEAR terminal error state with a
     *     Retry button — never an infinite spin.
     *   - The offset lives on the server, so retries/reloads RESUME, never restart.
     */
    public static function reportShell($v): string
    {
        $total     = (int)($v['total'] ?? 0);
        $processed = (int)($v['processed'] ?? 0);
        $id        = Support::h($v['id'] ?? '');
        $debug_badge = Debug::badge();
        $count_line = self::countLine($v['submitted'] ?? 0, $v['unique'] ?? $total, $v['merged'] ?? 0, !empty($v['per_url']));
        $count_html = $count_line !== '' ? '<p class="muted small" id="countline">' . Support::h($count_line) . '</p>' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Checking backlinks…</title>
<style>
  :root{ --blue:#2271b1; --blued:#135e96; --line:#c3c4c7; --bg:#f0f0f1;
         --text:#1d2327; --muted:#50575e; --stripe:#f6f7f7;
         --good:#008a20; --warn:#bd5b00; --bad:#b32d2e; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
  .wrap { max-width:1100px; margin:0 auto; padding:24px 20px 60px; }
  h1 { font-size:23px; font-weight:400; margin:0 0 6px; }
  a { color:var(--blue); text-decoration:none; } a:hover { text-decoration:underline; }
  .muted { color:var(--muted); } .small { font-size:12px; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:6px; padding:18px 20px; margin:14px 0; }
  .count { font-size:20px; font-weight:600; }
  .count .n { color:var(--blue); font-variant-numeric:tabular-nums; }
  .bartrack { height:10px; background:#e6e8eb; border-radius:6px; overflow:hidden; margin:12px 0 4px; }
  .barfill { height:100%; width:0%; background:linear-gradient(90deg,#2271b1,#135e96); transition:width .25s ease; }
  .statline { display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-block; font-size:12px; font-weight:600; padding:2px 9px; border-radius:12px; }
  .pill.clean { background:#edfaef; color:var(--good); border:1px solid #b8e6c1; }
  .pill.susp  { background:#fff8e5; color:var(--warn); border:1px solid #f0d98c; }
  .pill.spam  { background:#fcf0f1; color:var(--bad); border:1px solid #f0c2c3; }
  .spin { display:inline-block; width:15px; height:15px; border:2px solid #cfd6dd;
          border-top-color:var(--blue); border-radius:50%; animation:rot .8s linear infinite; vertical-align:-3px; }
  @keyframes rot { to { transform:rotate(360deg); } }
  table { width:100%; border-collapse:collapse; background:#fff;
          border:1px solid var(--line); border-radius:6px; overflow:hidden; margin-top:12px; }
  th,td { text-align:left; padding:8px 12px; border-bottom:1px solid #f0f0f1; vertical-align:top; }
  th { font-weight:600; border-bottom:1px solid var(--line); }
  tbody tr:nth-child(odd) { background:var(--stripe); }
  td.rank { color:var(--muted); width:46px; }
  td.dom { font-weight:600; word-break:break-all; }
  .tier { font-weight:700; font-size:11px; padding:1px 7px; border-radius:3px; }
  .tier.clean { color:var(--good); } .tier.susp { color:var(--warn); } .tier.spam { color:var(--bad); }
  .err { background:#fcf0f1; border-left:4px solid #d63638; color:#1d2327;
         padding:12px 14px; border-radius:4px; margin:14px 0; }
  .btn { display:inline-block; background:var(--blue); color:#fff; border:1px solid var(--blue);
         border-radius:3px; padding:7px 16px; font-size:13px; font-weight:500; cursor:pointer; }
  .btn:hover { background:var(--blued); border-color:var(--blued); }
  .btn.secondary { background:#f6f7f7; color:var(--blue); margin-left:6px; text-decoration:none; }
  noscript .err { display:block; }
</style>
</head>
<body>
{$debug_badge}
<div class="wrap">
  <p><a href="?" class="small">&larr; New analysis</a></p>
  <h1>Checking your backlinks…</h1>
  {$count_html}

  <noscript>
    <div class="err">JavaScript is off. <a href="?job=step">Continue without JavaScript →</a>
      (the page will advance one batch at a time until it finishes).</div>
  </noscript>

  <div class="panel" id="statusPanel"
       data-job-id="{$id}" data-total="{$total}" data-processed="{$processed}" data-endpoint="?job=batch">
    <div class="statline">
      <span class="spin" id="spin"></span>
      <span class="count">Checked <span class="n" id="done">{$processed}</span> of <span class="n" id="total">{$total}</span></span>
      <span class="pill clean" id="pillClean">0 clean</span>
      <span class="pill susp"  id="pillSusp">0 review</span>
      <span class="pill spam"  id="pillSpam">0 spam</span>
    </div>
    <div class="bartrack"><div class="barfill" id="bar"></div></div>
    <div class="small muted" id="stat">starting…</div>
  </div>

  <table id="live">
    <thead><tr><th>#</th><th>Domain</th><th>HTTP</th><th>Score</th><th>Verdict</th><th>Signals</th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>
<!-- The loader logic lives in an EXTERNAL file (?asset=shell.js) so a host /
     account-level Content-Security-Policy that blocks inline <script> cannot stop
     the batch loop. Per-page values are passed via data-* on #statusPanel above
     (HTML attributes are not affected by script-src CSP). -->
<script src="?asset=shell.js"></script>
<script>
/* Fail-safe: shell.js sets window.__blsBooted when its loop starts (and fires the
   first ?job=batch). If that hasn't happened within 3s, the external loader was
   empty / invalid / blocked — show a VISIBLE "loader didn't start — reload"
   message instead of hanging forever on "starting…". */
(function(){
  setTimeout(function(){
    if(window.__blsBooted){ return; }
    var sp=document.getElementById('spin'); if(sp){ sp.style.display='none'; }
    var s=document.getElementById('stat');
    if(s){ s.innerHTML='⚠ Loader didn’t start — <a href="?building=1">reload</a>. '
      + '<span class="muted">(<a href="?asset=shell.js" target="_blank" rel="noopener">open shell.js</a> · '
      + '<a href="?debug=1">debug log</a> · <a href="?job=step">continue without JavaScript</a>)</span>'; }
    if(window.console&&console.error){ console.error('[bls] watchdog: loader did not start within 3s'); }
  }, 3000);
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * The report-shell loader JavaScript, served as an EXTERNAL file via the
     * ?asset=shell.js route. It is pure static JS (no PHP interpolation): all
     * per-page values come from data-* attributes on #statusPanel, so a host
     * CSP that forbids inline scripts cannot block this logic. Wrapped in an
     * IIFE to avoid leaking globals.
     */
    public static function shellJs(): string
    {
        return <<<'JS'
(function(){
  'use strict';
  // Surface ANY uncaught error VISIBLY — a silent throw must never leave the page
  // spinning forever. Installed first so it also catches errors during init below.
  window.addEventListener('error', function(ev){
    try {
      var s = document.getElementById('stat');
      if(s){ s.textContent = 'startup error: ' + (ev && ev.message ? ev.message : 'unknown') + ' — open ?debug=1 / ?health=1'; }
      var sp = document.getElementById('spin'); if(sp){ sp.style.display = 'none'; }
    } catch(e){}
  });

  // Per-page config comes from data-* attributes (CSP-safe), NOT inline JS.
  var ROOT = document.getElementById('statusPanel');
  var cfg = (ROOT && ROOT.dataset) ? ROOT.dataset : {};
  var JOB_ID = cfg.jobId || '';
  var ENDPOINT = cfg.endpoint || '?job=batch';
  var SIZE = 20;            // domains per batch (server caps at 40)
  var REQ_TIMEOUT = 25000;  // hard per-request cap (ms) — a stalled tick aborts & retries
  var MAX_FAILS = 5;        // consecutive failures before a terminal error state
  var STATE = { done: parseInt(cfg.processed, 10) || 0, total: parseInt(cfg.total, 10) || 0,
                rank:0, counts:{clean:0,suspicious:0,spam:0}, finished:false, fails:0 };
  function el(id){ return document.getElementById(id); }

  function setProgress(done, total){
    STATE.done = done; STATE.total = total;
    el('done').textContent = done; el('total').textContent = total;
    var pct = total > 0 ? Math.round(done/total*100) : 0;
    el('bar').style.width = pct + '%';
    el('stat').textContent = 'checked ' + done + ' of ' + total + ' (' + pct + '%)';
  }
  function setCounts(){
    el('pillClean').textContent = STATE.counts.clean + ' clean';
    el('pillSusp').textContent  = STATE.counts.suspicious + ' review';
    el('pillSpam').textContent  = STATE.counts.spam + ' spam';
  }
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function appendRows(rows){
    if(!rows || !rows.length) return;
    var tb = el('rows'), frag = document.createDocumentFragment();
    for(var i=0;i<rows.length;i++){
      var v = rows[i], tier = v.tier || 'clean';
      STATE.counts[tier] = (STATE.counts[tier]||0) + 1;
      STATE.rank++;
      var label = tier==='spam' ? 'SPAM' : (tier==='suspicious' ? 'REVIEW' : 'CLEAN');
      var cls   = tier==='spam' ? 'spam' : (tier==='suspicious' ? 'susp' : 'clean');
      var tr = document.createElement('tr');
      tr.innerHTML = '<td class="rank">'+STATE.rank+'</td>'
        + '<td class="dom">'+esc(v.domain)+'</td>'
        + '<td>'+(v.http?esc(v.http):'—')+'</td>'
        + '<td>'+(v.score!=null?esc(v.score):'—')+'</td>'
        + '<td><span class="tier '+cls+'">'+label+'</span></td>'
        + '<td class="small muted">'+esc((v.signals||[]).join('; '))+'</td>';
      frag.appendChild(tr);
    }
    tb.appendChild(frag);
    setCounts();
  }

  function batchOnce(){
    var ctrl = (window.AbortController) ? new AbortController() : null;
    var to = ctrl ? setTimeout(function(){ ctrl.abort(); }, REQ_TIMEOUT) : null;
    var fd = new FormData(); fd.append('size', SIZE); fd.append('id', JOB_ID);
    var opt = { method:'POST', body:fd, credentials:'same-origin' };
    if(ctrl){ opt.signal = ctrl.signal; }
    return fetch(ENDPOINT, opt).then(function(r){ if(to) clearTimeout(to); return r.text(); },
      function(e){ if(to) clearTimeout(to); throw e; });
  }

  function tick(){
    if(STATE.finished) return;
    batchOnce().then(function(t){
      var d; try { d = JSON.parse(t); }
      catch(e){ return softFail('batch did not return JSON (tick likely killed by the host)'); }
      if(!d || !d.ok){ return softFail(d && d.error ? d.error : 'batch failed'); }
      STATE.fails = 0;
      appendRows(d.rows || []);
      setProgress(d.processed, d.total);
      if(d.done){ finish(d.report || '?report=1'); }
      else { setTimeout(tick, 0); }   // next slice immediately
    }).catch(function(e){
      var why = (e && e.name === 'AbortError') ? 'batch timed out (no response in '+(REQ_TIMEOUT/1000)+'s)'
                                               : ('network error: ' + (e && e.message ? e.message : e));
      softFail(why);
    });
  }

  // A failed/aborted tick is RESUMABLE: the server's offset hasn't advanced, so a
  // retry re-runs the SAME slice. Back off, then continue. One dead tick never ends
  // the run; only MAX_FAILS in a row produces a terminal error.
  function softFail(why){
    if(STATE.finished) return;
    STATE.fails++;
    if(STATE.fails <= MAX_FAILS){
      var wait = Math.min(8000, 600 * STATE.fails);
      el('stat').textContent = 'batch failed (' + why + ') — resuming in ' + (wait/1000).toFixed(1) + 's [' + STATE.fails + '/' + MAX_FAILS + ']';
      setTimeout(tick, wait);
    } else {
      hardFail(why);
    }
  }

  function finish(url){
    STATE.finished = true;
    el('spin').style.display = 'none';
    el('stat').textContent = 'done — opening the full report…';
    // ONE final render on the COMPLETE set: the assembled, filtered report.
    window.location.replace(url || '?report=1');
  }

  function hardFail(why){
    STATE.finished = true;
    el('spin').style.display = 'none';
    el('stat').textContent = 'stopped';
    var div = document.createElement('div');
    div.className = 'err';
    div.innerHTML = 'Analysis stopped after ' + MAX_FAILS + ' failed batches: ' + esc(why)
      + '<br><br><button class="btn" id="retryBtn">Retry from where it stopped</button>'
      + ' <a class="btn secondary" href="?debug=1">Open debug log</a>'
      + ' <a class="btn secondary" href="?health=1">Host health</a>';
    el('statusPanel').appendChild(div);
    document.getElementById('retryBtn').onclick = function(){
      div.parentNode.removeChild(div); STATE.finished = false; STATE.fails = 0; el('spin').style.display = '';
      tick();   // resumes at the server's current offset
    };
  }

  // Start the batch loop. Idempotent (guards against double-boot), wrapped so any
  // failure is shown instead of dying silently, and triggered MULTIPLE ways so it
  // runs no matter when this script executes relative to the DOM.
  function boot(){
    if(window.__blsBooted){ return; }
    window.__blsBooted = true;
    try {
      if(!window.fetch){
        el('spin').style.display = 'none';
        el('stat').innerHTML = 'This browser cannot run the live loader. ' +
          '<a href="?job=step">Continue without JavaScript &rarr;</a>';
        return;
      }
      if(window.console && console.log){ console.log('[bls] batch loop START — job=' + JOB_ID + ' total=' + STATE.total); }
      el('stat').textContent = 'starting batches…';
      setProgress(STATE.done, STATE.total);
      tick();   // first ?job=batch POST goes out now
    } catch(e){
      hardFail('kickoff error: ' + (e && e.message ? e.message : e));
    }
  }
  // Run now if the DOM is already parsed; otherwise as soon as it is. The extra
  // load + timer triggers are belt-and-suspenders so the loop ALWAYS starts.
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  window.addEventListener('load', boot);
  setTimeout(boot, 1000);
})();
JS;
    }

    /**
     * The "Backlink Notif" page. $view['mode'] is 'active' (locked status panel)
     * or 'editable' (the form). Secrets are never rendered, only masked.
     */
    public static function notifPage($cfg, $view): string
    {
        $nav = self::topNav('notif');

        $error  = $view['error']  ?? '';
        $notice = $view['notice'] ?? '';
        $err_html = $error  ? '<div class="err">' . Support::h($error) . '</div>' : '';
        $ok_html  = $notice ? '<div class="ok">' . Support::h($notice) . '</div>' : '';

        $curl_warn = function_exists('curl_init')
            ? '' : '<div class="err">⚠️ cURL is not enabled on this host — Telegram sending and live scanning will not work.</div>';
        $ossl_warn = function_exists('openssl_encrypt')
            ? '' : '<div class="err">⚠️ The OpenSSL extension is not available — encrypted storage will not work. Ask your host to enable php-openssl.</div>';

        $pw_field = (ACCESS_PASSWORD !== '')
            ? '<label>Password</label><input type="password" name="pw" autocomplete="off" required>'
            : '';

        if (($view['mode'] ?? 'editable') === 'active') {
            // ---- Locked status panel (monitor is running) ------------------
            $s = $view['state'];
            $count = count($s['domains'] ?? []);
            $created = !empty($s['created_at']) ? date('Y-m-d H:i', (int)$s['created_at']) : '—';
            $expires = !empty($s['expires_at']) ? date('Y-m-d', (int)$s['expires_at']) : '—';
            $lastrun = !empty($s['last_run']) ? date('Y-m-d H:i', (int)$s['last_run']) : 'not yet';
            $sum = $s['last_summary'] ?? null;
            $last_result = $sum
                ? Support::h($sum['checked'] . ' checked, ' . $sum['flagged'] . ' flagged (' . $sum['new'] . ' new) on ' . date('Y-m-d H:i', (int)$sum['at']))
                : 'no scan has run yet — the weekly cron will run it';
            $tok = Monitor::mask($s['bot_token'] ?? '');
            $chat = Monitor::mask($s['chat_id'] ?? '');

            $body = <<<HTML
  {$ok_html}{$err_html}
  <div class="ok"><strong>Monitoring is ACTIVE.</strong> {$count} domain(s) are checked once per week.</div>
  <form method="post">
    <div class="statusgrid">
      <div class="k">Domains monitored</div><div class="v">{$count}</div>
      <div class="k">Started</div><div class="v">{$created}</div>
      <div class="k">Runs until (1 year)</div><div class="v">{$expires}</div>
      <div class="k">Last weekly scan</div><div class="v">{$lastrun}</div>
      <div class="k">Last result</div><div class="v">{$last_result}</div>
      <div class="k">Telegram bot token</div><div class="v">{$tok} <span class="muted small">(hidden)</span></div>
      <div class="k">Telegram chat ID</div><div class="v">{$chat} <span class="muted small">(hidden)</span></div>
    </div>
    <p class="muted small">Your domain list and Telegram settings are stored encrypted on the server and are never shown here. To change the list, cancel monitoring first.</p>
    {$pw_field}
    <button type="submit" name="action" value="notif_cancel" formnovalidate>Cancel monitoring &amp; edit list</button>
  </form>
HTML;
        } else {
            // ---- Editable form ---------------------------------------------
            $domains = Support::h($view['domains'] ?? '');
            $has_creds = !empty($view['has_creds']);
            if ($has_creds) {
                $creds_note   = '<div class="ok">✅ Your Telegram bot token &amp; chat ID are saved. Edit the list and submit — leave the two fields blank to keep them.</div>';
                $tok_extra    = ' <span class="muted small">(saved ••••, leave blank to keep)</span>';
                $tok_ph       = 'Leave blank to keep the saved token';
                $tok_required = '';
                $tok_hint     = 'Already saved (encrypted). Type a new token only if you want to change it.';
                $chat_extra   = ' <span class="muted small">(saved ••••, leave blank to keep)</span>';
                $chat_ph      = 'Leave blank to keep the saved chat ID';
                $chat_required = '';
                $chat_hint    = 'Already saved (encrypted). Type a new ID only if you want to change it.';
            } else {
                $creds_note   = '';
                $tok_extra    = '';
                $tok_ph       = '123456789:AAExampleBotTokenFromBotFather';
                $tok_required = ' required';
                $tok_hint     = 'Create a bot with <code>@BotFather</code> and paste its token. Stored encrypted; never shown again.';
                $chat_extra   = '';
                $chat_ph      = '123456789';
                $chat_required = ' required';
                $chat_hint    = 'Get it from <code>@userinfobot</code>. Message your bot once first so it can DM you.';
            }
            $body = <<<HTML
  {$ok_html}{$err_html}
  <p class="lead">Paste the backlink domains you already have. We store them encrypted and, once a week, alert your Telegram if any becomes spam/toxic.</p>
  {$creds_note}
  <form method="post">
    {$pw_field}
    <label>Backlink domains (one per line)</label>
    <textarea name="notif_domains" placeholder="example.com&#10;myguestpost.co.uk&#10;partner-blog.com" required>{$domains}</textarea>

    <label>Telegram Bot Token{$tok_extra}</label>
    <input type="password" name="notif_token" autocomplete="off" placeholder="{$tok_ph}"{$tok_required}>
    <p class="muted small">{$tok_hint}</p>

    <label>Telegram Chat ID (your private user ID){$chat_extra}</label>
    <input type="password" name="notif_chat" autocomplete="off" placeholder="{$chat_ph}"{$chat_required}>
    <p class="muted small">{$chat_hint}</p>

    <button type="submit" name="action" value="notif_submit">Submit</button>
    <button type="submit" name="action" value="notif_cancel" formnovalidate class="secondary">Cancel</button>
  </form>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Backlink Notif</title>
<style>
  :root{ --blue:#2271b1; --blued:#135e96; --line:#c3c4c7; --bg:#f0f0f1;
         --text:#1d2327; --muted:#50575e; --good:#008a20; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
  .wrap { max-width:680px; margin:0 auto; padding:30px 20px 60px; }
  h1 { font-size:23px; font-weight:400; margin:0 0 4px; }
  p.lead { color:var(--muted); margin:0 0 18px; }
  a { color:var(--blue); text-decoration:none; } a:hover { text-decoration:underline; }
  .topnav { display:flex; gap:4px; margin:0 0 18px; border-bottom:1px solid var(--line); }
  .topnav a { padding:9px 18px; font-weight:600; color:var(--muted);
              border:1px solid transparent; border-bottom:none; border-radius:4px 4px 0 0; }
  .topnav a:hover { color:var(--blue); text-decoration:none; }
  .topnav a.active { background:#fff; border-color:var(--line); color:var(--text); margin-bottom:-1px; }
  .topnav a.logout { margin-left:auto; color:#b32d2e; }
  form { background:#fff; border:1px solid var(--line); border-radius:4px; padding:20px 22px; }
  label { display:block; margin:14px 0 4px; font-weight:600; }
  input[type=text], input[type=password], textarea {
    width:100%; background:#fff; color:var(--text); border:1px solid #8c8f94;
    border-radius:4px; padding:7px 9px; font:inherit; }
  input:focus, textarea:focus { border-color:var(--blue); outline:2px solid rgba(34,113,177,.25); }
  textarea { min-height:180px; font-family:ui-monospace,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); } .small { font-size:12px; }
  .err { background:#fcf0f1; border-left:4px solid #d63638; color:#1d2327;
         padding:10px 12px; border-radius:2px; margin-bottom:14px; }
  .ok { background:#edfaef; border-left:4px solid var(--good); color:#1d2327;
        padding:10px 12px; border-radius:2px; margin-bottom:14px; }
  .statusgrid { display:grid; grid-template-columns:170px 1fr; gap:8px 14px; margin:6px 0 4px; }
  .statusgrid .k { color:var(--muted); } .statusgrid .v { font-weight:600; word-break:break-word; }
  button { margin-top:18px; background:var(--blue); color:#fff; border:1px solid var(--blue);
           border-radius:3px; padding:8px 18px; font-size:14px; font-weight:500; cursor:pointer; }
  button:hover { background:var(--blued); border-color:var(--blued); }
  button.secondary { background:#f6f7f7; color:var(--blue); margin-left:6px; }
  button.secondary:hover { background:#f0f0f1; color:var(--blued); }
  code { background:#f0f0f1; padding:1px 5px; border-radius:3px; }
</style>
</head>
<body>
<div class="wrap">
  {$nav}
  <h1>Backlink Notif</h1>
  {$curl_warn}{$ossl_warn}
{$body}
</div>
</body>
</html>
HTML;
    }

    /** The login page (full HTML). */
    public static function login($error = '', $next = ''): string
    {
        $err  = $error ? '<div class="err">' . Support::h($error) . '</div>' : '';
        $next = Support::h($next);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Sign in</title>
<style>
  :root{ --blue:#2271b1; --blued:#135e96; --line:#c3c4c7; --bg:#f0f0f1; --text:#1d2327; --muted:#50575e; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:var(--bg); color:var(--text);
         font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
  .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:28px 30px;
          width:340px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
  h1 { font-size:20px; font-weight:600; margin:0 0 4px; }
  p.lead { color:var(--muted); margin:0 0 16px; font-size:13px; }
  label { display:block; margin:12px 0 4px; font-weight:600; }
  input { width:100%; border:1px solid #8c8f94; border-radius:4px; padding:9px 10px; font:inherit; }
  input:focus { border-color:var(--blue); outline:2px solid rgba(34,113,177,.25); }
  button { margin-top:18px; width:100%; background:var(--blue); color:#fff; border:1px solid var(--blue);
           border-radius:4px; padding:10px; font-size:14px; font-weight:600; cursor:pointer; }
  button:hover { background:var(--blued); border-color:var(--blued); }
  .err { background:#fcf0f1; border-left:4px solid #d63638; padding:10px 12px; border-radius:2px;
         margin-bottom:14px; font-size:13px; }
  .muted { color:var(--muted); font-size:12px; margin-top:14px; }
</style>
</head>
<body>
<div class="card">
  <h1>Backlink Tools</h1>
  <p class="lead">Please sign in. You'll only be asked once on this device.</p>
  {$err}
  <form method="post" autocomplete="on">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="next" value="{$next}">
    <label>Username</label>
    <input type="text" name="username" autocomplete="username" autofocus required>
    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
  <p class="muted">This login is remembered for 1 year in an encrypted, signed cookie on this browser.</p>
</div>
</body>
</html>
HTML;
    }
}
