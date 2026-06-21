<?php
/**
 * Engine — the backlink scoring pipeline: parse input → fetch live → build the
 * target topic profile → detect PBN clusters → score & classify every domain →
 * split into ranked "prospects" and an "avoid" list.
 *
 * Pure analysis. No HTML, no storage, no globals beyond the $cfg array that is
 * threaded through every method (identical to the original procedural design).
 */
class Engine
{
    /** A blank backlink record with every field the pipeline may populate. */
    public static function newBacklink($raw): array
    {
        return [
            'raw' => trim((string)$raw), 'source_url' => '', 'registrable_domain' => '',
            'tld' => '', 'dr' => null, 'spam_score' => null, 'external_links_meta' => null,
            'http_status' => null, 'final_url' => '', 'redirected' => false,
            'reachable' => false, 'is_dead' => false, 'indexable' => true,
            'parked' => false, 'lang' => '', 'title' => '', 'meta_description' => '',
            'text_sample' => '', 'text_word_count' => 0, 'outbound_links' => 0,
            'internal_links' => 0, 'link_friendly' => false, 'friendly_markers' => [],
            'spam_points' => 0, 'spam_signals' => [], 'score' => 0.0, 'factors' => [],
            'factor_values' => [], 'status' => 'prospect', 'avoid_reasons' => [],
            'fetch_skipped' => false, 'audit' => null,
        ];
    }

    // ----------------------------------------------------- input parsing

    /**
     * Parse pasted text into backlink records (supports "url,dr,spam" lines).
     * When $keepPathQuery is true (per-URL mode) the source_url keeps its path +
     * query so different pages on one domain stay distinct; otherwise the URL is
     * reduced to scheme://host/path (domain-level analysis).
     */
    public static function loadLines($text, $cfg, $keepPathQuery = false): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Optional CSV-ish line: url,dr,spam (a domain/URL then a comma).
            $is_csv = strpos($line, ',') !== false && (
                preg_match('~^\s*https?://[^,\s]+\s*,~i', $line) ||
                preg_match('~^\s*[a-z0-9.\-]+\.[a-z]{2,}\s*,~i', $line));
            if ($is_csv) {
                $fields = str_getcsv($line);
                $url = trim($fields[0]);
                $bl = self::newBacklink($url);
                $norm = Support::normalizeUrl($url, $keepPathQuery);
                if ($norm) {
                    $bl['source_url'] = $norm;
                    [$bl['registrable_domain'], $bl['tld']] =
                        Support::registrableDomain(parse_url($norm, PHP_URL_HOST), $cfg['TWO_LEVEL_SUFFIXES']);
                }
                if (isset($fields[1]) && is_numeric(trim($fields[1]))) {
                    $bl['dr'] = (float)trim($fields[1]);
                }
                if (isset($fields[2]) && is_numeric(trim($fields[2]))) {
                    $bl['spam_score'] = (float)trim($fields[2]);
                }
                $rows[] = $bl;
                continue;
            }

