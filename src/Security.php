<?php
/**
 * Security — three related concerns kept together:
 *   1. At-rest encryption: AES-256-CBC with encrypt-then-HMAC authentication.
 *   2. The login gate: an HMAC-signed cookie, asked once per browser.
 *   3. The per-browser encrypted result cache (refresh-safe report storage).
 *
 * Secrets come from constants defined in the root config.php
 * (NOTIF_SECRET_KEY, AUTH_USER, AUTH_PASS). Every method fails gracefully when
 * OpenSSL is missing.
 */
class Security
{
    // ---------------------------------------------------------------- crypto

    /** Two independent 32-byte keys derived from the master secret. */
    private static function keys(): array
    {
        return [
            'enc' => substr(hash('sha256', 'enc|' . NOTIF_SECRET_KEY, true), 0, 32),
            'mac' => substr(hash('sha256', 'mac|' . NOTIF_SECRET_KEY, true), 0, 32),
        ];
    }

    /**
     * Encrypt + authenticate a string. Output = base64(hmac(32) . iv(16) . ct).
     * Returns false on failure (e.g. OpenSSL missing) so callers degrade safely.
     */
    public static function encrypt($plaintext)
    {
        if (!function_exists('openssl_encrypt')) {
            return false;
        }
        $k = self::keys();
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $ct = openssl_encrypt((string)$plaintext, 'aes-256-cbc', $k['enc'], OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            return false;
        }
        $mac = hash_hmac('sha256', $iv . $ct, $k['mac'], true);
        return base64_encode($mac . $iv . $ct);
    }

    /** Verify + decrypt a blob from encrypt(). null if tampered/wrong key/missing. */
    public static function decrypt($blob)
    {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }
        $raw = base64_decode((string)$blob, true);
        if ($raw === false || strlen($raw) < 49) { // 32 mac + 16 iv + >=1
            return null;
        }
        $k = self::keys();
        $mac = substr($raw, 0, 32);
        $iv  = substr($raw, 32, 16);
        $ct  = substr($raw, 48);
        if (!hash_equals($mac, hash_hmac('sha256', $iv . $ct, $k['mac'], true))) {
            return null;
        }
        $pt = openssl_decrypt($ct, 'aes-256-cbc', $k['enc'], OPENSSL_RAW_DATA, $iv);
        return $pt === false ? null : $pt;
    }

    // ----------------------------------------------------------------- login

    /** True when a username/password gate is configured. */
    public static function authEnabled(): bool
    {
        return AUTH_USER !== '' && AUTH_PASS !== '';
    }

    /** Secret used to sign the login cookie (derived from the master secret). */
    private static function authKey(): string
    {
        return hash('sha256', 'auth|' . NOTIF_SECRET_KEY, true);
    }

    /** HTTPS-only cookies when the request is secure. */
    public static function cookieSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }

    /** Build an HMAC-signed login token (works even without OpenSSL). */
    public static function makeToken($exp): string
    {
        $payload = base64_encode(json_encode(['u' => AUTH_USER, 'exp' => (int)$exp]));
        return $payload . '.' . hash_hmac('sha256', $payload, self::authKey());
    }

    /** Validate a signed login token: signature + user + not expired. */
    public static function tokenValid($tok): bool
    {
        $p = explode('.', (string)$tok);
        if (count($p) !== 2) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', $p[0], self::authKey()), $p[1])) {
            return false;
        }
        $d = json_decode(base64_decode($p[0], true) ?: '', true);
        return is_array($d) && ($d['u'] ?? '') === AUTH_USER && (int)($d['exp'] ?? 0) >= time();
    }

    /** Is this browser logged in? (Always true when the gate is disabled.) */
    public static function isLoggedIn(): bool
    {
        if (!self::authEnabled()) {
            return true;
        }
        return self::tokenValid($_COOKIE['bls_auth'] ?? '');
    }

    /** Persist the login for ~1 year so it is asked only once per browser. */
    public static function setAuthCookie(): void
    {
        $exp = time() + 365 * 24 * 3600;
        setcookie('bls_auth', self::makeToken($exp), [
            'expires' => $exp, 'path' => '/', 'secure' => self::cookieSecure(),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /** Clear the login cookie (logout). */
    public static function clearAuthCookie(): void
    {
        setcookie('bls_auth', '', [
            'expires' => time() - 3600, 'path' => '/', 'secure' => self::cookieSecure(),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    // ------------------------------------------------- per-browser cache

    /** A stable random per-browser id (cookie) used to key that browser's cache. */
    public static function clientUid(): string
    {
        $uid = $_COOKIE['bls_uid'] ?? '';
        if (!preg_match('~^[a-f0-9]{32}$~', $uid)) {
            $uid = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
            setcookie('bls_uid', $uid, [
                'expires' => time() + 365 * 24 * 3600, 'path' => '/',
                'secure' => self::cookieSecure(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
            $_COOKIE['bls_uid'] = $uid; // usable already within this request
        }
        return $uid;
    }

    private static function cacheDir(): string
    {
        return Support::dataDir() . '/cache';
    }

    /** Per-user, per-key cache file path. The filename leaks nothing (hashed). */
    private static function cachePath($uid, $name): string
    {
        return self::cacheDir() . '/' . hash('sha256', $uid . '|' . $name) . '.enc';
    }

    /** Encrypt + store a string in the caller's cache. Returns false on failure. */
    public static function cachePut($uid, $name, $data): bool
    {
        Support::ensureDataDir();
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $blob = self::encrypt((string)$data);
        if ($blob === false) {
            return false;
        }
        $path = self::cachePath($uid, $name);
        $ok = @file_put_contents($path, $blob, LOCK_EX);
        if ($ok !== false) {
            @chmod($path, 0600);
        }
        return $ok !== false;
    }

    /** Load + decrypt a cached string, or null if missing/expired/unreadable. */
    public static function cacheGet($uid, $name, $maxAge = 0)
    {
        $path = self::cachePath($uid, $name);
        if (!is_file($path)) {
            return null;
        }
        if ($maxAge > 0 && (time() - (int)@filemtime($path)) > $maxAge) {
            return null;
        }
        $blob = @file_get_contents($path);
        if ($blob === false) {
            return null;
        }
        return self::decrypt($blob);
    }

    // ------------------------------------------------- batch job state
    //
    // A progressive analysis is split into two encrypted per-browser blobs:
    //   'job'  — the immutable work order (parsed records + niche + pbn + opts),
    //            written once on submit.
    //   'prog' — the cursor: {offset, done}. 'offset' is the next record to
    //            process (server-authoritative, so a batch is idempotent and a
    //            reload resumes instead of restarting); 'done' accumulates the
    //            slim processed records for the final report assembly.
    // Both are AES-encrypted via cachePut/cacheGet exactly like the report cache.

    /** Persist the immutable analysis job for this browser. */
    public static function jobSave(string $uid, array $job): bool
    {
        return self::cachePut($uid, 'job', json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Load the analysis job, or null if none / unreadable. */
    public static function jobLoad(string $uid): ?array
    {
        $raw = self::cacheGet($uid, 'job');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** Persist the progress cursor (offset + accumulated slim records). */
    public static function progSave(string $uid, array $prog): bool
    {
        return self::cachePut($uid, 'prog', json_encode($prog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Load the progress cursor, defaulting to a fresh zero state. */
    public static function progLoad(string $uid): array
    {
        $raw = self::cacheGet($uid, 'prog');
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) {
                return ['offset' => (int)($d['offset'] ?? 0), 'done' => (array)($d['done'] ?? [])];
            }
        }
        return ['offset' => 0, 'done' => []];
    }
}
