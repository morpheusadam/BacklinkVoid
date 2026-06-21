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

        $live = !empty($r['live']);
        $prospect_rows = '';
        $i = 0;
        foreach ($prospects as $bl) {
            $i++;
            $url = Support::h($bl['final_url'] ?: $bl['source_url']);
            $dom = Support::h($bl['registrable_domain'] ?: $bl['source_url']);
            $title = Support::h($bl['title']);
            $rel = round(($bl['factor_values']['relevance'] ?? 0) * 100);
            $auth = round(($bl['factor_values']['authority'] ?? 0) * 100);
            $badge = $bl['score'] >= 70 ? 'good' : ($bl['score'] >= 50 ? 'ok' : 'warn');
            $friendly_sort = $bl['link_friendly'] ? 1 : 0;
            $friendly = $bl['link_friendly']
                ? '<span class="tag yes">guest&nbsp;post</span>'
                : '<span class="tag no">—</span>';
            // Flag domains the deadline could not live-fetch (scored offline).
            $unv = ($live && !$bl['reachable'])
                ? ' <span class="unv" title="Not live-fetched within the time limit — scored on name/TLD only">· not verified</span>'
                : '';
            $score = rtrim(rtrim(number_format($bl['score'], 1), '0'), '.');
            $prospect_rows .= '
        <tr>
          <td class="rank">' . $i . '</td>
          <td class="src"><a href="' . $url . '" target="_blank" rel="noopener nofollow">' . $dom . '</a>
            <div class="muted small">' . $title . $unv . '</div></td>
          <td data-sort="' . $bl['score'] . '"><span class="score ' . $badge . '">' . $score . '</span></td>
          <td data-sort="' . $rel . '">' . $rel . '%</td>
          <td data-sort="' . $auth . '">' . $auth . '%</td>
          <td data-sort="' . $friendly_sort . '">' . $friendly . '</td>
          <td class="small muted">' . Support::h(Engine::whyStr($bl)) . '</td>
        </tr>';
        }
        if ($prospect_rows === '') {
            $prospect_rows = '<tr><td colspan="7" class="muted" style="padding:1.5rem;text-align:center">No suitable prospects found.</td></tr>';
        }

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

        // PDF/CSV/Excel/disavow client-side helpers (literal em dash for outreach).
        $pdf_js = <<<'JS'
function pdfText(el){ return el ? el.innerText.trim() : ''; }
var DASH = '—';

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function downloadBlob(content, filename, mime){
  const blob = new Blob([content], {type: mime});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
}

// Which rows feed an export. 'current' = every row in its on-screen order
// (so sorting carries through); 'guest' = only guest-post-friendly rows.
function exportScope(){ const s = document.getElementById('scope'); return s ? s.value : 'current'; }
function scopedRows(){
  let rows = Array.prototype.filter.call(document.querySelectorAll('#t tbody tr'), function(r){return r.children.length===7;});
  if(exportScope()==='guest') rows = rows.filter(function(r){ return r.children[5].innerText.trim() !== DASH; });
  return rows;
}

function showTab(name, btn){
  Array.prototype.forEach.call(document.querySelectorAll('.tabpanel'), function(p){ p.classList.remove('active'); });
  Array.prototype.forEach.call(document.querySelectorAll('.tab'), function(t){ t.classList.remove('active'); });
  const panel = document.getElementById('tab-'+name);
  if(panel) panel.classList.add('active');
  if(btn) btn.classList.add('active');
}