            foreach (Support::splitConcatenated($line) as $piece) {
                $bl = self::newBacklink($piece);
                $norm = Support::normalizeUrl($piece, $keepPathQuery);
                if ($norm) {
                    $bl['source_url'] = $norm;
                    [$bl['registrable_domain'], $bl['tld']] =
                        Support::registrableDomain(parse_url($norm, PHP_URL_HOST), $cfg['TWO_LEVEL_SUFFIXES']);
                }
                $rows[] = $bl;
            }
        }
        return $rows;
    }

    // -------------------------------------------------- live fetch (cURL)

    /** Fetch many URLs in parallel with curl_multi, honouring an overall deadline. */
    public static function fetchMany($urls, $cfg, $onDone = null): array
    {
        $results = [];
        if (!function_exists('curl_multi_init') || !$urls) {
            return $results;
        }
        $urls = array_values(array_unique($urls));
        $queue = $urls;
        $i = 0;
        $n = count($queue);
        $deadline = time() + (int)$cfg['OVERALL_DEADLINE'];
        $fm_start = microtime(true);
        $fm_hit = false;
        $mh = curl_multi_init();
        $active = [];

        $add = function () use (&$i, &$queue, &$mh, &$active, $n, $cfg) {
            if ($i >= $n) {
                return false;
            }
            $url = $queue[$i];
            $i++;
            // Per-domain hard caps so ONE dead/slow site can never stall the run.
            $timeout = max(2, (int)$cfg['REQUEST_TIMEOUT']);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_TIMEOUT => $timeout,                          // hard total cap
                CURLOPT_CONNECTTIMEOUT => max(2, min($timeout, 4)),   // fast-fail dead hosts
                CURLOPT_NOSIGNAL => 1,                               // timeouts honored without SIGALRM
                CURLOPT_LOW_SPEED_LIMIT => 200,                      // abort domains that...
                CURLOPT_LOW_SPEED_TIME => $timeout,                  // ...trickle < 200 B/s for $timeout s
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_SSL_VERIFYPEER => (bool)$cfg['VERIFY_SSL'],
                CURLOPT_SSL_VERIFYHOST => $cfg['VERIFY_SSL'] ? 2 : 0,
                CURLOPT_USERAGENT => $cfg['USER_AGENT'],
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[Support::handleId($ch)] = ['ch' => $ch, 'url' => $url, 'start' => microtime(true)];
            return true;
        };

        for ($k = 0; $k < (int)$cfg['MAX_WORKERS']; $k++) {
            if (!$add()) {
                break;
            }
        }

        do {
            do {
                $mrc = curl_multi_exec($mh, $running);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = Support::handleId($ch);
                $meta = $active[$id] ?? null;
                $url = $meta['url'] ?? '';
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $err = curl_errno($ch);
                $results[$url] = [
                    'status' => ($code > 0) ? $code : null,
                    'final' => $final ?: $url,
                    'body' => ($body !== false && !($err && $code === 0)) ? (string)$body : '',
                ];
                if ($onDone) {
                    $ms = isset($meta['start']) ? (int)round((microtime(true) - $meta['start']) * 1000) : 0;
                    $onDone($url, $code, $results[$url]['final'], $results[$url]['body'], $ms);
                    // The callback consumed the body — free it so memory stays flat
                    // even for 1000+ domains (don't hold every HTML page at once).
                    $results[$url]['body'] = '';
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$id]);
                $add();
            }
            if ($running) {
                // Some libcurl builds return -1 immediately when there is nothing
                // to wait on; sleep a beat so we never busy-spin the CPU (which on
                // CloudLinux shared hosting trips the per-account CPU/IO throttle
                // and slows EVERY request on the subdomain to a crawl).
                if (curl_multi_select($mh, 1.0) === -1) {
                    usleep(50000);
                }
            }
            if (time() > $deadline) {
                $fm_hit = true;
                break;
            }
        } while ($running > 0 || $i < $n || count($active) > 0);

        foreach ($active as $a) {  // clean up anything still open (deadline hit)
            curl_multi_remove_handle($mh, $a['ch']);
            curl_close($a['ch']);
        }
        curl_multi_close($mh);
        Debug::log('fetchMany: requested=' . $n . ' completed=' . count($results)
            . ' deadline_hit=' . ($fm_hit ? 'YES (overall deadline)' : 'no')
            . ' in ' . round(microtime(true) - $fm_start, 1) . 's');
        return $results;
    }

    /** Extract title/lang/links/parked/guest-post signals from a fetched body. */
    public static function extractSignals(&$bl, $status, $final_url, $body, $cfg): void
    {
        $bl['http_status'] = $status;
        $bl['final_url'] = $final_url ?: $bl['source_url'];
        $bl['reachable'] = $status !== null;
        $bl['redirected'] = ($final_url && Support::hostOf($final_url) !== Support::hostOf($bl['source_url']));
        $dead = [404, 410, 500, 502, 503, 504];
        $bl['is_dead'] = ($status === null) || in_array($status, $dead, true);
        if ($body === '') {
            return;
        }
        $body = substr($body, 0, (int)$cfg['MAX_HTML_BYTES']);

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
            $bl['title'] = mb_substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300);
        }
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $body, $m)) {
            $bl['meta_description'] = mb_substr(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 500);
        }
        if (preg_match('/<html[^>]*\blang=["\']([a-zA-Z\-]+)/i', $body, $m)) {
            $bl['lang'] = strtolower(explode('-', $m[1])[0]);
        }
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'](.*?)["\']/is', $body, $m)
            && stripos($m[1], 'noindex') !== false) {
            $bl['indexable'] = false;
        }

        $own = $bl['registrable_domain'];
        $out = 0;
        $internal = 0;
        if (preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $body, $mm)) {
            foreach ($mm[1] as $href) {
                $low = strtolower($href);
                if ($href === '' || $href[0] === '#') {
                    continue;
                }
                if (strncmp($low, 'mailto:', 7) === 0 || strncmp($low, 'tel:', 4) === 0
                    || strncmp($low, 'javascript:', 11) === 0) {
                    continue;
                }
                foreach ($cfg['GUEST_POST_SLUGS'] as $slug) {
                    if (strpos($low, $slug) !== false) {
                        $bl['link_friendly'] = true;
                        break;
                    }
                }
                if (strncmp($low, 'http', 4) === 0) {
                    if ($own && strpos(Support::hostOf($href), $own) !== false) {
                        $internal++;
                    } else {
                        $out++;
                    }
                } else {
                    $internal++;
                }
            }
        }
        $bl['outbound_links'] = $out;
        $bl['internal_links'] = $internal;

        $noscript = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $body);
        $visible = html_entity_decode(strip_tags($noscript), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = trim(preg_replace('/\s+/', ' ', $visible));
        $bl['text_word_count'] = $visible === '' ? 0 : count(explode(' ', $visible));
        $bl['text_sample'] = mb_strtolower(mb_substr($visible, 0, 6000));

        $hay = mb_strtolower($bl['title'] . ' ' . $bl['text_sample']);
        foreach ($cfg['GUEST_POST_MARKERS'] as $marker) {
            if (strpos($hay, $marker) !== false) {
                $bl['link_friendly'] = true;
                if (!in_array($marker, $bl['friendly_markers'], true)) {
                    $bl['friendly_markers'][] = $marker;
                }
            }
        }
        $low = mb_strtolower($bl['title'] . ' ' . mb_substr($visible, 0, 4000));
        foreach ($cfg['PARKED_MARKERS'] as $marker) {
            if (strpos($low, $marker) !== false) {
                $bl['parked'] = true;
                break;
            }
        }
        if ($bl['text_word_count'] < 40 && (strpos($low, 'sale') !== false || strpos($low, 'domain') !== false)) {
            $bl['parked'] = true;
        }
    }

    // --------------------------------------------- target topic profile

    /** Most frequent meaningful words in a blob of text. */
    public static function topKeywords($text, $cfg, $n = 15): array
    {
        preg_match_all('/[a-z]{4,}/', mb_strtolower($text), $m);
        $counts = [];
        foreach ($m[0] as $w) {
            if (isset($cfg['STOPWORDS'][$w])) {
                continue;
            }
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, $n);
    }

    /** Build the target site's keyword profile (domain + optional live content). */
    public static function buildProfile($url, $cfg, $do_fetch): array
    {
        $kws = $cfg['NICHE_KEYWORDS'];
        $norm = Support::normalizeUrl($url);
        [$dom,] = Support::registrableDomain(parse_url($norm ?: $url, PHP_URL_HOST), $cfg['TWO_LEVEL_SUFFIXES']);
        preg_match_all('/[a-z]{4,}/', strtolower($dom), $m);
        foreach ($m[0] as $t) {
            if (!isset($cfg['STOPWORDS'][$t])) {
                $kws[] = $t;
            }
        }

        if ($do_fetch && $norm) {
            $res = self::fetchMany([$norm], $cfg);
            $r = $res[$norm] ?? null;
            if ($r && $r['body'] !== '') {
                $tmp = self::newBacklink($url);
                $tmp['source_url'] = $norm;
                $tmp['registrable_domain'] = $dom;
                self::extractSignals($tmp, $r['status'], $r['final'], $r['body'], $cfg);
                $kws = array_merge($kws, self::topKeywords(
                    $tmp['title'] . ' ' . $tmp['meta_description'] . ' ' . $tmp['text_sample'], $cfg, 15));
            }
        }
        $out = [];
        foreach ($kws as $k) {
            $k = trim(mb_strtolower($k));
            if ($k !== '') {
                $out[$k] = 1;
            }
        }
        return array_keys($out);
    }

    // ------------------------------------------- PBN / spam detection

    /** Flag domains that belong to a same-token network cluster (PBN footprint). */
    public static function detectPbnClusters($backlinks, $cfg): array
    {
        $buckets = [];
        foreach ($backlinks as $bl) {
            if ($bl['registrable_domain'] === '') {
                continue;
            }
            $sld = $bl['tld'] !== ''
                ? substr($bl['registrable_domain'], 0, -(strlen($bl['tld']) + 1))
                : $bl['registrable_domain'];
            $sld = strtolower($sld);
            foreach ($cfg['NETWORK_TOKENS'] as $tok) {
                if (strlen($tok) >= 4 && strpos($sld, $tok) !== false) {
                    $buckets[$tok . '|' . $bl['tld']][$bl['registrable_domain']] = 1;
                }
            }
        }
        $flagged = [];
        foreach ($buckets as $members) {
            if (count($members) >= $cfg['PBN_CLUSTER_MIN_SIZE']) {
                foreach ($members as $d => $_) {
                    $flagged[$d] = 1;
                }
            }
        }
        return $flagged;
    }

    /** True when the domain matches a toxic-content pattern (piracy/adult/etc.). */
    public static function isToxicNeighborhood($bl, $cfg): bool
    {
        $dom = strtolower($bl['registrable_domain'] !== '' ? $bl['registrable_domain'] : $bl['raw']);
        foreach ($cfg['TOXIC_NEIGHBORHOOD_PATTERNS'] as $p) {
            if (preg_match('/' . $p . '/i', $dom)) {
                return true;
            }
        }
        return false;
    }

    /** Sum spam-risk points and collect the signals that triggered them. */
    public static function computeSpamPoints($bl, $pbn, $cfg): array
    {
        $pts = 0;
        $sig = [];
        if ($bl['dr'] !== null && $bl['dr'] <= 0) {
            $pts += 2;
            $sig[] = 'DR 0';
        }
        $ext = $bl['external_links_meta'] !== null ? $bl['external_links_meta'] : ($bl['outbound_links'] ?: null);
        if ($ext && $ext > 100) {
            $pts += 2;
            $sig[] = "$ext outbound links (link-farm risk)";
        }
        if (in_array($bl['tld'], $cfg['BAD_TLDS'], true)) {
            $pts += 2;
            $sig[] = "suspicious TLD .{$bl['tld']}";
        }
        $name = strtolower($bl['registrable_domain']);
        foreach ($cfg['SPAMMY_NAME_SUBSTRINGS'] as $s) {
            if (strpos($name, $s) !== false) {
                $pts += 2;
                $sig[] = 'spammy domain name';
                break;
            }
        }
        if (isset($pbn[$bl['registrable_domain']])) {
            $pts += 2;
            $sig[] = 'PBN/link-network footprint';
        }
        if ($bl['spam_score'] !== null && $bl['spam_score'] >= 60) {
            $pts += 2;
            $sig[] = "toxicity {$bl['spam_score']}";
        }
        return [$pts, $sig];
    }

    // ------------------------------------------------------- scoring

    private static function relevanceValue($bl, $niche, $sat)
    {
        if (!$niche) {
            return null;
        }
        $strong = mb_strtolower($bl['title'] . ' ' . $bl['meta_description']);
        $domain = strtolower($bl['registrable_domain']);
        $body = $bl['text_sample'];
        // Allow a name-based relevance signal even when we could not fetch the
        // page (deadline-skipped domains) — as long as we have something to match.
        if (!$bl['reachable'] && $bl['title'] === '' && $domain === '') {
            return null;
        }
        $hits = 0.0;
        foreach ($niche as $kw) {
            $k = trim(mb_strtolower($kw));
            if ($k === '') {
                continue;
            }
            if (mb_strpos($strong, $k) !== false) {
                $hits += 1.0;
            } elseif ($domain !== '' && strpos($domain, $k) !== false) {
                $hits += 0.6;
            } elseif ($body !== '' && mb_strpos($body, $k) !== false) {
                $hits += 0.4;
            }
        }
        return min(1.0, $hits / max(1.0, $sat));
    }

    private static function authorityValue($bl): array
    {
        if ($bl['dr'] !== null) {
            return [max(0.0, min(1.0, $bl['dr'] / 100.0)), 'DR=' . $bl['dr']];
        }
        if (!$bl['reachable']) {
            return [null, 'unreachable'];
        }
        $s = 0.0;
        if (strncmp($bl['final_url'], 'https://', 8) === 0) {
            $s += 0.2;
        }
        if ($bl['indexable']) {
            $s += 0.2;
        }
        $s += 0.6 * min(1.0, $bl['text_word_count'] / 800.0);
        return [min(1.0, $s), '~' . $bl['text_word_count'] . 'w content (proxy; DR needs API)'];
    }

    private static function healthValue($bl): array
    {
        if (!$bl['reachable']) {
            return [0.0, 'no response'];
        }
        $s = 1.0;
        $notes = [];
        if ($bl['is_dead']) {
            $s -= 0.7;
            $notes[] = 'dead';
        }
        if ($bl['parked']) {
            $s -= 0.8;
            $notes[] = 'parked';
        }
        if (!$bl['indexable']) {
            $s -= 0.4;
            $notes[] = 'noindex';
        }
        if ($bl['redirected']) {
            $s -= 0.15;
            $notes[] = 'redirected';
        }
        return [max(0.0, min(1.0, $s)), $notes ? implode(', ', $notes) : 'live & clean'];
    }

    private static function friendlinessValue($bl): array
    {
        if ($bl['link_friendly']) {
            $m = $bl['friendly_markers'] ? implode(', ', array_slice($bl['friendly_markers'], 0, 2)) : 'guest-post path found';
            return [1.0, "accepts contributions ($m)"];
        }
        return [0.55, 'no public guest-post path (direct outreach still possible)'];
    }

    private static function tldLangGeoValue($bl, $cfg): array
    {
        $base = $cfg['TLD_SCORES'][$bl['tld']] ?? $cfg['NEUTRAL_TLD_SCORE'];
        $lang = isset($cfg['PREFERRED_LANGS'][$bl['lang']]) ? 1.0 : ($bl['lang'] !== '' ? 0.5 : 0.8);
        $geo = isset($cfg['PREFERRED_GEO_TLDS'][$bl['tld']]) ? 1.0 : 0.85;
        return [min(1.0, 0.45 * $base + 0.35 * $lang + 0.20 * $geo),
                "tld .{$bl['tld']}, lang " . ($bl['lang'] ?: '?')];
    }

    private static function spamSafetyValue($bl, $cfg): array
    {
        $val = max(0.0, 1.0 - $bl['spam_points'] / $cfg['SPAM_SAFETY_CAP']);
        return [$val, $bl['spam_points'] === 0 ? 'clean' : "{$bl['spam_points']} risk signal(s)"];
    }

    /** Compute the weighted 0–100 score and per-factor breakdown for a backlink. */
    public static function scoreProspect(&$bl, $niche, $cfg): void
    {
        [$auth_v, $auth_n] = self::authorityValue($bl);
        [$health_v, $health_n] = self::healthValue($bl);
        [$friend_v, $friend_n] = self::friendlinessValue($bl);
        [$tlg_v, $tlg_n] = self::tldLangGeoValue($bl, $cfg);
        [$safe_v, $safe_n] = self::spamSafetyValue($bl, $cfg);
        $raw = [
            ['relevance', self::relevanceValue($bl, $niche, $cfg['RELEVANCE_SATURATION']), 'topical fit to your site'],
            ['authority', $auth_v, $auth_n],
            ['link_friendliness', $friend_v, $friend_n],
            ['domain_health', $health_v, $health_n],
            ['tld_lang_geo', $tlg_v, $tlg_n],
            ['spam_safety', $safe_v, $safe_n],
        ];
        $W = $cfg['WEIGHTS'];
        $available = 0;
        foreach ($raw as [$k, $v, $note]) {
            if ($v !== null) {
                $available += $W[$k];
            }
        }
        if ($available <= 0) {
            $bl['score'] = 0.0;
            $bl['factors'] = [['(no signals)', 0, 0, 'nothing measurable']];
            return;
        }
        $total = 0.0;
        $factors = [];
        foreach ($raw as [$k, $v, $note]) {
            if ($v === null) {
                continue;
            }
            $max = $W[$k] / $available * 100.0;
            $pts = $v * $max;
            $total += $pts;
            $factors[] = [$k, round($pts, 1), round($max, 1), $note];
            $bl['factor_values'][$k] = round($v, 3);
        }
        $bl['score'] = round($total, 1);
        $bl['factors'] = $factors;
    }

    /** Decide whether a backlink is a prospect or belongs on the avoid list. */
    public static function classifyProspect(&$bl, $cfg, $do_fetch): void
    {
        $reasons = [];
        if ($bl['registrable_domain'] === '') {
            $reasons[] = 'malformed / unparseable entry';
        }
        if ($cfg['EXCLUDE_TOXIC_NEIGHBORHOODS'] && self::isToxicNeighborhood($bl, $cfg)) {
            $reasons[] = 'toxic neighborhood (piracy/adult/gambling) — never link from here';
        }
        if ($do_fetch && $bl['source_url'] !== '' && $bl['reachable']) {
            if ($cfg['EXCLUDE_DEAD'] && $bl['is_dead']) {
                $reasons[] = 'dead page (cannot place a link)';
            }
            if ($cfg['EXCLUDE_PARKED'] && $bl['parked']) {
                $reasons[] = 'parked / for-sale placeholder';
            }
            if ($cfg['EXCLUDE_NOINDEX'] && !$bl['indexable']) {
                $reasons[] = 'de-indexed (a link here passes no value)';
            }
        } elseif ($do_fetch && $bl['source_url'] !== '' && !$bl['reachable'] && empty($bl['fetch_skipped'])) {
            $reasons[] = 'unreachable (no response)';
        }
        // Domains skipped by the fetch deadline are NOT excluded — they are kept
        // and ranked on offline signals (name / TLD / DR + the removal audit), so
        // the ENTIRE input list is analysed instead of only the first ~100.
        $bl['avoid_reasons'] = $reasons;
        $bl['status'] = $reasons ? 'avoid' : 'prospect';
    }

    /** Drop duplicate registrable domains, keeping the first occurrence. */
    public static function dedupeDomains($backlinks): array
    {
        $seen = [];
        $out = [];
        foreach ($backlinks as $bl) {
            $d = $bl['registrable_domain'];
            if ($d !== '') {
                if (isset($seen[$d])) {
                    continue;
                }
                $seen[$d] = 1;
            }
            $out[] = $bl;
        }
        return $out;
    }

    /** A short "why" string listing a backlink's top three scoring factors. */
    public static function whyStr($bl): string
    {
        $f = $bl['factors'];
        usort($f, fn($a, $b) => $b[1] <=> $a[1]);
        $parts = [];
        foreach (array_slice($f, 0, 3) as [$n, $p, $m, $note]) {
            $parts[] = str_replace('_', ' ', $n) . " $p/$m";
        }
        return implode(' · ', $parts);
    }

    // ------------------------------------------------------ pipeline

    /**
     * Run the full pipeline and return:
     *   ['prospects' => [...sorted...], 'avoid' => [...], 'niche' => [...], 'total' => N]
     */
    public static function runPipeline($text, $opts, $cfg, $onProgress = null): array
    {
        // $onProgress($line) streams bash-style progress to the terminal loader.
        $log = $onProgress ?: static function ($l) {};

        // Apply runtime option overrides. Allow many parallel workers so even
        // large lists (hundreds of domains) can be live-fetched within the
        // overall deadline instead of silently timing out after the first ~100.
        $cfg['VERIFY_SSL'] = $opts['verify_ssl'];
        $cfg['MAX_WORKERS'] = max(1, min(64, (int)$opts['workers']));
        $do_fetch = $opts['live'];

        $log('[init] parsing input …');
        $backlinks = self::loadLines($text, $cfg);
        if ($opts['limit'] > 0) {
            $backlinks = array_slice($backlinks, 0, $opts['limit']);
        }
        $log('[init] ' . count($backlinks) . ' entr' . (count($backlinks) === 1 ? 'y' : 'ies') . ' parsed');

        $target_host = Support::hostOf(Support::normalizeUrl($opts['target_url']) ?: $opts['target_url']);
        $log('[init] building topic profile from ' . ($target_host ?: $opts['target_url']) . ' …');
        $niche = self::buildProfile($opts['target_url'], $cfg, $do_fetch);
        $pbn = self::detectPbnClusters($backlinks, $cfg);
        if ($pbn) {
            $log('[pbn]  ' . count($pbn) . ' domain(s) in a link-network cluster');
        }

        if ($do_fetch) {
            // Index records by their URL, then process each page AS IT ARRIVES and
            // let fetchMany free its body immediately — so memory stays flat even
            // for 1000+ domains (the old "fetch all, then loop" held every HTML
            // body at once and peaked ~186 MB at 494 domains → OOM at scale).
            $byUrl = [];
            foreach ($backlinks as $idx => $bl) {
                if ($bl['source_url'] !== '') {
                    $byUrl[$bl['source_url']][] = $idx;
                }
            }
            $log('[fetch] live fetch: ' . count($byUrl) . ' url(s) · workers=' . $cfg['MAX_WORKERS']
                . ' · timeout=' . $cfg['REQUEST_TIMEOUT'] . 's · deadline=' . $cfg['OVERALL_DEADLINE'] . 's');
            $fetchedIdx = [];
            self::fetchMany(array_keys($byUrl), $cfg, function ($url, $status, $final, $body, $ms)
                use (&$backlinks, &$byUrl, &$fetchedIdx, $cfg, $log) {
                foreach ($byUrl[$url] as $idx) {
                    self::extractSignals($backlinks[$idx], $status, $final, $body, $cfg);
                    $fetchedIdx[$idx] = true;
                }
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                $log('[fetch] ' . str_pad($status > 0 ? (string)$status : 'ERR', 3) . '  ' . $host . ($ms ? '  (' . $ms . 'ms)' : ''));
            });
            foreach ($backlinks as $idx => $bl) {
                // Not reached before the deadline → audited offline (name/TLD).
                if (($bl['source_url'] ?? '') !== '' && empty($fetchedIdx[$idx])) {
                    $backlinks[$idx]['fetch_skipped'] = true;
                }
            }
        }

        $before = count($backlinks);
        $backlinks = self::dedupeDomains($backlinks);
        if ($before !== count($backlinks)) {
            $log('[dedupe] ' . ($before - count($backlinks)) . ' duplicate domain(s) merged');
        }

        // Score, classify, and run the removal-risk audit for EVERY domain. The
        // audit is string-based, so all input domains are covered no matter how
        // many were live-fetched within the time limit.
        $log('[score] ranking ' . count($backlinks) . ' domain(s) …');
        $tiers = ['disavow' => 0, 'review' => 0, 'keep' => 0];
        foreach ($backlinks as &$bl) {
            [$bl['spam_points'], $bl['spam_signals']] = self::computeSpamPoints($bl, $pbn, $cfg);
            self::scoreProspect($bl, $niche, $cfg);
            self::classifyProspect($bl, $cfg, $do_fetch);
            $bl['audit'] = self::auditRisk($bl, $cfg, $pbn);
            $t = $bl['audit']['tier'] ?? 'keep';
            if (isset($tiers[$t])) {
                $tiers[$t]++;
            }
        }
        unset($bl);

        // Measure how completely the live fetch covered the list (for the report
        // banner) — so a partial fetch is transparent, not silently "unreachable".
        $fetchable = 0;
        $fetched_ok = 0;
        foreach ($backlinks as $bl) {
            if ($bl['source_url'] !== '') {
                $fetchable++;
                if (empty($bl['fetch_skipped'])) {
                    $fetched_ok++;
                }
            }
        }
        if ($do_fetch && $fetchable > $fetched_ok) {
            $log('[fetch] ' . ($fetchable - $fetched_ok) . ' domain(s) not fetched in time — audited offline');
        }

        $prospects = array_values(array_filter($backlinks, fn($b) => $b['status'] === 'prospect'));
        usort($prospects, fn($a, $b) => $b['score'] <=> $a['score']);
        $avoid = array_values(array_filter($backlinks, fn($b) => $b['status'] === 'avoid'));

        $log('[audit] disavow=' . $tiers['disavow'] . '  review=' . $tiers['review'] . '  keep=' . $tiers['keep']);
        $log('[done]  ' . count($prospects) . ' prospect(s) · ' . count($avoid) . ' avoid · ' . $tiers['disavow'] . ' to disavow');

        return [
            'prospects' => $prospects, 'avoid' => $avoid, 'niche' => $niche,
            'total' => count($backlinks),
            'live' => $do_fetch, 'fetchable' => $fetchable, 'fetched' => $fetched_ok,
        ];
    }

    /**
     * Backlink-removal risk audit (the "For removal" classifier). Conservative
     * by design — only HARD, confirmable spam signals auto-disavow; soft signals
     * go to REVIEW (manual), and everything else is KEEP. Returns:
     *   ['tier' => 'disavow'|'review'|'keep', 'risk' => 0..100,
     *    'signals' => [...], 'confidence' => 'high'|'']
     *
     * Hard signals are derived from the domain string (toxic-content pattern,
     * spammy TLD, spammy name substring, or a provided toxicity metric) so they
     * work even for domains that were not live-fetched.
     */
    public static function auditRisk($bl, $cfg, $pbn): array
    {
        // --- Step 1: hard spam signals -> DISAVOW (each traces to a match) ---
        $hard = [];
        if (self::isToxicNeighborhood($bl, $cfg)) {
            $hard[] = 'toxic-content pattern';
        }
        if ($bl['tld'] !== '' && in_array($bl['tld'], $cfg['BAD_TLDS'], true)) {
            $hard[] = 'spammy TLD .' . $bl['tld'];
        }
        $name = strtolower($bl['registrable_domain']);
        if ($name !== '') {
            foreach ($cfg['SPAMMY_NAME_SUBSTRINGS'] as $s) {
                if (strpos($name, $s) !== false) {
                    $hard[] = 'spammy name "' . $s . '"';
                    break;
                }
            }
        }
        if ($bl['spam_score'] !== null && $bl['spam_score'] >= 60) {
            $hard[] = 'provided toxicity ' . rtrim(rtrim(number_format($bl['spam_score'], 1), '0'), '.');
        }

        // --- Step 2: soft signals -> REVIEW (scored, never auto-disavowed) ---
        $score = 0;
        $soft = [];
        if ($bl['reachable']) {
            if ($bl['parked']) {
                $score += 40;
                $soft[] = 'parked / for-sale page (+40)';
            } elseif ($bl['is_dead']) {
                $score += 40;
                $soft[] = 'dead page (+40)';
            }
            if (!$bl['indexable']) {
                $score += 30;
                $soft[] = 'noindex / de-indexed (+30)';
            }
            if ($bl['text_word_count'] > 0 && $bl['text_word_count'] < 120) {
                $score += 25;
                $soft[] = 'thin auto-generated content (+25)';
            }
            $rel = $bl['factor_values']['relevance'] ?? null;
            if ($rel !== null && $rel < 0.05) {
                $score += 15;
                $soft[] = 'irrelevant niche (+15)';
            }
        }
        if (isset($pbn[$bl['registrable_domain']])) {
            $score += 20;
            $soft[] = 'PBN / link-network footprint (+20)';
        }

        // --- Step 3: decide. Bias to KEEP — only HARD signals auto-disavow. ---
        if ($hard) {
            return ['tier' => 'disavow', 'risk' => 100, 'signals' => $hard, 'confidence' => 'high'];
        }
        if ($score > 0) {
            return ['tier' => 'review', 'risk' => min(100, $score), 'signals' => $soft, 'confidence' => ''];
        }
        return ['tier' => 'keep', 'risk' => 0, 'signals' => [], 'confidence' => ''];
    }

    // ------------------------------------------- streaming / batch helpers

    /** Drop duplicate URLs (per-URL mode), keeping the first occurrence. */
    public static function dedupeUrls($backlinks): array
    {
        $seen = [];
        $out = [];
        foreach ($backlinks as $bl) {
            $key = $bl['source_url'] !== '' ? $bl['source_url'] : $bl['raw'];
            if ($key !== '') {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = 1;
            }
            $out[] = $bl;
        }
        return $out;
    }

    /**
     * Parse the pasted input into records AND report how the list collapsed, so
     * the UI can be transparent about it (e.g. "462 URLs → 108 unique domains").
     *
     * Default: one record per registrable domain (best for Disavow). When
     * $opts['per_url'] is set: one record per unique URL (path + query kept).
     * Every record carries 'url_count' = how many input URLs mapped to its
     * registrable domain (powers the per-domain badge). Returns:
     *   ['records'=>[...], 'submitted'=>int, 'unique'=>int, 'merged'=>int]
     */
    public static function parseRecordsWithStats($text, $opts, $cfg): array
    {
        $per_url = !empty($opts['per_url']);
        $parsed = self::loadLines($text, $cfg, $per_url);
        if (!empty($opts['limit']) && (int)$opts['limit'] > 0) {
            $parsed = array_slice($parsed, 0, (int)$opts['limit']);
        }
        $submitted = count($parsed);

        // How many input URLs map to each registrable domain (badge source).
        $domainUrlCounts = [];
        foreach ($parsed as $bl) {
            $d = $bl['registrable_domain'];
            if ($d !== '') {
                $domainUrlCounts[$d] = ($domainUrlCounts[$d] ?? 0) + 1;
            }
        }

        $records = $per_url ? self::dedupeUrls($parsed) : self::dedupeDomains($parsed);
        $records = array_values($records);
        foreach ($records as $i => $rec) {
            $d = $rec['registrable_domain'];
            $records[$i]['url_count'] = ($d !== '' && isset($domainUrlCounts[$d])) ? $domainUrlCounts[$d] : 1;
        }
        $unique = count($records);

        return [
            'records'   => $records,
            'submitted' => $submitted,
            'unique'    => $unique,
            'merged'    => max(0, $submitted - $unique),
        ];
    }

    /** Parse + de-duplicate the input list into backlink records (no fetch). */
    public static function parseRecords($text, $opts, $cfg): array
    {
        return self::parseRecordsWithStats($text, $opts, $cfg)['records'];
    }

    /** Score + classify + audit one (already-fetched-or-offline) backlink. */
    public static function processOne(&$bl, $niche, $pbn, $cfg, $do_fetch): void
    {
        [$bl['spam_points'], $bl['spam_signals']] = self::computeSpamPoints($bl, $pbn, $cfg);
        self::scoreProspect($bl, $niche, $cfg);
        self::classifyProspect($bl, $cfg, $do_fetch);
        $bl['audit'] = self::auditRisk($bl, $cfg, $pbn);
    }

    /** A compact live "verdict" for one backlink (Spam / Suspicious / Clean). */
    public static function verdict($bl): array
    {
        $map = ['disavow' => 'spam', 'review' => 'suspicious', 'keep' => 'clean'];
        $tier = $bl['audit']['tier'] ?? 'keep';
        return [
            'domain'  => $bl['registrable_domain'] !== '' ? $bl['registrable_domain'] : $bl['raw'],
            'http'    => $bl['http_status'],
            'tier'    => $map[$tier] ?? 'clean',
            'score'   => $bl['score'],
            'signals' => array_values($bl['audit']['signals'] ?? []),
        ];
    }

    /** Drop the heavy text fields before persisting a record between batches. */
    public static function slimRecord($bl): array
    {
        unset($bl['text_sample'], $bl['meta_description']);
        return $bl;
    }

    /**
     * Process ONE small slice of the record list — fetch (if live), score,
     * classify and audit — and return the live verdicts plus slim records for
     * persistence between batches.
     *
     * This is the ONLY code path the web app uses to do scoring work, and it is
     * hard-bounded: REQUEST_TIMEOUT caps each domain, OVERALL_DEADLINE caps the
     * whole slice. A single batch therefore can never run long enough to hit the
     * host's execution limit or tie up a PHP worker — which is what made the old
     * "whole list in one request" path hang and lock the subdomain.
     *
     * @return array{rows: array<int,array>, slim: array<int,array>}
     */
    public static function processSlice($records, $offset, $size, $niche, $pbn, $opts, $cfg): array
    {
        $do_fetch = !empty($opts['live']);
        $cfg['MAX_WORKERS']     = max(1, min(64, (int)($opts['workers'] ?? $cfg['MAX_WORKERS'])));
        $cfg['VERIFY_SSL']      = !empty($opts['verify_ssl']);
        $cfg['REQUEST_TIMEOUT'] = 5;    // per-domain hard cap (one dead site can't stall the slice)
        $cfg['OVERALL_DEADLINE'] = 20;  // whole-slice hard cap (a batch is always short)

        $slice = array_slice($records, (int)$offset, (int)$size, true);
        $fetched = [];
        if ($do_fetch && function_exists('curl_multi_init')) {
            $urls = [];
            foreach ($slice as $rec) {
                if (($rec['source_url'] ?? '') !== '') {
                    $urls[] = $rec['source_url'];
                }
            }
            if ($urls) {
                $fetched = self::fetchMany($urls, $cfg);
            }
        }

        $rows = [];
        $slim = [];
        foreach ($slice as $idx => $rec) {
            if ($do_fetch && isset($fetched[$rec['source_url']])) {
                $f = $fetched[$rec['source_url']];
                self::extractSignals($rec, $f['status'], $f['final'], $f['body'], $cfg);
            } elseif ($do_fetch && ($rec['source_url'] ?? '') !== '') {
                $rec['fetch_skipped'] = true;
            }
            self::processOne($rec, $niche, $pbn, $cfg, $do_fetch);
            $rows[] = self::verdict($rec);
            $slim[$idx] = self::slimRecord($rec);
        }
        return ['rows' => $rows, 'slim' => $slim];
    }

    /** Build the final report payload from already-processed records. */
    public static function assembleReport($records, $niche, $opts): array
    {
        $do_fetch = !empty($opts['live']);
        $fetchable = 0;
        $fetched = 0;
        foreach ($records as $bl) {
            if (($bl['source_url'] ?? '') !== '') {
                $fetchable++;
                if (empty($bl['fetch_skipped'])) {
                    $fetched++;
                }
            }
        }
        $prospects = array_values(array_filter($records, fn($b) => ($b['status'] ?? '') === 'prospect'));
        usort($prospects, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $avoid = array_values(array_filter($records, fn($b) => ($b['status'] ?? '') === 'avoid'));
        return [
            'prospects' => $prospects, 'avoid' => $avoid, 'niche' => $niche,
            'total' => count($records),
            'live' => $do_fetch, 'fetchable' => $fetchable, 'fetched' => $fetched,
        ];
    }
}
