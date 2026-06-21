<?php
/**
 * Router — the single entry point's request dispatcher. Ordering matters:
 *   1. Weekly cron endpoint (token-authenticated, bypasses the login).
 *   2. Login gate (asked once per browser).
 *   3. Backlink Notif tab.
 *   4. Cached report view (refresh-safe).
 *   5. Scorer analyze (POST → compute → cache → PRG redirect).
 *   6. Scorer form (default GET).
 */
class Router
{
    /** Dispatch the current request. Echoes a response and exits. */
    public static function dispatch($cfg): void
    {
        // (1) Weekly cron endpoint — runs before HTML routing AND before the
        //     login gate (it authenticates with its own secret token).
        if (isset($_GET['cron'])) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            if (!hash_equals(NOTIF_CRON_TOKEN, (string)($_GET['token'] ?? ''))) {
                http_response_code(403);
                echo "forbidden\n";
                return;
            }
            // ?force=1 bypasses the 7-day throttle for a manual test run.
            $res = Monitor::runCheck(isset($_GET['force']) && $_GET['force'] === '1', $cfg);
            Debug::log('cron: ' . $res);
            echo $res . "\n";
            return;
        }

        // (1b) Health / self-test — open WITHOUT login so the host can always be
        //      diagnosed. Reports the limits that actually break large lists.
        if (isset($_GET['health'])) {
            self::health();
            return;
        }

        // (2) Login gate — asked once per browser, then remembered ~1 year.
        if (Security::authEnabled()) {
            if (isset($_GET['logout'])) {
                Security::clearAuthCookie();
                header('Location: ?');
                return;
            }
            if (!Security::isLoggedIn()) {
                if (($_POST['action'] ?? '') === 'login') {
                    $u = (string)($_POST['username'] ?? '');
                    $p = (string)($_POST['password'] ?? '');
                    if (hash_equals(AUTH_USER, $u) && hash_equals(AUTH_PASS, $p)) {
                        Security::setAuthCookie();
                        header('Location: ' . (($_POST['next'] ?? '') === 'notif' ? '?tab=notif' : '?'));
                        return;
                    }
                    echo View::login('Wrong username or password.', (string)($_POST['next'] ?? ''));
                    return;
                }
                echo View::login('', (string)($_GET['tab'] ?? ''));
                return;
            }
        }

        // Logged in (or login disabled). Establish this browser's cache id.
        $uid = Security::clientUid();

