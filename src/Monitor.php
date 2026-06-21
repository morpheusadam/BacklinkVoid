<?php
/**
 * Monitor — the "Backlink Notif" feature: an encrypted state file holding the
 * monitored domain list + Telegram credentials, a Telegram sender, and the
 * weekly scan that alerts on newly spam/toxic domains.
 *
 * Reuses Engine for detection and Security for at-rest encryption. The Telegram
 * token + chat id live ONLY in the encrypted state file, never anywhere else.
 */
class Monitor
{
    /** Absolute path to the single encrypted state file. */
    public static function statePath(): string
    {
        return Support::dataDir() . '/state.enc';
    }

    /** Load + decrypt the saved state, or null if none / unreadable. */
    public static function loadState()
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return null;
        }
        $blob = @file_get_contents($path);
        if ($blob === false || $blob === '') {
            return null;
        }
        $json = Security::decrypt($blob);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** Encrypt + write the state array. Returns true on success. */
    public static function saveState($state): bool
    {
        Support::ensureDataDir();
        $blob = Security::encrypt(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($blob === false) {
            return false;
        }
        $path = self::statePath();
        $ok = @file_put_contents($path, $blob, LOCK_EX);
        if ($ok !== false) {
            @chmod($path, 0600);
        }
        return $ok !== false;
    }

    /** Delete the saved state file. */
    public static function clearState(): void
    {
        $path = self::statePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Mask a secret for display — the real value is never echoed to the page. */
    public static function mask($s): string
    {
        return ((string)$s === '') ? '—' : '••••';
    }

    /** Validate + normalise a pasted list into clean, de-duplicated hostnames. */
    public static function cleanDomains($text, $cfg): array
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $norm = Support::normalizeUrl($line);
            if (!$norm) {
                continue;
            }
            $host = strtolower((string)parse_url($norm, PHP_URL_HOST));
            $host = preg_replace('~^www\.~', '', $host);
            if ($host === '' || strpos($host, '.') === false) {
                continue;
            }
            if (isset($seen[$host])) {
                continue;
            }
            $seen[$host] = 1;
            $out[] = $host;
        }
        return $out;
    }

    /**
     * Send one Telegram message. Returns true only on confirmed delivery; fails
     * gracefully (false) if cURL is missing, the token is malformed, or Telegram
     * is unreachable. Never throws.
     */
    public static function telegramSend($token, $chat_id, $text): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $token = trim((string)$token);
        $chat_id = trim((string)$chat_id);
        if ($token === '' || $chat_id === '') {
            return false;
        }
        if (!preg_match('~^\d+:[A-Za-z0-9_\-]+$~', $token)) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.telegram.org/bot' . $token . '/sendMessage',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chat_id,
                'text' => $text,
                'disable_web_page_preview' => 'true',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code !== 200) {
            return false;
        }
        $j = json_decode($resp, true);
        return is_array($j) && !empty($j['ok']);
    }

    /**
     * Scan saved domains LIVE and return [domain => reason] for those that look
     * spam/toxic now. A domain is flagged when it is a toxic neighborhood, has
     * spam points, or is parked/for-sale (a classic toxic takeover).
     */
    public static function scan($domains, $cfg): array
    {
        $records = [];
        foreach ($domains as $d) {
            $bl = Engine::newBacklink($d);
            $norm = Support::normalizeUrl($d);
            if ($norm) {
                $bl['source_url'] = $norm;
                [$bl['registrable_domain'], $bl['tld']] =
                    Support::registrableDomain(parse_url($norm, PHP_URL_HOST), $cfg['TWO_LEVEL_SUFFIXES']);
            }
            $records[] = $bl;
        }

        // Best-effort live fetch. Name/TLD checks still run if cURL is missing.
        if (function_exists('curl_multi_init')) {
            $urls = [];
            foreach ($records as $rec) {
                if ($rec['source_url'] !== '') {
                    $urls[] = $rec['source_url'];
                }
            }
            $fetched = Engine::fetchMany($urls, $cfg);
            foreach ($records as &$rec) {
                if ($rec['source_url'] === '') {
                    continue;
                }
                $f = $fetched[$rec['source_url']] ?? null;
                if ($f) {
                    Engine::extractSignals($rec, $f['status'], $f['final'], $f['body'], $cfg);
                }
            }
            unset($rec);
        }

        $pbn = Engine::detectPbnClusters($records, $cfg);
        $flagged = [];
        foreach ($records as $rec) {
            [$pts, $sig] = Engine::computeSpamPoints($rec, $pbn, $cfg);
            $toxic = Engine::isToxicNeighborhood($rec, $cfg);
            $is_bad = $toxic || $pts >= 2 || $rec['parked'];
            if (!$is_bad) {
                continue;
            }
            $reasons = $sig;
            if ($toxic) {
                $reasons[] = 'toxic neighborhood (piracy/adult/gambling)';
            }
            if ($rec['parked']) {
                $reasons[] = 'parked / for-sale takeover';
            }
            $key = $rec['registrable_domain'] !== '' ? $rec['registrable_domain'] : $rec['raw'];
            $flagged[$key] = $reasons ? implode('; ', array_unique($reasons)) : 'spam signals';
        }
        return $flagged;
    }

    /** Detailed English "started" confirmation (with emojis) for Telegram. */
    public static function startedMessage($count, $expires_at): string
    {
        $until = date('Y-m-d', (int)$expires_at);
        return implode("\n", [
            '🚀 Backlink Checker started!',
            '',
            '✅ Monitoring is now active.',
            '🔗 Links being indexed: ' . (int)$count,
            '🗓️ Checks run automatically every 7 days (weekly).',
            '⏳ This monitor stays on for 1 year — until ' . $until . '.',
            '🔔 You will get an alert here if any monitored domain turns spam or toxic.',
            '🛡️ Your list and settings are stored encrypted on the server.',
            '',
            '— Backlink Prospect Scorer',
        ]);
    }

    /**
     * Run the weekly check once. Honours the 7-day self-throttle unless $force,
     * auto-expires after 1 year, and alerts Telegram only about NEWLY spam/toxic
     * domains. Returns a short plain-text status string. Never throws.
     */
    public static function runCheck($force, $cfg): string
    {
        $state = self::loadState();
        if (!$state || empty($state['active'])) {
            return 'no active monitor';
        }

        $now = time();

        // Auto-expire after the 1-year window: stop and notify once.
        if (!empty($state['expires_at']) && $now >= (int)$state['expires_at']) {
            $state['active'] = false;
            self::saveState($state);
            self::telegramSend(
                $state['bot_token'] ?? '',
                $state['chat_id'] ?? '',
                "🏁 Backlink Checker finished.\n\n⏳ The 1-year monitoring window has ended. " .
                "Open the Backlink Notif tab and submit again to start another year."
            );
            return 'expired: monitor deactivated after 1 year';
        }

        if (!$force && !empty($state['last_run']) && ($now - (int)$state['last_run']) < NOTIF_INTERVAL) {
            $left = NOTIF_INTERVAL - ($now - (int)$state['last_run']);
            return 'throttled: next run in ~' . (int)ceil($left / 3600) . 'h';
        }

        $domains = $state['domains'] ?? [];
        if (!$domains) {
            $state['last_run'] = $now;
            self::saveState($state);
            return 'no domains saved';
        }

        $flagged_now = self::scan($domains, $cfg);
        $prev = $state['flagged'] ?? [];
        $newly = [];
        foreach ($flagged_now as $dom => $why) {
            if (!isset($prev[$dom])) {
                $newly[$dom] = $why;
            }
        }

        if ($newly) {
            $lines = ['⚠️ Backlink Checker — newly spam/toxic domain(s):', ''];
            foreach ($newly as $dom => $why) {
                $lines[] = '• ' . $dom . ' — ' . $why;
            }
            $lines[] = '';
            $lines[] = 'Scanned ' . count($domains) . ' domain(s) on ' . date('Y-m-d H:i') . '.';
            self::telegramSend($state['bot_token'], $state['chat_id'], implode("\n", $lines));
        }

        // Persist the new baseline so resolved domains drop off and only
        // genuinely new problems alert next time.
        $state['flagged'] = $flagged_now;
        $state['last_run'] = $now;
        $state['last_summary'] = [
            'at' => $now, 'checked' => count($domains),
            'flagged' => count($flagged_now), 'new' => count($newly),
        ];
        self::saveState($state);

        return 'ok: checked ' . count($domains) . ', flagged ' . count($flagged_now) . ', new ' . count($newly);
    }
}
