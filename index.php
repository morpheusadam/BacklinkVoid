<?php
/**
 * Backlink Prospect Scorer — web edition (object-oriented)
 * ===========================================================================
 * Single entry point. Upload the whole folder to your host (e.g.
 * public_html/backlink/) so this index.php sits in the web root next to the
 * src/ classes and config.php. Open it in a browser.
 *
 * It ranks candidate domains by how good a backlink FROM each would be for YOUR
 * site, builds a Google Disavow file from spam/toxic domains, and can monitor
 * your existing backlinks weekly and alert you on Telegram (the "Backlink
 * Notif" tab).
 *
 * Requirements: PHP 7.4+ with the cURL and OpenSSL extensions (standard on
 * cPanel). mbstring is used if present but not required.
 *
 * Layout:
 *   index.php          — this bootstrap (root)
 *   config.php         — your editable settings/secrets (root)
 *   src/*.php          — the classes (Config, Support, Engine, Security,
 *                        Monitor, View, Router)
 *   notif_data/        — auto-created, git-ignored, encrypted runtime data
 *
 * Weekly cron (cPanel > Cron Jobs), every Monday 09:00 — replace host + token:
 *   0 9 * * 1 curl -fsS "https://YOURDOMAIN/backlink/index.php?cron=run&token=PUT-YOUR-CRON-TOKEN-HERE" >/dev/null 2>&1
 * The endpoint self-throttles to once / 7 days, so triggering it more often is
 * harmless. Set NOTIF_CRON_TOKEN (and the other secrets) in config.php.
 * ===========================================================================
 */

@set_time_limit(0);
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');
@ini_set('pcre.backtrack_limit', '10000000');
@ini_set('default_socket_timeout', '15');

// Polyfills so the tool also runs on hosts without the mbstring extension.
if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding($enc = null) { return 'UTF-8'; }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower((string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) {
        return $len === null ? substr((string)$s, $start) : substr((string)$s, $start, $len);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $o = 0) { return strpos((string)$h, (string)$n, $o); }
}
mb_internal_encoding('UTF-8');

// No browser caching — every response is dynamic and may contain private data.
// (Refresh-safe results are handled by the server-side encrypted cache.)
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Project root, used by the classes to locate the encrypted data directory.
define('APP_ROOT', __DIR__);

// Load settings. config.local.php (git-ignored) wins because it is loaded first
// and every define() in config.php is guarded with if (!defined()).
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
require __DIR__ . '/config.php';

// Minimal PSR-style autoloader: class Foo -> src/Foo.php
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Diagnostics first, so a fatal/time-limit/memory kill anywhere below is caught,
// logged to notif_data/debug.log, and shown on the page (when DEBUG is on).
Debug::init();

// Build the runtime config and dispatch the request.
$CFG = Config::defaults();
Router::dispatch($CFG);