function generatePDF(){
  if(!(window.jspdf && window.jspdf.jsPDF)){ window.print(); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({unit:'pt', format:'a4'});
  const W = doc.internal.pageSize.getWidth();
  const H = doc.internal.pageSize.getHeight();
  const M = 40;
  const guestOnly = exportScope()==='guest';
  const site = pdfText(document.querySelector('header strong'));
  const gen  = pdfText(document.getElementById('gen'));
  const cards = Array.prototype.map.call(document.querySelectorAll('.cards .n'), function(e){return e.innerText.trim();});
  const summary = 'Domains checked: '+(cards[0]||'')+'    Good prospects: '+(cards[1]||'')+
                  '    Guest-post friendly: '+(cards[2]||'')+'    Avg score: '+(cards[3]||'')+
                  '    Avoid: '+(cards[4]||'');
  function footer(){
    doc.setFont('times','italic'); doc.setFontSize(8); doc.setTextColor(140);
    doc.text('Backlink Prospect Report - '+site, M, H-18);
    doc.text('Page '+doc.internal.getNumberOfPages(), W-M, H-18, {align:'right'});
  }
  doc.setFont('times','bold'); doc.setFontSize(22); doc.setTextColor(20);
  doc.text(guestOnly ? 'Backlink Prospect Report (Guest-post only)' : 'Backlink Prospect Report', M, 56);
  doc.setDrawColor(30); doc.setLineWidth(1.2); doc.line(M, 66, W-M, 66);
  doc.setFont('times','normal'); doc.setFontSize(10); doc.setTextColor(90);
  doc.text('Your site: '+site, M, 86);
  doc.text(gen, M, 100);
  doc.setFontSize(9); doc.text(summary, M, 116);
  const prows = scopedRows().map(function(tr){
      const c = tr.children;
      const a = c[1].querySelector('a');
      const domain = (a ? a.innerText : c[1].innerText).trim();
      const outreach = c[5].innerText.trim()===DASH ? '' : 'Guest post';
      return [c[0].innerText.trim(), domain, c[2].innerText.trim(),
              c[3].innerText.trim(), c[4].innerText.trim(), outreach, c[6].innerText.trim()];
    });
  doc.autoTable({
    startY: 130,
    head: [['#','Domain','Score','Rel %','Auth %','Outreach','Why (top factors)']],
    body: prows,
    theme: 'grid',
    styles: {font:'times', fontSize:9, textColor:[33,33,33], cellPadding:4, overflow:'linebreak', lineColor:[205,205,205], lineWidth:0.5},
    headStyles: {fillColor:[20,30,55], textColor:255, fontStyle:'bold', halign:'left'},
    alternateRowStyles: {fillColor:[246,248,250]},
    columnStyles: {0:{cellWidth:26, halign:'center'}, 1:{cellWidth:120},
                   2:{cellWidth:38, halign:'center'}, 3:{cellWidth:40, halign:'center'},
                   4:{cellWidth:42, halign:'center'}, 5:{cellWidth:56}, 6:{cellWidth:'auto'}},
    margin: {left:M, right:M, bottom:34},
    didDrawPage: footer
  });
  if(!guestOnly){
    const arows = Array.prototype.filter.call(document.querySelectorAll('#avoid tbody tr'), function(r){return r.children.length===2;})
      .map(function(tr){ return [tr.children[0].innerText.trim(), tr.children[1].innerText.trim()]; });
    if(arows.length){
      let y = doc.lastAutoTable.finalY + 26;
      if(y > H-90){ doc.addPage(); y=56; }
      doc.setFont('times','bold'); doc.setFontSize(14); doc.setTextColor(150,40,40);
      doc.text('Avoid - unsafe or unusable link sources', M, y);
      doc.autoTable({
        startY: y+10,
        head: [['Domain','Reason']],
        body: arows,
        theme: 'grid',
        styles: {font:'times', fontSize:9, textColor:[33,33,33], cellPadding:4, overflow:'linebreak', lineColor:[205,205,205], lineWidth:0.5},
        headStyles: {fillColor:[120,30,30], textColor:255, fontStyle:'bold'},
        columnStyles: {0:{cellWidth:175}, 1:{cellWidth:'auto'}},
        margin: {left:M, right:M, bottom:34},
        didDrawPage: footer
      });
    }
  }
  doc.save(guestOnly ? 'guest-post-prospects.pdf' : 'backlink-prospects.pdf');
}

function downloadCSV(){
  const rows = [['rank','domain','score','relevance_%','authority_%','guest_post','why']];
  scopedRows().forEach(function(tr){
    const c = tr.children;
    const a = c[1].querySelector('a');
    rows.push([c[0].innerText.trim(), (a?a.innerText:c[1].innerText).trim(), c[2].innerText.trim(),
               c[3].innerText.trim().replace('%',''), c[4].innerText.trim().replace('%',''),
               c[5].innerText.trim()===DASH?'no':'yes', c[6].innerText.trim()]);
  });
  const csv = rows.map(function(r){return r.map(function(x){return '"'+String(x).replace(/"/g,'""')+'"';}).join(',');}).join('\n');
  downloadBlob('﻿'+csv, exportScope()==='guest'?'guest-post-prospects.csv':'prospects.csv', 'text/csv;charset=utf-8');
}

// Excel: an HTML table tagged as a worksheet — opens natively in Excel,
// LibreOffice Calc and Google Sheets, no external library or network needed.
function downloadExcel(){
  let body = '';
  scopedRows().forEach(function(tr){
    const c = tr.children;
    const a = c[1].querySelector('a');
    const domain = (a?a.innerText:c[1].innerText).trim();
    body += '<tr><td>'+esc(c[0].innerText.trim())+'</td><td>'+esc(domain)+'</td><td>'+
      esc(c[2].innerText.trim())+'</td><td>'+esc(c[3].innerText.trim().replace('%',''))+'</td><td>'+
      esc(c[4].innerText.trim().replace('%',''))+'</td><td>'+(c[5].innerText.trim()===DASH?'no':'yes')+
      '</td><td>'+esc(c[6].innerText.trim())+'</td></tr>';
  });
  const head = '<tr><th>#</th><th>Domain</th><th>Score</th><th>Relevance %</th><th>Authority %</th><th>Guest post</th><th>Why</th></tr>';
  const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">'+
    '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'+
    '<x:Name>Prospects</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>'+
    '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body><table border="1">'+
    head+body+'</table></body></html>';
  downloadBlob('﻿'+html, exportScope()==='guest'?'guest-post-prospects.xls':'prospects.xls', 'application/vnd.ms-excel');
}

function downloadDisavow(){
  const el = document.getElementById('disavowText');
  if(el) downloadBlob(el.value, 'disavow.txt', 'text/plain;charset=utf-8');
}
function copyDisavow(){
  const ta = document.getElementById('disavowText');
  if(!ta) return;
  ta.focus(); ta.select();
  try { ta.setSelectionRange(0, 999999); } catch(e){}
  try { document.execCommand('copy'); } catch(e){}
  if(navigator.clipboard){ navigator.clipboard.writeText(ta.value).catch(function(){}); }
  const btn = document.getElementById('copyBtn');
  if(btn){ const t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = t; }, 1500); }
}

function sortT(col, type){
  const tb = document.querySelector('#t tbody');
  const rows = Array.prototype.filter.call(tb.querySelectorAll('tr'), function(r){return r.children.length===7;});
  const dir = tb.getAttribute('data-dir')==='asc' ? -1 : 1;
  tb.setAttribute('data-dir', dir===1?'asc':'desc');
  rows.sort(function(a,b){
    let x=a.children[col].getAttribute('data-sort')??a.children[col].innerText;
    let y=b.children[col].getAttribute('data-sort')??b.children[col].innerText;
    if(type==='num'){ return ((parseFloat(x)||0)-(parseFloat(y)||0))*dir; }
    return x.localeCompare(y)*dir;
  });
  rows.forEach(function(r){ tb.appendChild(r); });
}

// Sort to an explicit column + direction (driven by the "Sort by" dropdown).
function sortTo(col, type, dir){
  const tb = document.querySelector('#t tbody');
  const rows = Array.prototype.filter.call(tb.querySelectorAll('tr'), function(r){return r.children.length===7;});
  rows.sort(function(a,b){
    let x=a.children[col].getAttribute('data-sort')??a.children[col].innerText;
    let y=b.children[col].getAttribute('data-sort')??b.children[col].innerText;
    if(type==='num'){ return ((parseFloat(x)||0)-(parseFloat(y)||0))*dir; }
    return String(x).localeCompare(String(y))*dir;
  });
  rows.forEach(function(r){ tb.appendChild(r); });
}
function applySort(){
  const sel = document.getElementById('sortby'); if(!sel) return;
  const parts = sel.value.split('-');
  const col = parseInt(parts[0], 10), dir = parts[1]==='asc' ? 1 : -1;
  sortTo(col, col===1 ? 'str' : 'num', dir);
}
JS;

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
<div class="wrap">
  <header>
    <p><a href="?" class="backbtn">&larr; New analysis</a></p>
    <h1>Backlink Prospects</h1>
    <p class="muted small">Your site: <strong>{$target}</strong></p>
    <p class="muted small">Topic profile: {$profile}</p>
    <p class="muted small" id="gen">Generated {$generated}</p>
  </header>

  <nav class="tabs">
    <button type="button" class="tab active" onclick="showTab('prospects', this)">Prospects</button>
    <button type="button" class="tab" onclick="showTab('disavow', this)">For Removal / Disavow ({$disavow_count})</button>
  </nav>

  <section id="tab-prospects" class="tabpanel active">
    {$fetch_banner}
    <section class="cards">
      <div><div class="n">{$total}</div><div class="l">Domains checked</div></div>
      <div><div class="n" style="color:var(--good)">{$i}</div><div class="l">Good prospects</div></div>
      <div><div class="n" style="color:var(--accent)">{$friendly_n}</div><div class="l">Guest-post friendly</div></div>
      <div><div class="n">{$avg}</div><div class="l">Avg score</div></div>
      <div><div class="n" style="color:var(--warn)">{$avoidcount}</div><div class="l">Avoid</div></div>
    </section>

    <h2>Best prospects</h2>
    <div class="sortbar">
      <label class="scopelbl">Sort by
        <select id="sortby" onchange="applySort()">
          <option value="2-desc">Score (high → low)</option>
          <option value="2-asc">Score (low → high)</option>
          <option value="1-asc">Domain (A → Z)</option>
          <option value="3-desc">Relevance (high → low)</option>
          <option value="4-desc">Authority (high → low)</option>
          <option value="5-desc">Guest-post first</option>
          <option value="0-asc">Rank (default)</option>
        </select>
      </label>
      <span class="muted small">{$total} domains analysed · click any column header to sort too</span>
    </div>
    <table id="t">
      <thead><tr>
        <th onclick="sortT(0,'num')">#</th>
        <th onclick="sortT(1,'str')">Domain</th>
        <th onclick="sortT(2,'num')">Score</th>
        <th onclick="sortT(3,'num')">Relevance</th>
        <th onclick="sortT(4,'num')">Authority</th>
        <th onclick="sortT(5,'str')">Outreach</th>
        <th onclick="sortT(6,'str')">Why</th>
      </tr></thead>
      <tbody>{$prospect_rows}</tbody>
    </table>

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
      <button class="btn" onclick="generatePDF()">PDF</button>
      <button class="btn secondary" onclick="downloadExcel()">Excel</button>
      <button class="btn secondary" onclick="downloadCSV()">CSV</button>
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
    <p class="muted small">Download and upload in
      <a href="https://search.google.com/search-console/disavow-links" target="_blank" rel="noopener">Search Console &rsaquo; Disavow Links</a>.
      <strong>Review every entry first</strong> — disavowing healthy links can hurt your rankings.</p>
    <textarea id="disavowText" class="disavow" readonly spellcheck="false">{$disavow_textarea}</textarea>
    <div class="bar">
      <button class="btn" onclick="downloadDisavow()">Download disavow.txt</button>
      <button class="btn secondary" id="copyBtn" onclick="copyDisavow()">Copy to clipboard</button>
    </div>
  </section>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
{$pdf_js}
</script>
</body>
</html>
HTML;
    }

    /** The input form with the full-screen loading overlay + live timer. */
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
        $topnav = self::topNav('scorer');
        // The streaming terminal needs the encrypted cache (OpenSSL). When it is
        // unavailable the form falls back to a normal POST.
        $stream_ok = function_exists('openssl_encrypt') ? 'true' : 'false';

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
  .term { position:fixed; inset:0; z-index:9999; background:rgba(8,10,14,.86);
          display:flex; align-items:center; justify-content:center; padding:18px; }
  .term-win { width:min(860px,100%); height:min(72vh,640px); display:flex; flex-direction:column;
              background:#0b0f17; border:1px solid #1d2734; border-radius:10px; overflow:hidden;
              box-shadow:0 24px 70px rgba(0,0,0,.5); }
  .term-bar { display:flex; align-items:center; gap:8px; padding:9px 14px; background:#121826;
              border-bottom:1px solid #1d2734; }
  .term-bar .dot { width:11px; height:11px; border-radius:50%; display:inline-block; }
  .term-bar .dot.r { background:#ff5f56; } .term-bar .dot.y { background:#ffbd2e; } .term-bar .dot.g { background:#27c93f; }
  .term-title { color:#9fb3c8; font:600 12px ui-monospace,Consolas,monospace; margin-left:6px; }
  .term-elapsed { margin-left:auto; color:#5eead4; font:600 12px ui-monospace,Consolas,monospace;
                  font-variant-numeric:tabular-nums; }
  .term-body { margin:0; flex:1; overflow-y:auto; padding:14px 16px; background:#0b0f17;
               color:#c7d2da; font:12.5px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace;
               white-space:pre-wrap; word-break:break-word; }
  .term-body .l-cmd  { color:#7dd3fc; font-weight:700; }
  .term-body .l-ok   { color:#86efac; }
  .term-body .l-warn { color:#fcd34d; }
  .term-body .l-err  { color:#fca5a5; }
  .term-body .l-info { color:#93c5fd; }
  .term-body .l-done { color:#34d399; font-weight:700; }
  .term-body .l-clean { color:#86efac; }
  .term-body .l-susp  { color:#fcd34d; }
  .term-body .l-spam  { color:#fca5a5; font-weight:700; }
  .term-body .cur { display:inline-block; width:8px; height:15px; background:#34d399;
                    vertical-align:-2px; animation:blink 1s steps(1) infinite; }
  @keyframes blink { 50% { opacity:0; } }
  .term-foot { display:flex; align-items:center; gap:12px; padding:9px 14px; background:#121826;
               border-top:1px solid #1d2734; color:#9fb3c8; font:12px ui-monospace,Consolas,monospace;
               flex-wrap:wrap; }
  .term-foot .muted { color:#6b7c92; }
  .term-actions { margin-left:auto; display:inline-flex; gap:8px; }
  .term-btn, .term-close { background:#1f2a3a; color:#cbd5e1; border:1px solid #2c3a4e;
                border-radius:5px; padding:5px 12px; font:inherit; cursor:pointer; }
  .term-btn:hover, .term-close:hover { background:#27374b; }
  .term-btn.primary { background:#134e4a; border-color:#155e57; color:#a7f3d0; }
  .term-btn.primary:hover { background:#166057; }
</style>
</head>
<body>
<div id="term" class="term" hidden>
  <div class="term-win">
    <div class="term-bar">
      <span class="dot r"></span><span class="dot y"></span><span class="dot g"></span>
      <span class="term-title">spam-check — live</span>
      <span class="term-elapsed" id="termTime">0.0s</span>
    </div>
    <pre class="term-body" id="termBody"><span class="cur"></span></pre>
    <div class="term-foot">
      <span class="term-spin" id="termSpin">▰▱▱</span>
      <span id="termCounts">🟢 0 · 🟡 0 · 🔴 0</span>
      <span id="termStat" class="muted">starting…</span>
      <span class="term-actions" id="termActions" hidden>
        <button type="button" class="term-btn" id="btnDisavow">⬇ disavow.txt</button>
        <button type="button" class="term-btn primary" id="btnReport">Open full report ↗</button>
      </span>
      <button type="button" class="term-close" id="termClose" hidden>Close</button>
    </div>
  </div>
</div>
<div class="wrap">
  {$topnav}
  <h1>Backlink Prospect Scorer</h1>
  <p class="lead">Paste candidate domains and find the best ones to get a backlink from — ranked, with all factors checked.</p>
  {$err}{$curl_ok}
  <form method="post" enctype="multipart/form-data" data-scan="1">
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

    <button type="submit">Analyze &amp; rank</button>
    <p class="muted" style="margin-top:1rem">Tip: for large lists (hundreds of domains), raise <strong>Workers</strong> so more sites are fetched within the time limit, and raise PHP <code>max_execution_time</code> if you can. Either way, the <strong>For Removal / Disavow</strong> audit classifies <em>every</em> domain you paste, not just the ones fetched in time.</p>
  </form>
</div>
<script>
// Live spam-check console. Submits the list once (?prepare=1), then checks it in
// small BATCHES (?batch=1) — each a short request, so even a time-limited shared
// host (e.g. Hostinger) finishes the WHOLE list instead of dying after ~100.
// Verdicts render line-by-line for a live feel. (A Server-Sent-Events endpoint
// ?sse=1 also exists for hosts that allow long streaming, but batching is the
// reliable default.) Falls back to a normal POST when JS/OpenSSL is unavailable.
var STREAM_OK = {$stream_ok};
var BATCH = 12;
var TERM = {};
function el(id){ return document.getElementById(id); }

(function(){
  var form = document.querySelector('form[data-scan]');
  if(!form) return;
  form.addEventListener('submit', function(ev){
    if(!STREAM_OK || !window.fetch){ return; } // no streaming support -> normal POST
    ev.preventDefault();
    startCheck(form);
  });
})();

function startCheck(form){
  TERM = { t0:Date.now(), checked:0, total:0, counts:{clean:0,suspicious:0,spam:0},
           spam:[], queue:[], draining:false, lastBatch:false, finished:false,
           reportUrl:'?report=1', timer:null };
  el('term').hidden = false;                 // ALWAYS show the console immediately
  el('termActions').hidden = true; el('termClose').hidden = true;
  el('termBody').innerHTML = '<span class="cur"></span>';
  setCounts(); setStat('preparing…');
  var frames = ['▰▱▱','▰▰▱','▰▰▰','▰▰▱'], fi = 0;
  TERM.timer = setInterval(function(){
    el('termTime').textContent = ((Date.now()-TERM.t0)/1000).toFixed(1)+'s';
    if(!TERM.finished){ el('termSpin').textContent = frames[fi=(fi+1)%frames.length]; }
  }, 200);

  line('$ spam-check  (preparing…)', 'l-cmd');
  fetch('?prepare=1', {method:'POST', body:new FormData(form), credentials:'same-origin'})
    .then(function(r){ return r.text(); })
    .then(function(t){
      var d; try { d = JSON.parse(t); }
      catch(e){ fail('prepare did not return JSON (host/deploy issue) — open  ?health=1'); return; }
      if(!d || !d.ok){ fail(d && d.error ? d.error : 'prepare failed'); return; }
      TERM.total = d.total;
      if(!TERM.total){ fail('no valid domains found in the input'); return; }
      line('· '+TERM.total+' unique domain(s) queued', 'l-info');
      // Batch polling: each request is short, so a time-limited host still
      // finishes the WHOLE list. This is the reliable default.
      line('$ checking in batches of '+BATCH+' …', 'l-cmd');
      setStat('0 / '+TERM.total);
      poll(0);
    })
    .catch(function(e){ fail('prepare failed: '+(e&&e.message?e.message:e)+'  — open  ?health=1'); });
}

function poll(offset){
  var fd = new FormData(); fd.append('offset', offset); fd.append('size', BATCH);
  fetch('?batch=1', {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){ return r.text(); })
    .then(function(t){
      var d; try { d = JSON.parse(t); }
      catch(e){ fail('batch did not return JSON — open  ?health=1'); return; }
      if(!d || !d.ok){ fail(d && d.error ? d.error : 'batch failed'); return; }
      setStat(Math.min(d.next, d.total)+' / '+d.total);
      enqueue(d.results || []);
      if(d.done){ TERM.lastBatch = true; TERM.reportUrl = d.report || '?report=1'; drain(); }
      else { poll(d.next); }
    })
    .catch(function(e){ fail('batch failed: '+(e&&e.message?e.message:e)); });
}

// Render queued verdicts smoothly (~12ms each) for a live line-by-line feel,
// independent of the network polling above.
function enqueue(list){ for(var i=0;i<list.length;i++){ TERM.queue.push(list[i]); } drain(); }
function drain(){
  if(TERM.draining) return;
  TERM.draining = true;
  (function step(){
    if(TERM.queue.length === 0){
      TERM.draining = false;
      if(TERM.lastBatch && !TERM.finished){
        onSummary({total:TERM.total, clean:TERM.counts.clean, suspicious:TERM.counts.suspicious, spam:TERM.counts.spam});
        onDone({report:TERM.reportUrl});
      }
      return;
    }
    onItem(TERM.queue.shift());
    setTimeout(step, 12);
  })();
}

function pad(s, n){ s = String(s); while(s.length < n){ s += ' '; } return s; }
function line(text, cls){
  var cur = el('termBody').querySelector('.cur');
  var span = document.createElement('span');
  if(cls){ span.className = cls; }
  span.textContent = text + '\n';
  el('termBody').insertBefore(span, cur);
  el('termBody').scrollTop = el('termBody').scrollHeight;
}
function onItem(v){
  TERM.checked++;
  var tier = v.tier || 'clean';
  TERM.counts[tier] = (TERM.counts[tier]||0) + 1;
  if(tier === 'spam'){ TERM.spam.push(v); }
  var tag  = tier==='spam' ? '[SPAM]' : (tier==='suspicious' ? '[SUSP]' : '[ OK ]');
  var http = v.http ? (' '+v.http) : '';
  var extra = tier==='clean' ? (v.score!=null ? ('   score '+v.score) : '') : ('   '+((v.signals||[]).join('; ')));
  line(tag+'  '+pad(v.domain, 32)+http+extra, tier==='spam'?'l-spam':(tier==='suspicious'?'l-susp':'l-clean'));
  setCounts();
}
function onSummary(s){
  line('', '');
  line('── summary ──  '+(s.total||TERM.checked)+' checked · '+(s.clean||0)+' clean · '+(s.suspicious||0)+' suspicious · '+(s.spam||0)+' spam', 'l-done');
}
function onDone(d){
  TERM.finished = true;
  if(d && d.report){ TERM.reportUrl = d.report; }
  clearInterval(TERM.timer);
  el('termSpin').textContent = '✓'; setStat('done');
  el('termActions').hidden = false; el('termClose').hidden = false;
  el('btnDisavow').disabled = TERM.spam.length === 0;
  el('btnDisavow').onclick = downloadDisavow;
  el('btnReport').onclick = function(){ window.location.href = TERM.reportUrl || '?report=1'; };
  el('termClose').onclick = function(){ el('term').hidden = true; };
}
function fail(msg){
  TERM.finished = true; clearInterval(TERM.timer);
  el('termSpin').textContent = '✗'; setStat('failed');
  line('[error] '+msg, 'l-err');
  el('termClose').hidden = false; el('termClose').onclick = function(){ el('term').hidden = true; };
}
function setStat(s){ el('termStat').textContent = s; }
function setCounts(){ el('termCounts').textContent = '🟢 '+(TERM.counts.clean||0)+' · 🟡 '+(TERM.counts.suspicious||0)+' · 🔴 '+(TERM.counts.spam||0); }
function downloadDisavow(){
  var d = new Date();
  var ds = d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);
  var out = ['# Disavow generated '+ds, '# Source: Backlink spam-check (hard-spam only)', '#'];
  TERM.spam.forEach(function(v){ out.push('# '+(((v.signals||[]).join('; '))||'spam')); out.push('domain:'+v.domain); });
  if(TERM.spam.length === 0){ out.push('# No spam domains detected.'); }
  var blob = new Blob([out.join('\n')+'\n'], {type:'text/plain;charset=utf-8'});
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'disavow.txt';
  document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href);
}
</script>
</body>
</html>
HTML;
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
