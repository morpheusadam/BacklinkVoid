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

        // (1c) Static front-end asset: the report-shell loader JS. Served as an
        //      EXTERNAL file (not inline) so a host/account-level Content-Security-
        //      Policy that blocks inline <script> cannot stop the batch loop. It
        //      holds no secrets (per-page values arrive via data-* attributes), so
        //      it is safe to serve before the login gate.
        if (($_GET['asset'] ?? '') === 'shell.js' || ($_GET['asset'] ?? '') === 'report.js') {
            // CRITICAL: a static JS file must contain ONLY JS. With DEBUG on,
            // display_errors is enabled, so a PHP notice/warning/deprecation — or a
            // "headers already sent" warning from stray whitespace in a hand-edited
            // config — would be injected into the body and break the script (the
            // browser then can't parse it, the console is empty, and the loader
            // never runs). silentPage() turns display_errors OFF for this response
            // so the JS body is always clean. Errors are still written to the log.
            Debug::silentPage();
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) { @ob_end_clean(); }  // drop any stray pre-output
            }
            if (!headers_sent()) {
                header('Content-Type: application/javascript; charset=utf-8');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store');
            }
            echo ($_GET['asset'] === 'report.js') ? View::reportJs() : View::shellJs();
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

        // (4) Cached report view — refresh-safe and STRICTLY TERMINAL. Served
        //     from this browser's encrypted cache; if the cache is missing but
        //     the job finished, it self-heals (rebuilds once from progress). It
        //     never redirects into a polling/building state, so there is no loop.
        if (isset($_GET['report'])) {
            if ($_GET['report'] === 'data') {
                self::reportData($uid);   // paginated JSON slice for report.js
                return;
            }
            self::serveReport($uid);
            return;
        }

        // (5) Progressive analysis. NO request below ever processes the whole
        //     list: submit builds the job and redirects to the report shell; the
        //     shell then drives ?job=batch, one small bounded slice per request.
        if (isset($_GET['building'])) {
            self::buildingShell($cfg, $uid);
            return;
        }
        if (($_GET['job'] ?? '') === 'batch') {
            self::jobBatch($cfg, $uid);
            return;
        }
        if (($_GET['job'] ?? '') === 'step') {
            self::jobStep($cfg, $uid);  // no-JS fallback: one batch per page refresh
            return;
        }

        // (6) Scorer analyze (form submit). Build the job, then PRG-redirect to
        //     the report shell — this request does parsing + ONE target fetch
        //     only, never the per-domain scan, so it is always short.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::startJob($cfg, $uid);
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
            'per_url' => !empty($_POST['per_url']),  // off = dedupe to domain (default)
        ];
        return [$text, $opts];
    }

    /**
     * Scorer analyze (form submit). Parse + dedupe the list, build the topic
     * profile (ONE target fetch) and PBN map, store the encrypted job + a zeroed
     * progress cursor, then PRG-redirect to the report shell. This request never
     * runs the per-domain scan, so it is always short and can't lock a worker.
     */
    private static function startJob($cfg, $uid): void
    {
        if (ACCESS_PASSWORD !== '' && (($_POST['pw'] ?? '') !== ACCESS_PASSWORD)) {
            echo View::form($cfg, 'Wrong password.', $_POST);
            return;
        }
        [$text, $opts] = self::collectInput($cfg);
        if (trim($text) === '') {
            echo View::form($cfg, 'Please paste some domains or upload a file.', $_POST);
            return;
        }
        // The progressive flow needs the encrypted store. OpenSSL is standard on
        // the target host; only if it is genuinely missing do we degrade to the
        // single-request pipeline (documented limitation, no encrypted cache).
        if (!function_exists('openssl_encrypt')) {
            Debug::log('startJob: OpenSSL missing — degrading to single-request pipeline');
            $extra = array_filter(array_map('trim', explode(',', $_POST['niche'] ?? '')));
            if ($extra) {
                $cfg['NICHE_KEYWORDS'] = array_merge($cfg['NICHE_KEYWORDS'], $extra);
            }
            echo View::report(Engine::runPipeline($text, $opts, $cfg), $opts);
            return;
        }

        $extra = array_filter(array_map('trim', explode(',', $_POST['niche'] ?? '')));
        if ($extra) {
            $cfg['NICHE_KEYWORDS'] = array_merge($cfg['NICHE_KEYWORDS'], $extra);
        }

        Debug::log('startJob: building work order (no per-domain scan in this request)');
        $cfg['REQUEST_TIMEOUT'] = 8;    // only the single target-profile fetch happens here
        $cfg['OVERALL_DEADLINE'] = 15;

        // Parse + (by default) collapse to unique registrable domains. Capture
        // BOTH counts so the UI can explain the collapse — this is where the
        // pasted 462 URLs become 108 domains (NOT a cap; the intended dedupe).
        $stats = Engine::parseRecordsWithStats($text, $opts, $cfg);
        $records = $stats['records'];
        if (!$records) {
            echo View::form($cfg, 'No valid domains found in the input.', $_POST);
            return;
        }
        // Stash the transparency counts in opts so they flow to BOTH the building
        // shell and the final report without touching jobBatch/finalizeJob.
        $opts['per_url']   = !empty($opts['per_url']);
        $opts['submitted'] = (int)$stats['submitted'];
        $opts['unique']    = (int)$stats['unique'];
        $opts['merged']    = (int)$stats['merged'];

        $niche = Engine::buildProfile($opts['target_url'], $cfg, !empty($opts['live']));
        $pbn   = Engine::detectPbnClusters($records, $cfg);
        $id    = substr(hash('sha256', $uid . '|' . microtime(true) . '|' . count($records)), 0, 16);

        $job = [
            'id' => $id, 'records' => $records, 'niche' => $niche, 'pbn' => $pbn,
            'opts' => $opts, 'total' => count($records),
        ];
        $okJob  = Security::jobSave($uid, $job);
        $okProg = Security::progSave($uid, ['offset' => 0, 'done' => []]);
        Debug::log('startJob: submitted=' . $stats['submitted'] . ' URLs → ' . count($records)
            . ($opts['per_url'] ? ' unique URLs' : ' unique domains') . ' (' . $stats['merged'] . ' merged)'
            . ' · id=' . $id . ' · profile=' . count($niche) . 'kw · pbn=' . count($pbn)
            . ' · stored=' . ($okJob && $okProg ? 'yes' : 'NO'));

        if (!$okJob || !$okProg) {
            echo View::form($cfg, 'Could not store the job — check folder permissions on notif_data/.', $_POST);
            return;
        }
        if (!headers_sent()) {
            header('Location: ?building=1');   // PRG → refresh-safe, resumable shell
        }
    }

    /**
     * ?building=1 — the report shell: a full page that loads instantly and then
     * drives ?job=batch over AJAX. Reads the stored progress so a reload RESUMES
     * (it never restarts). Redirects to the form if there is no job.
     */
    private static function buildingShell($cfg, $uid): void
    {
        $job = function_exists('openssl_decrypt') ? Security::jobLoad($uid) : null;
        if (!$job || empty($job['records'])) {
            if (!headers_sent()) {
                header('Location: ?');
            }
            return;
        }
        $prog = Security::progLoad($uid);
        $o = $job['opts'] ?? [];
        echo View::reportShell([
            'id'        => (string)($job['id'] ?? ''),
            'total'     => (int)$job['total'],
            'processed' => count($prog['done'] ?? []),
            'live'      => !empty($o['live']),
            'per_url'   => !empty($o['per_url']),
            'submitted' => (int)($o['submitted'] ?? 0),
            'unique'    => (int)($o['unique'] ?? (int)$job['total']),
            'merged'    => (int)($o['merged'] ?? 0),
        ]);
    }

    /**
     * ?job=batch (POST) — process the NEXT bounded slice and return JSON:
     *   { ok, rows:[verdict…], processed, offset, total, done, report }
     * The offset is SERVER-authoritative (from the stored cursor), so the call is
     * idempotent and resumable: a retried/duplicated request re-checks the same
     * slice without corrupting the result set. On the final slice it assembles +
     * caches the full report and returns its URL.
     */
    private static function jobBatch($cfg, $uid): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        Debug::silentPage();  // JSON response — log errors, never echo them
        // Log on ENTRY (before any work) so the debug log proves the client's
        // batch loop is actually reaching the server — even if work below fails.
        Debug::log('jobBatch: ENTER size=' . (int)($_POST['size'] ?? 0) . ' id=' . substr((string)($_POST['id'] ?? ''), 0, 16));
        if (!function_exists('openssl_decrypt')) {
            echo json_encode(['ok' => false, 'error' => 'OpenSSL is unavailable on this host.']);
            return;
        }
        $job = Security::jobLoad($uid);
        if (!$job || !isset($job['records'])) {
            echo json_encode(['ok' => false, 'error' => 'no job — submit the form again']);
            return;
        }

        $total  = (int)$job['total'];
        $prog   = Security::progLoad($uid);
        $offset = max(0, (int)$prog['offset']);
        $size   = min(40, max(1, (int)($_POST['size'] ?? 20)));
        $bt     = microtime(true);

        // Already complete (e.g. a duplicate final call) → just (re)finalize.
        if ($offset >= $total) {
            self::finalizeJob($uid, $job, $prog);
            echo json_encode(['ok' => true, 'rows' => [], 'processed' => $total,
                'offset' => $total, 'total' => $total, 'done' => true, 'report' => '?report=1']);
            return;
        }

        $res  = Engine::processSlice($job['records'], $offset, $size,
            $job['niche'], $job['pbn'], $job['opts'], $cfg);
        $done = $prog['done'];
        foreach ($res['slim'] as $idx => $rec) {
            $done[(string)$idx] = $rec;   // keyed by original index → order-stable, dup-safe
        }
        $newOffset = $offset + count($res['rows']);
        if ($newOffset <= $offset) {
            $newOffset = min($total, $offset + $size);  // never stall on an empty slice
        }
        $prog['offset'] = $newOffset;
        $prog['done']   = $done;
        Security::progSave($uid, $prog);

        $isDone = $newOffset >= $total;
        if ($isDone) {
            self::finalizeJob($uid, $job, $prog);
        }
        $reportUrl = $isDone ? '?report=1' : null;
        Debug::log('jobBatch off=' . $offset . ' size=' . $size . ' rows=' . count($res['rows'])
            . ' → ' . min($newOffset, $total) . '/' . $total
            . ' in ' . round((microtime(true) - $bt) * 1000) . 'ms');

        echo json_encode([
            'ok'        => true,
            'rows'      => $res['rows'],
            'processed' => min($newOffset, $total),
            'offset'    => $newOffset,
            'total'     => $total,
            'done'      => $isDone,
            'report'    => $reportUrl,
        ]);
    }

    /**
     * ?job=step — no-JavaScript fallback. Processes ONE slice per page load and
     * meta-refreshes to itself until complete (then to ?report=1). Guarantees
     * that even without JS no single request runs the whole list.
     */
    private static function jobStep($cfg, $uid): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $job = function_exists('openssl_decrypt') ? Security::jobLoad($uid) : null;
        if (!$job || !isset($job['records'])) {
            echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=?">';
            return;
        }
        $total  = (int)$job['total'];
        $prog   = Security::progLoad($uid);
        $offset = max(0, (int)$prog['offset']);

        if ($offset < $total) {
            $res  = Engine::processSlice($job['records'], $offset, 20,
                $job['niche'], $job['pbn'], $job['opts'], $cfg);
            $done = $prog['done'];
            foreach ($res['slim'] as $idx => $rec) {
                $done[(string)$idx] = $rec;
            }
            $prog['offset'] = $offset + max(count($res['rows']), 1);
            $prog['done']   = $done;
            Security::progSave($uid, $prog);
            $offset = min($total, (int)$prog['offset']);
        }

        if ($offset >= $total) {
            self::finalizeJob($uid, $job, $prog);
            echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=?report=1">'
                . 'Done. Opening the full report…';
            return;
        }
        $pct = (int)round($offset / max(1, $total) * 100);
        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta http-equiv="refresh" content="0;url=?job=step">'
            . '<title>Checking…</title><body style="font:14px system-ui,Segoe UI,Arial;margin:40px;color:#1d2327">'
            . '<h1 style="font-size:18px;font-weight:600">Checking your links…</h1>'
            . '<p>Checked <strong>' . $offset . '</strong> of <strong>' . $total . '</strong> (' . $pct . '%).</p>'
            . '<p class="muted" style="color:#50575e">This page advances automatically. '
            . 'Enable JavaScript for the live view.</p></body>';
    }

    /**
     * ?report=data&offset=&limit=&sort= — paginated JSON slice of the prospect
     * rows for report.js. Source is the ALREADY-CACHED job progress (no re-
     * analysis): assemble (read-only) → compact rows → sort the FULL set
     * server-side → slice. limit=0 returns all rows (used for sort/export).
     * Keeps the first report HTML tiny: the 484 rows arrive here, not inline.
     */
    private static function reportData($uid): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        Debug::silentPage();   // JSON — log, never echo errors
        if (!function_exists('openssl_decrypt')) {
            echo json_encode(['ok' => false, 'error' => 'OpenSSL is unavailable on this host.']);
            return;
        }
        $job = Security::jobLoad($uid);
        if (!$job || !isset($job['records'])) {
            echo json_encode(['ok' => false, 'error' => 'no job — submit the form again']);
            return;
        }
        $bt = microtime(true);
        $prog = Security::progLoad($uid);
        $all = [];
        foreach (($prog['done'] ?? []) as $k => $v) {
            $all[(int)$k] = $v;
        }
        ksort($all);
        // Read-only re-assembly (same call finalizeJob uses) → prospects/avoid.
        $r = Engine::assembleReport(array_values($all), $job['niche'], $job['opts']);
        $rows = View::prospectRows($r);   // compact, default order (score desc), each with 'o'
        $total = count($rows);

        // Sort the FULL set server-side so pagination + sorting cover all rows.
        $sortp = (string)($_GET['sort'] ?? 's-desc');
        $parts = explode('-', $sortp);
        $field = $parts[0] ?? 's';
        $dir = (($parts[1] ?? 'desc') === 'asc') ? 1 : -1;
        if (!in_array($field, ['o', 'd', 's', 'rel', 'au', 'g', 'w'], true)) {
            $field = 's';
        }
        if (!($field === 'o' && $dir === 1)) {   // 'o-asc' is already the natural order
            usort($rows, function ($a, $b) use ($field, $dir) {
                if ($field === 'd' || $field === 'w') {
                    return strcmp((string)$a[$field], (string)$b[$field]) * $dir;
                }
                return (($a[$field] <=> $b[$field])) * $dir;
            });
        }

        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit  = (int)($_GET['limit'] ?? 50);
        $slice = $limit > 0 ? array_slice($rows, $offset, $limit) : array_slice($rows, $offset);

        Debug::log('reportData: off=' . $offset . ' limit=' . $limit . ' sort=' . $sortp
            . ' total=' . $total . ' returned=' . count($slice)
            . ' in ' . round((microtime(true) - $bt) * 1000) . 'ms');

        echo json_encode([
            'ok'      => true,
            'total'   => $total,
            'offset'  => $offset,
            'limit'   => $limit,
            'per_url' => !empty($job['opts']['per_url']),
            'rows'    => $slice,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * ?report=1 — serve the finished report. STRICTLY TERMINAL:
     *   1. cache HIT  → echo it (static; no polling, no rebuild).
     *   2. cache MISS but the job is COMPLETE → self-heal: rebuild + cache once
     *      from the persisted progress (assembly only, no fetching), then serve.
     *   3. otherwise → a terminal "not ready" page (NEVER a redirect into the
     *      building/polling state — that was the loop).
     */
    private static function serveReport($uid): void
    {
        $cached = function_exists('openssl_decrypt') ? Security::cacheGet($uid, 'report', 86400) : null;
        if (is_string($cached) && $cached !== '') {
            Debug::log('report: cache HIT (' . strlen($cached) . ' bytes) — terminal, no rebuild');
            echo $cached;
            return;
        }

        $job  = function_exists('openssl_decrypt') ? Security::jobLoad($uid) : null;
        $prog = $job ? Security::progLoad($uid) : ['offset' => 0, 'done' => []];
        $total   = (int)($job['total'] ?? 0);
        $complete = $job && $total > 0 && (int)$prog['offset'] >= $total && !empty($prog['done']);

        if ($complete) {
            Debug::log('report: cache MISS — rebuilding from progress (' . count($prog['done']) . ' records, HEAL)');
            $html = self::finalizeJob($uid, $job, $prog);   // renders + best-effort caches
            if ($html !== '') {
                echo $html;   // serve even if the cache write failed (still terminal)
                return;
            }
        }

        // No usable report. Terminal page — no spinner, no auto-redirect, no loop.
        $resumable = $job && $total > 0 && (int)$prog['offset'] < $total;
        Debug::log('report: cache MISS, complete=' . ($complete ? 'yes' : 'no')
            . ', resumable=' . ($resumable ? 'yes' : 'no') . ' — terminal "not ready" page');
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo View::reportMissing($resumable);
    }

    /**
     * Assemble + cache the full report from the accumulated slim records and
     * return the rendered HTML (so a caller can serve it even if the cache write
     * fails). Records are keyed by their original index, so ksort() restores
     * input order regardless of the order batches completed in. Assembly + render
     * only — no network — so it is always fast and bounded.
     */
    private static function finalizeJob($uid, $job, $prog): string
    {
        $all = [];
        foreach (($prog['done'] ?? []) as $k => $v) {
            $all[(int)$k] = $v;
        }
        ksort($all);
        $r = Engine::assembleReport(array_values($all), $job['niche'], $job['opts']);
        $html = View::report($r, $job['opts']);
        $stored = function_exists('openssl_encrypt') ? Security::cachePut($uid, 'report', $html) : false;
        Debug::log('finalizeJob: assembled ' . count($all) . ' records → ' . count($r['prospects'])
            . ' prospects, ' . count($r['avoid']) . ' avoid · report ' . strlen($html)
            . ' bytes · cached=' . ($stored ? 'yes' : 'NO'));
        return $html;
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
