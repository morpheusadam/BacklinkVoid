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
            echo Monitor::runCheck(isset($_GET['force']) && $_GET['force'] === '1', $cfg) . "\n";
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

        // (5) Streaming analyze (POST ?stream=1) — used by the terminal loader.
        //     Emits live bash-style progress, caches the report, then signals
        //     @@DONE@@ so the browser opens the (already cached) report.
        if (($_GET['stream'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            self::streamAnalyze($cfg, $uid);
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

            $r = Engine::runPipeline($text, $opts, $cfg);
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
     * Streaming analyze endpoint for the terminal loader. Disables output
     * buffering so progress reaches the browser live, runs the pipeline with a
     * flushing progress callback, caches the finished report, then emits the
     * @@DONE@@ sentinel with the report URL (the browser opens the cached report).
     */
    private static function streamAnalyze($cfg, $uid): void
    {
        // Defeat buffering/compression so each line is flushed immediately.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no'); // ask nginx not to buffer the stream
        }
        // ~2 KB of padding nudges FastCGI/proxies to start flushing right away.
        echo str_repeat(' ', 2048) . "\n";
        @flush();

        $emit = static function ($line) {
            echo $line . "\n";
            @flush();
        };

        // Same password gate as the plain POST handler.
        if (ACCESS_PASSWORD !== '' && (($_POST['pw'] ?? '') !== ACCESS_PASSWORD)) {
            $emit('[error] wrong password');
            $emit('@@FAIL@@');
            return;
        }

        // Gather input (uploaded file overrides the textarea).
        $text = (string)($_POST['domains'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $uploaded = @file_get_contents($_FILES['file']['tmp_name']);
            if ($uploaded !== false && trim($uploaded) !== '') {
                $text = $uploaded;
            }
        }
        if (trim($text) === '') {
            $emit('[error] no domains provided');
            $emit('@@FAIL@@');
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

        $emit('$ backlink-scan --target ' . $opts['target_url']
            . ($opts['live'] ? ' --live' : ' --no-fetch') . ' --workers ' . $opts['workers']);

        $r = Engine::runPipeline($text, $opts, $cfg, $emit);
        $html = View::report($r, $opts);

        if (function_exists('openssl_encrypt') && Security::cachePut($uid, 'report', $html)) {
            $emit('[ok]    report cached — opening…');
            $emit('@@DONE@@?report=1');
        } else {
            // No encrypted cache available (rare): hand the report back inline so
            // the client can still render it via document.write.
            $emit('@@HTML@@');
            echo $html;
        }
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
