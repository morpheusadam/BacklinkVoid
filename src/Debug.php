<?php
/**
 * Debug — opt-in diagnostics, WordPress-style. When DEBUG (config.php) is true:
 *   - turns on display_errors + logs warnings/notices,
 *   - a shutdown handler captures FATAL errors / time-limit / memory kills — this
 *     is what reveals "the host killed the request after ~100 domains",
 *   - appends a timestamped trace to notif_data/debug.log,
 *   - shows fatals inline on HTML pages (so you never get a blank screen),
 *   - the log is viewable at  ?debug=1  (login required).
 *
 * JSON/SSE endpoints call silentPage() so errors are LOGGED but never injected
 * into the response body. Turn DEBUG off in production.
 */
class Debug
{
    private static $on = false;
    private static $t0 = 0.0;
    private static $pageDisplay = true;
    private static $reserve = '';
    private static $booted = false;

    /** Wire up handlers as early as possible (called from index.php). */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$t0 = microtime(true);
        self::$on = defined('DEBUG') && DEBUG;
        if (!self::$on) {
            return;
        }
        // Reserve memory so we can still log if the script hits memory_limit.
        self::$reserve = str_repeat('x', 262144);
        @ini_set('display_errors', '1');
        @ini_set('log_errors', '1');
        @error_reporting(E_ALL);
        set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
        self::log('===== ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-') . ' =====');
        self::log('php ' . PHP_VERSION
            . ' · max_execution_time=' . (ini_get('max_execution_time') !== '' ? ini_get('max_execution_time') : '?') . 's'
            . ' · memory_limit=' . ini_get('memory_limit')
            . ' · post_max_size=' . ini_get('post_max_size'));
    }

    public static function on(): bool
    {
        return self::$on;
    }

    /** JSON/SSE endpoints: keep the response body clean (log, don't echo). */
    public static function silentPage(): void
    {
        self::$pageDisplay = false;
        @ini_set('display_errors', '0');
    }

    /** Append one timestamped line (elapsed + memory) to the log. No-op when off. */
    public static function log($msg, array $ctx = []): void
    {
        if (!self::$on) {
            return;
        }
        $line = sprintf(
            '[%s] +%6.2fs %5.1fMB  %s%s',
            date('H:i:s'),
            microtime(true) - self::$t0,
            memory_get_usage(true) / 1048576,
            is_string($msg) ? $msg : json_encode($msg),
            $ctx ? '  ' . json_encode($ctx) : ''
        );
        self::write($line);
    }

    private static function write(string $line): void
    {
        $path = self::logPath();
        if ($path === null) {
            return;
        }
        // Cap the file at ~1 MB so it can't grow unbounded.
        if (is_file($path) && @filesize($path) > 1048576) {
            $keep = @file_get_contents($path);
            if ($keep !== false) {
                @file_put_contents($path, substr($keep, -393216));
            }
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function logPath(): ?string
    {
        if (!class_exists('Support')) {
            return null;
        }
        Support::ensureDataDir();
        $dir = Support::dataDir();
        return is_dir($dir) ? $dir . '/debug.log' : null;
    }

    public static function readLog(): string
    {
        $p = self::logPath();
        return ($p && is_file($p)) ? (string)@file_get_contents($p) : '';
    }

    public static function clearLog(): void
    {
        $p = self::logPath();
        if ($p && is_file($p)) {
            @unlink($p);
        }
    }

    /** Capture warnings/notices into the log; let PHP also display them. */
    public static function onError($no, $str, $file, $line): bool
    {
        self::log('PHP ' . self::levelName($no) . ': ' . $str . '  @ ' . basename((string)$file) . ':' . $line);
        return false;
    }

    /** Capture fatal errors / time-limit / memory kills — the key diagnostic. */
    public static function onShutdown(): void
    {
        self::$reserve = ''; // free reserved memory so logging works after OOM
        $e = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR];
        if ($e && in_array($e['type'], $fatalTypes, true)) {
            $msg = 'FATAL: ' . $e['message'] . '  @ ' . basename((string)$e['file']) . ':' . $e['line'];
            self::log($msg);
            self::log('peak memory ' . round(memory_get_peak_usage(true) / 1048576, 1) . 'MB');
            if (self::$pageDisplay) {
                echo "\n<pre style=\"background:#fff0f0;border:2px solid #d63638;color:#8a1f11;"
                    . "padding:14px;margin:14px;border-radius:6px;white-space:pre-wrap;"
                    . "font:13px ui-monospace,Consolas,monospace;position:relative;z-index:99999\">"
                    . '☠ ' . htmlspecialchars($msg)
                    . "\n\nThis is almost certainly the host killing the request (time or memory limit) — "
                    . "the reason a big list stops at ~100. Full trace at  ?debug=1</pre>";
            }
        } elseif (self::$on) {
            self::log('--- end · peak ' . round(memory_get_peak_usage(true) / 1048576, 1) . 'MB ---');
        }
    }

    private static function levelName($no): string
    {
        $map = [
            E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED',
            E_USER_WARNING => 'WARNING', E_USER_NOTICE => 'NOTICE', E_STRICT => 'STRICT',
        ];
        return $map[$no] ?? ('E#' . $no);
    }

    /** A small floating badge linking to the log (shown on HTML pages when on). */
    public static function badge(): string
    {
        if (!self::$on) {
            return '';
        }
        return '<a href="?debug=1" title="View debug log" style="position:fixed;right:12px;bottom:12px;'
            . 'z-index:9998;background:#1f2a3a;color:#a7f3d0;border:1px solid #2c3a4e;border-radius:18px;'
            . 'padding:6px 12px;font:600 12px ui-monospace,Consolas,monospace;text-decoration:none;'
            . 'box-shadow:0 4px 14px rgba(0,0,0,.25)">🐞 debug log</a>';
    }

    /** The ?debug=1 viewer page (the caller gates it behind login). */
    public static function renderViewer(): string
    {
        $on = self::$on ? 'ON' : 'OFF — set <code>define(\'DEBUG\', true)</code> in config.php, then reproduce';
        $log = self::readLog();
        $tail = $log === '' ? '(log is empty — turn DEBUG on, run an analysis, then refresh)' : Support::h($log);
        $path = self::logPath() ?? '(no data dir)';

        $before = ini_get('max_execution_time');
        @set_time_limit(0);
        $after = ini_get('max_execution_time');
        $facts = 'PHP ' . PHP_VERSION
            . ' · max_execution_time=' . ($before !== '' ? $before : '?') . 's (after set_time_limit(0): ' . ($after !== '' ? $after : '?') . 's)'
            . ' · memory_limit=' . ini_get('memory_limit')
            . ' · post_max_size=' . ini_get('post_max_size')
            . ' · openssl=' . (function_exists('openssl_encrypt') ? 'yes' : 'NO')
            . ' · curl=' . (function_exists('curl_multi_init') ? 'yes' : 'NO');
        $facts = Support::h($facts);
        $path = Support::h($path);

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Debug log</title>
<style>
  body{font:13px ui-monospace,SFMono-Regular,Consolas,monospace;margin:0;background:#0b0f17;color:#c7d2da}
  .bar{background:#121826;border-bottom:1px solid #1d2734;padding:11px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .bar b{color:#7dd3fc}.bar .st{color:#9fb3c8}.bar .x{margin-left:auto}
  .facts{padding:9px 16px;color:#9fb3c8;border-bottom:1px solid #1d2734;background:#0e1420;line-height:1.6}
  pre{margin:0;padding:14px 16px;white-space:pre-wrap;word-break:break-word;line-height:1.5}
  a.btn{background:#1f2a3a;color:#cbd5e1;border:1px solid #2c3a4e;border-radius:5px;padding:5px 12px;text-decoration:none}
  a.btn:hover{background:#27374b}code{background:#1f2a3a;padding:1px 5px;border-radius:3px}
</style></head><body>
<div class="bar"><b>🐞 Debug log</b> <span class="st">DEBUG: {$on}</span>
  <a class="btn" href="?debug=1">↻ refresh</a>
  <a class="btn" href="?debug=download">⬇ download</a>
  <a class="btn" href="?debug=clear" onclick="return confirm('Clear the log?')">🗑 clear</a>
  <a class="btn x" href="?">← back to tool</a></div>
<div class="facts">{$facts}<br>log file: {$path}  ·  also see <a class="btn" href="?health=1" style="padding:2px 8px">?health=1</a></div>
<pre>{$tail}</pre>
</body></html>
HTML;
    }
}
