<?php
/**
 * config.php — EDIT THESE before going live.
 * ===========================================================================
 * This is the ONLY file you normally need to change. It defines the login
 * credentials, the at-rest encryption key, and the weekly-cron secret token.
 *
 * Keeping real secrets OUT of git:
 *   Create a file named  config.local.php  next to this one, containing the
 *   same define() lines with your real values. It is loaded BEFORE this file
 *   and is git-ignored, so your secrets never get committed. The defaults below
 *   only apply to whatever you have not already defined there.
 * ===========================================================================
 */

// --- Login gate (asked once per browser, then remembered ~1 year) ----------
// Set BOTH to '' to disable the login entirely.
if (!defined('AUTH_USER')) {
    define('AUTH_USER', 'admin');
}
if (!defined('AUTH_PASS')) {
    define('AUTH_PASS', 'adminA');
}

// --- Optional extra password on the Scorer/Notif POST actions --------------
// '' = no extra password (the login above is usually enough).
if (!defined('ACCESS_PASSWORD')) {
    define('ACCESS_PASSWORD', '');
}

// --- At-rest encryption key (CHANGE THIS) ----------------------------------
// Used to encrypt the monitor state, the per-user cache, and to sign the login
// cookie. Use a long random string (>= 32 chars). Changing it later makes any
// existing encrypted data unreadable (it is discarded safely).
if (!defined('NOTIF_SECRET_KEY')) {
    define('NOTIF_SECRET_KEY', 'CHANGE-ME-to-a-long-random-string-please-32chars-min');
}

// --- Weekly cron secret (CHANGE THIS) --------------------------------------
// Must appear in the cron URL: ?cron=run&token=NOTIF_CRON_TOKEN
if (!defined('NOTIF_CRON_TOKEN')) {
    define('NOTIF_CRON_TOKEN', 'CHANGE-ME-cron-secret-token');
}

// --- Timings ---------------------------------------------------------------
if (!defined('NOTIF_INTERVAL')) {
    define('NOTIF_INTERVAL', 7 * 24 * 3600);    // weekly-check throttle (7 days)
}
if (!defined('NOTIF_DURATION')) {
    define('NOTIF_DURATION', 365 * 24 * 3600);  // monitor lifetime (1 year)
}