        // (2b) Debug log viewer (login-gated): ?debug=1 / ?debug=download / ?debug=clear
        if (isset($_GET['debug'])) {
            $d = (string)$_GET['debug'];
            if ($d === 'clear') {
                Debug::clearLog();
                header('Location: ?debug=1');
                return;
            }
            if ($d === 'download') {
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="debug.log"');
                }
                echo Debug::readLog();
                return;
            }
            echo Debug::renderViewer();
            return;
        }

        // (3) "Backlink Notif" tab — GET view (?tab=notif) or its POST actions.
        if (($_GET['tab'] ?? '') === 'notif' || strpos((string)($_POST['action'] ?? ''), 'notif_') === 0) {
            self::handleNotif($cfg);
            return;
        }

        // (4) Cached report view — refresh-safe, served from this browser's
        //     encrypted cache so a refresh never re-runs the slow analysis.
        if (isset($_GET['report'])) {
            $cached = function_exists('openssl_decrypt') ? Security::cacheGet($uid, 'report', 86400) : null;
            if ($cached !== null && $cached !== '') {
                echo $cached;
                return;
            }
            header('Location: ?'); // nothing cached for this browser → form
            return;
        }

        // (5) Live spam-check endpoints driven by the streaming console.
        //     prepare (POST) stores the encrypted job + builds profile/PBN once;
        //     sse (GET) streams per-domain verdicts via Server-Sent Events;
        //     batch (POST) is the polling fallback (one slice per request).
        if (($_GET['prepare'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            self::prepareJob($cfg, $uid);
            return;
        }
        if (($_GET['sse'] ?? '') === '1') {
            self::sseStream($cfg, $uid);
            return;
        }
        if (($_GET['batch'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            self::batchCheck($cfg, $uid);
            return;
        }

        // (6) Scorer analyze (plain POST, no-JS fallback). Compute, cache, redirect.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (ACCESS_PASSWORD !== '' && (($_POST['pw'] ?? '') !== ACCESS_PASSWORD)) {
                echo View::form($cfg, 'Wrong password.', $_POST);
                return;
            }

            // Gather input text (uploaded file overrides textarea if present).
            $text = (string)($_POST['domains'] ?? '');
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $uploaded = @file_get_contents($_FILES['file']['tmp_name']);
                if ($uploaded !== false && trim($uploaded) !== '') {
                    $text = $uploaded;
                }
            }
            if (trim($text) === '') {
                echo View::form($cfg, 'Please paste some domains or upload a file.', $_POST);
                return;
            }

            $opts = [
                'target_url' => trim($_POST['target_url'] ?? $cfg['TARGET_URL']) ?: $cfg['TARGET_URL'],
                'limit' => max(0, (int)($_POST['limit'] ?? 0)),
                'workers' => (int)($_POST['workers'] ?? $cfg['MAX_WORKERS']),
                'live' => !empty($_POST['live']),
                'verify_ssl' => !empty($_POST['verify_ssl']),
            ];
            $extra = array_filter(array_map('trim', explode(',', $_POST['niche'] ?? '')));
            if ($extra) {
                $cfg['NICHE_KEYWORDS'] = array_merge($cfg['NICHE_KEYWORDS'], $extra);
            }

            Debug::log('analyze (no-JS POST): full pipeline on the WHOLE list in ONE request — the path that dies on time-limited hosts');
            $r = Engine::runPipeline($text, $opts, $cfg);
            Debug::log('analyze (no-JS POST): done — ' . count($r['prospects']) . ' prospects, ' . count($r['avoid']) . ' avoid');
            $html = View::report($r, $opts);

            // Cache the finished report for this browser, then PRG-redirect.
            if (function_exists('openssl_encrypt') && Security::cachePut($uid, 'report', $html)) {
                header('Location: ?report=1');
                return;
            }
            echo $html; // fallback when caching is unavailable
            return;
        }

        // (7) Default: the input form.
        echo View::form($cfg);
    }

    /**
     * ?health=1 — a no-login self-test page. It reports the host facts that
     * actually cause "only ~100 of 700 shown": whether the execution-time limit
     * can be raised, plus OpenSSL/cURL, writable data dir, and that every src/
     * class loaded. Share this page to diagnose a stubborn host.
     */
    private static function health(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $rows = [];
        $add = function ($name, $ok, $val) use (&$rows) {
            $rows[] = [$name, $ok, $val];
        };

        $add('PHP version', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
        $add('OpenSSL (encryption, login, cache, streaming)', function_exists('openssl_encrypt'),
            function_exists('openssl_encrypt') ? 'available' : 'MISSING — streaming & cache are disabled');
        $add('cURL (fetching sites)', function_exists('curl_multi_init'),
            function_exists('curl_multi_init') ? 'available' : 'MISSING — cannot fetch sites');
        $add('mbstring', null, extension_loaded('mbstring') ? 'available' : 'polyfill in use (ok)');

        // Execution time — the #1 cause of the "100 of 700" symptom.
        $before = (int)ini_get('max_execution_time');
        @set_time_limit(0);
        $after = (int)ini_get('max_execution_time');
        $canRaise = ($after === 0 || $after >= 300);
        $add('max_execution_time', null,
            ($before === 0 ? 'unlimited' : $before . 's') . ' → after set_time_limit(0): ' . ($after === 0 ? 'unlimited' : $after . 's'));
        $add('Can the host raise the time limit?', $canRaise,
            $canRaise ? 'yes — long requests allowed' : 'NO — host caps it. Batch mode handles this automatically.');

        $add('memory_limit', null, ini_get('memory_limit'));
        $add('post_max_size', null, ini_get('post_max_size'));
        $add('zlib.output_compression', null, ini_get('zlib.output_compression') ? 'ON (can delay SSE — batch mode unaffected)' : 'off');

        $classes = ['Config', 'Support', 'Engine', 'Security', 'Monitor', 'View'];
        $missing = array_values(array_filter($classes, fn($c) => !class_exists($c)));
        $add('All src/ classes loaded', empty($missing),
            empty($missing) ? 'yes' : 'MISSING: ' . implode(', ', $missing) . ' — upload the whole src/ folder');

        Support::ensureDataDir();
        $dir = Support::dataDir();
        $writable = is_dir($dir) && is_writable($dir);
        $add('Data dir writable (notif_data/)', $writable, $writable ? $dir : 'NOT writable: ' . $dir);

        $enc = Security::encrypt('healthcheck');
        $dec = $enc !== false ? Security::decrypt($enc) : null;
        $add('Encrypted cache round-trip', $dec === 'healthcheck',
            $dec === 'healthcheck' ? 'ok' : 'FAILED — check OpenSSL and NOTIF_SECRET_KEY');

        $add('Login gate', null, Security::authEnabled() ? 'enabled' : 'disabled');

        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Health check</title>';
        echo '<style>body{font:14px system-ui,Segoe UI,Arial;margin:28px;color:#1d2327;max-width:780px}'
            . 'h1{font-size:20px}table{border-collapse:collapse;width:100%}'
            . 'td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:top}'
            . '.s{width:58px;font-weight:700}.ok{color:#08820a}.no{color:#c00}.warn{color:#b66b00}'
            . 'code{background:#f3f3f3;padding:1px 5px;border-radius:3px}.lead{color:#555;line-height:1.6}</style>';
        echo '<h1>🩺 Backlink Checker — health check</h1>';
        echo '<p class="lead">If <b>“Can the host raise the time limit?”</b> is <b style="color:#c00">NO</b>, that is exactly why a big list only showed ~100 in one shot. '
            . 'The analyzer now runs in small <b>batches</b> (a short request each), so it finishes the whole list regardless. '
            . 'Anything red below should be fixed.</p>';
        echo '<table>';
        foreach ($rows as [$name, $ok, $val]) {
            $cls = $ok === true ? 'ok' : ($ok === false ? 'no' : 'warn');
            $tag = $ok === true ? 'PASS' : ($ok === false ? 'FAIL' : 'INFO');
            echo '<tr><td class="s ' . $cls . '">' . $tag . '</td><td><b>' . Support::h($name) . '</b></td><td>' . Support::h($val) . '</td></tr>';
        }
        echo '</table>';
        echo '<p class="lead">Make sure you uploaded <code>index.php</code> + <code>config.php</code> + the whole <code>src/</code> folder together.</p>';
    }

    /** Gather + sanitise the analyse inputs (textarea or uploaded file). */
    private static function collectInput($cfg): array
    {
        $text = (string)($_POST['domains'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $uploaded = @file_get_contents($_FILES['file']['tmp_name']);
            if ($uploaded !== false && trim($uploaded) !== '') {
                $text = $uploaded;
            }
        }
        $opts = [
            'target_url' => trim($_POST['target_url'] ?? $cfg['TARGET_URL']) ?: $cfg['TARGET_URL'],
            'limit' => max(0, (int)($_POST['limit'] ?? 0)),
            'workers' => (int)($_POST['workers'] ?? $cfg['MAX_WORKERS']),
            'live' => !empty($_POST['live']),
            'verify_ssl' => !empty($_POST['verify_ssl']),
        ];
        return [$text, $opts];
    }

    /**
     * ?prepare=1 (POST) — validate the list, build the topic profile + PBN map
     * once, store an encrypted per-browser "job", and return {ok,total} as JSON.
     * The slow per-domain checking then happens over SSE or batch polling.
     */
    private static function prepareJob($cfg, $uid): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        if (ACCESS_PASSWORD !== '' && (($_POST['pw'] ?? '') !== ACCESS_PASSWORD)) {
            echo json_encode(['ok' => false, 'error' => 'Wrong password.']);
            return;
        }
        if (!function_exists('openssl_encrypt')) {
            echo json_encode(['ok' => false, 'error' => 'OpenSSL is unavailable on this host.']);
            return;
        }
        [$text, $opts] = self::collectInput($cfg);
        if (trim($text) === '') {
            echo json_encode(['ok' => false, 'error' => 'No domains provided.']);
            return;
        }
        $extra = array_filter(array_map('trim', explode(',', $_POST['niche'] ?? '')));
        if ($extra) {
            $cfg['NICHE_KEYWORDS'] = array_merge($cfg['NICHE_KEYWORDS'], $extra);
        }

        Debug::silentPage(); // JSON response — log errors, never echo them
        $cfg['REQUEST_TIMEOUT'] = 8;  // keep prepare quick (one target fetch)
        $records = Engine::parseRecords($text, $opts, $cfg);
        Debug::log('prepare: parsed ' . count($records) . ' unique domain(s), live=' . (!empty($opts['live']) ? 'yes' : 'no') . ', workers=' . (int)$opts['workers']);
        $niche   = Engine::buildProfile($opts['target_url'], $cfg, !empty($opts['live']));
        $pbn     = Engine::detectPbnClusters($records, $cfg);
        Debug::log('prepare: profile=' . count($niche) . 'kw, pbn=' . count($pbn) . ' — storing job');

        $job = [
            'records' => $records, 'niche' => $niche, 'pbn' => $pbn,
            'opts' => $opts, 'total' => count($records),
        ];
        $ok = Security::cachePut($uid, 'job', json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => (bool)$ok, 'total' => count($records),
                          'error' => $ok ? null : 'Could not store the job (file permissions).']);
    }

    /**
     * ?sse=1 (GET) — Server-Sent Events stream. Emits `open` immediately (so the
     * client can detect host buffering), then one `item` event per domain with
     * its spam verdict, then `summary` and `done` (with the cached report URL).
     */
    private static function sseStream($cfg, $uid): void
    {
        Debug::silentPage(); // SSE stream — never inject error HTML into it
        // Defeat output buffering / compression so each event flushes live.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate, private');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');   // nginx / hcdn: do not buffer
            header('Content-Encoding: identity'); // discourage gzip on the stream
        }
        echo ':' . str_repeat(' ', 2048) . "\n\n"; // primer comment to flush proxies
        @flush();

        $send = static function ($event, $data) {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            @flush();
        };
        $send('hello', ['ts' => time()]);

        $raw = function_exists('openssl_decrypt') ? Security::cacheGet($uid, 'job') : null;
        $job = $raw ? json_decode($raw, true) : null;
        if (!$job || empty($job['records'])) {
            $send('fail', ['msg' => 'No job found — submit the form again.']);
            return;
        }

        $records = $job['records'];
        $niche = $job['niche'];
        $pbn = $job['pbn'];
        $opts = $job['opts'];
        $do_fetch = !empty($opts['live']);
        $cfg['MAX_WORKERS'] = max(1, min(64, (int)$opts['workers']));
        $cfg['VERIFY_SSL'] = !empty($opts['verify_ssl']);
        $cfg['REQUEST_TIMEOUT'] = 5;  // 5s per request so the server never locks
        $cfg['OVERALL_DEADLINE'] = 25;
        Debug::log('sse: streaming ' . count($records) . ' domain(s), live=' . ($do_fetch ? 'yes' : 'no'));

        $counts = ['spam' => 0, 'suspicious' => 0, 'clean' => 0];
        $byUrl = [];
        foreach ($records as $i => $rec) {
            if (($rec['source_url'] ?? '') !== '') {
                $byUrl[$rec['source_url']][] = $i;
            }
        }
        $processed = [];

        if ($do_fetch && function_exists('curl_multi_init')) {
            Engine::fetchMany(array_keys($byUrl), $cfg, function ($url, $status, $final, $body, $ms)
                use (&$records, &$byUrl, &$processed, &$counts, $niche, $pbn, $cfg, $do_fetch, $send) {
                foreach (($byUrl[$url] ?? []) as $idx) {
                    Engine::extractSignals($records[$idx], $status, $final, $body, $cfg);
                    Engine::processOne($records[$idx], $niche, $pbn, $cfg, $do_fetch);
                    $processed[$idx] = true;
                    $v = Engine::verdict($records[$idx]);
                    $counts[$v['tier']]++;
                    $send('item', $v);
                }
            });
        }
        // Offline / not-fetched-in-time domains — still audited and emitted.
        foreach ($records as $idx => $rec) {
            if (!empty($processed[$idx])) {
                continue;
            }
            if ($do_fetch && ($rec['source_url'] ?? '') !== '') {
                $records[$idx]['fetch_skipped'] = true;
            }
            Engine::processOne($records[$idx], $niche, $pbn, $cfg, $do_fetch);
            $v = Engine::verdict($records[$idx]);
            $counts[$v['tier']]++;
            $send('item', $v);
        }

        // Build + cache the full ranked report so "Open full report" works.
        $r = Engine::assembleReport($records, $niche, $opts);
        if (function_exists('openssl_encrypt')) {
            Security::cachePut($uid, 'report', View::report($r, $opts));
        }

        $send('summary', ['total' => count($records)] + $counts);
        $send('done', ['ok' => true, 'report' => '?report=1']);
    }

    /**
     * ?batch=1 (POST {offset,size}) — polling fallback for hosts that buffer SSE
     * (e.g. Hostinger hcdn). Checks one slice and returns its verdicts as JSON;
     * on the final slice it assembles + caches the full report.
     */
    private static function batchCheck($cfg, $uid): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $raw = function_exists('openssl_decrypt') ? Security::cacheGet($uid, 'job') : null;
        $job = $raw ? json_decode($raw, true) : null;
        if (!$job || !isset($job['records'])) {
            echo json_encode(['ok' => false, 'error' => 'no job']);
            return;
        }
        $records = $job['records'];
        $niche = $job['niche'];
        $pbn = $job['pbn'];
        $opts = $job['opts'];
        $total = count($records);
        Debug::silentPage();
        $bt = microtime(true);
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $size = min(40, max(1, (int)($_POST['size'] ?? 20)));
        $do_fetch = !empty($opts['live']);
        $cfg['MAX_WORKERS'] = max(1, min(64, (int)$opts['workers']));
        $cfg['VERIFY_SSL'] = !empty($opts['verify_ssl']);
        $cfg['REQUEST_TIMEOUT'] = 5;
        $cfg['OVERALL_DEADLINE'] = 25;  // a single batch can never run long

        $slice = array_slice($records, $offset, $size, true);
        $fetched = [];
        if ($do_fetch && function_exists('curl_multi_init')) {
            $urls = [];
            foreach ($slice as $rec) {
                if (($rec['source_url'] ?? '') !== '') {
                    $urls[] = $rec['source_url'];
                }
            }
            if ($urls) {
                $fetched = Engine::fetchMany($urls, $cfg);
            }
        }

        $out = [];
        $slim = [];
        foreach ($slice as $idx => $rec) {
            if ($do_fetch && isset($fetched[$rec['source_url']])) {
                $f = $fetched[$rec['source_url']];
                Engine::extractSignals($rec, $f['status'], $f['final'], $f['body'], $cfg);
            } elseif ($do_fetch && ($rec['source_url'] ?? '') !== '') {
                $rec['fetch_skipped'] = true;
            }
            Engine::processOne($rec, $niche, $pbn, $cfg, $do_fetch);
            $out[] = Engine::verdict($rec);
            $slim[$idx] = Engine::slimRecord($rec);
        }
        Security::cachePut($uid, 'prog_' . $offset, json_encode($slim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Debug::log('batch off=' . $offset . ' size=' . $size . ' checked=' . count($out) . ' fetched=' . count($fetched) . ' in ' . round((microtime(true) - $bt) * 1000) . 'ms');

        $next = $offset + count($slice);
        $done = $next >= $total;
        $reportUrl = null;
        if ($done) {
            // Re-read every persisted slice (client uses a constant size, so the
            // offsets are 0, size, 2*size, …) and assemble the full report.
            $all = [];
            for ($o = 0; $o < $total; $o += $size) {
                $praw = Security::cacheGet($uid, 'prog_' . $o);
                $part = $praw ? json_decode($praw, true) : null;
                if (is_array($part)) {
                    foreach ($part as $k => $v) {
                        $all[(int)$k] = $v;
                    }
                }
            }
            ksort($all);
            $r = Engine::assembleReport(array_values($all), $niche, $opts);
            if (function_exists('openssl_encrypt')) {
                Security::cachePut($uid, 'report', View::report($r, $opts));
            }
            $reportUrl = '?report=1';
        }

        echo json_encode([
            'ok' => true, 'results' => $out, 'next' => $next,
            'total' => $total, 'done' => $done, 'report' => $reportUrl,
        ]);
    }

    /**
     * Handle the Notif tab: GET view + the Submit/Cancel POST actions. Password-
     * gates the mutating actions exactly like the Scorer gates its POST. The
     * Telegram token + chat id are kept across edits and never printed back.
     */
    private static function handleNotif($cfg): void
    {
        $action = $_POST['action'] ?? '';

        // Gate the mutating actions behind the same password as the Scorer.
        if ($action !== '' && ACCESS_PASSWORD !== '' && (($_POST['pw'] ?? '') !== ACCESS_PASSWORD)) {
            $state = Monitor::loadState();
            echo View::notifPage($cfg, [
                'mode'  => ($state && !empty($state['active'])) ? 'active' : 'editable',
                'state' => $state,
                'error' => 'Wrong password.',
            ]);
            return;
        }

        if ($action === 'notif_cancel') {
            // Pause monitoring but KEEP the encrypted Telegram token + chat id so
            // they are not lost on edit (the two fields can be left blank later).
            $old = Monitor::loadState();
            if ($old) {
                $old['active'] = false;
                Monitor::saveState($old);
            }
            $has_creds = !empty($old['bot_token']) && !empty($old['chat_id']);
            echo View::notifPage($cfg, [
                'mode'      => 'editable',
                'domains'   => implode("\n", $old['domains'] ?? []),
                'has_creds' => $has_creds,
                'notice'    => 'Monitoring paused. Edit the list and submit again — your saved Telegram settings are kept.',
            ]);
            return;
        }

        if ($action === 'notif_submit') {
            $existing = Monitor::loadState();
            $raw    = (string)($_POST['notif_domains'] ?? '');
            $token  = trim((string)($_POST['notif_token'] ?? ''));
            $chat   = trim((string)($_POST['notif_chat'] ?? ''));
            $domains = Monitor::cleanDomains($raw, $cfg);

            // Blank Telegram fields mean "keep what is already saved".
            $saved_token = (string)($existing['bot_token'] ?? '');
            $saved_chat  = (string)($existing['chat_id'] ?? '');
            if ($token === '') {
                $token = $saved_token;
            }
            if ($chat === '') {
                $chat = $saved_chat;
            }
            $has_creds = ($saved_token !== '' && $saved_chat !== '');

            $errs = [];
            if (!$domains) {
                $errs[] = 'Add at least one valid domain.';
            }
            if (!preg_match('~^\d+:[A-Za-z0-9_\-]+$~', $token)) {
                $errs[] = 'That Telegram bot token does not look valid.';
            }
            if (!preg_match('~^-?\d+$~', $chat)) {
                $errs[] = 'Telegram chat ID must be a number (e.g. 123456789).';
            }
            if (!function_exists('openssl_encrypt')) {
                $errs[] = 'OpenSSL is unavailable, cannot store data securely.';
            }

            if ($errs) {
                echo View::notifPage($cfg, [
                    'mode'      => 'editable',
                    'domains'   => $raw,
                    'has_creds' => $has_creds,
                    'error'     => implode(' ', $errs),
                ]);
                return;
            }

            $now = time();
            $state = [
                'active'     => true,
                'domains'    => $domains,
                'bot_token'  => $token,
                'chat_id'    => $chat,
                'created_at' => $now,
                'expires_at' => $now + NOTIF_DURATION,
                'last_run'   => 0,
                'flagged'    => $existing['flagged'] ?? [],
            ];

            if (!Monitor::saveState($state)) {
                echo View::notifPage($cfg, [
                    'mode'      => 'editable',
                    'domains'   => $raw,
                    'has_creds' => $has_creds,
                    'error'     => 'Could not write the encrypted data file. Check folder permissions.',
                ]);
                return;
            }

            $sent = Monitor::telegramSend(
                $token,
                $chat,
                Monitor::startedMessage(count($domains), $state['expires_at'])
            );
            $notice = $sent
                ? 'Saved. A detailed "Backlink Checker started" message was sent to your Telegram. Weekly checks are now active for 1 year.'
                : 'Saved and monitoring is active, but the Telegram message could not be delivered — double-check the token/chat ID and that you have messaged your bot once.';

            echo View::notifPage($cfg, ['mode' => 'active', 'state' => $state, 'notice' => $notice]);
            return;
        }

        // Plain GET view of the tab.
        $state = Monitor::loadState();
        if ($state && !empty($state['active'])) {
            echo View::notifPage($cfg, ['mode' => 'active', 'state' => $state]);
        } elseif ($state) {
            echo View::notifPage($cfg, [
                'mode'      => 'editable',
                'domains'   => implode("\n", $state['domains'] ?? []),
                'has_creds' => !empty($state['bot_token']) && !empty($state['chat_id']),
            ]);
        } else {
            echo View::notifPage($cfg, ['mode' => 'editable']);
        }
    }
}
