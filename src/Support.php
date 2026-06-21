<?php
/**
 * Support — stateless helpers shared across the app: HTML escaping, URL
 * normalisation, registrable-domain extraction, and the encrypted data dir.
 *
 * No state, no side effects beyond ensureDataDir(); every method is static.
 */
class Support
{
    /** Escape a value for safe HTML output. */
    public static function h($s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** A stable integer id for a cURL handle (object on PHP 8, resource before). */
    public static function handleId($ch): int
    {
        return is_object($ch) ? spl_object_id($ch) : (int)$ch;
    }

    /** Lowercased host of a URL, or '' if it cannot be parsed. */
    public static function hostOf($url): string
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    /** Split a line that has several URLs glued together (no newline between). */
    public static function splitConcatenated($raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/(?=https?:\/\/)/i', $raw);
        $parts = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
        return $parts ?: [$raw];
    }

    /**
     * Return [registrableDomain, tld] for a host, honouring two-level public
     * suffixes (e.g. co.uk) supplied in $twoLevel.
     */
    public static function registrableDomain($host, $twoLevel): array
    {
        $host = strtolower(trim((string)$host, " \t\n\r\0\x0B."));
        if ($host === '') {
            return ['', ''];
        }
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n >= 3) {
            $last2 = implode('.', array_slice($labels, -2));
            if (isset($twoLevel[$last2])) {
                return [implode('.', array_slice($labels, -3)), $last2];
            }
        }
        if ($n >= 2) {
            return [implode('.', array_slice($labels, -2)), $labels[$n - 1]];
        }
        return [$host, ''];
    }

    /**
     * Normalise a raw entry into a clean http(s) URL, or null if invalid.
     *
     * Default ($keepPathQuery = false): scheme://host/path — query and fragment
     * are dropped. This is what domain-level analysis uses.
     *
     * Per-URL mode ($keepPathQuery = true): keeps the path AND query string but
     * drops the #fragment, lowercases the host, and strips a trailing slash on
     * non-root paths — so only trivial noise is normalised and "/a?x=1" stays
     * distinct from "/b". Used when the user opts to analyse every URL.
     */
    public static function normalizeUrl($raw, $keepPathQuery = false)
    {
        $s = trim((string)$raw, " \t\n\r\0\x0B\"'");
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^https?:\/\//i', $s)) {
            $s = 'https://' . $s;
        }
        $p = @parse_url($s);
        if (!$p || empty($p['host'])) {
            return null;
        }
        $host = strtolower($p['host']);
        if (strpos($host, '.') === false) {
            return null;
        }
        if (!preg_match('/^[a-z0-9.\-:]+$/', $host)) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? 'https');
        $path = $p['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (!$keepPathQuery) {
            return "$scheme://$host$path";
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');   // "/page/" and "/page" are the same URL
        }
        $query = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
        return "$scheme://$host$path$query";
    }

    /** Absolute path to the web-blocked data directory (under the project root). */
    public static function dataDir(): string
    {
        return APP_ROOT . '/notif_data';
    }

    /**
     * Create the data directory on first use and drop an .htaccess that blocks
     * all direct web access (so nobody can download the encrypted files).
     * Works on Apache 2.2 and 2.4.
     */
    public static function ensureDataDir(): void
    {
        $dir = self::dataDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !file_exists($ht)) {
            @file_put_contents(
                $ht,
                "# Deny all web access to the encrypted data files.\n" .
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }
    }
}
