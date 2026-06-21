<?php
/**
 * Config — algorithmic defaults for the scoring engine (weights, spam patterns,
 * TLD tables, etc.). These are generic and niche-agnostic; the analysed site is
 * always supplied at runtime via the form/CLI, never hard-coded to one brand.
 *
 * Editable SECRETS (encryption key, login, cron token) live in the root
 * config.php, not here.
 */
class Config
{
    /** @return array the full default configuration consumed by the Engine. */
    public static function defaults(): array
    {
        return [
            // A neutral placeholder; the real target is chosen on the form/CLI.
            'TARGET_URL' => 'https://example.com/',
            // Generic seed keywords. Override per run with the "niche" field.
            'NICHE_KEYWORDS' => [
                'business', 'technology', 'marketing', 'finance', 'health',
                'lifestyle', 'news', 'education', 'software', 'design',
                'startup', 'ecommerce', 'travel', 'food', 'home',
            ],
            'RELEVANCE_SATURATION' => 3.0,

            'WEIGHTS' => [
                'relevance' => 30, 'authority' => 25, 'link_friendliness' => 12,
                'domain_health' => 13, 'tld_lang_geo' => 10, 'spam_safety' => 10,
            ],

            'EXCLUDE_TOXIC_NEIGHBORHOODS' => true,
            'EXCLUDE_DEAD' => true,
            'EXCLUDE_PARKED' => true,
            'EXCLUDE_NOINDEX' => true,

            'REQUEST_TIMEOUT' => 12,
            'MAX_WORKERS' => 10,
            'VERIFY_SSL' => true,
            'MAX_HTML_BYTES' => 1000000,
            'OVERALL_DEADLINE' => 270,   // seconds; hard stop so the page never hangs
            'USER_AGENT' =>
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
                '(KHTML, like Gecko) Chrome/124.0 Safari/537.36',

            'SPAM_SAFETY_CAP' => 6.0,
            'BAD_TLDS' => ['top', 'sbs', 'xyz', 'monster', 'cfd', 'buzz', 'site', 'online',
                'click', 'work', 'pro', 'cloud', 'icu', 'tk', 'ml', 'ga', 'cf', 'gq', 'link'],
            'SPAMMY_NAME_SUBSTRINGS' => ['backlink', 'seo-tool', 'seotool', 'linkbuild',
                'link-build', 'buylink', 'buy-link', 'guestpost-service', 'cheapseo',
                'rankboost', 'linkfarm'],
            'TOXIC_NEIGHBORHOOD_PATTERNS' => [
                'stream(east|ing)?', 'movies?(da|flix|hub|joy)?', '123movies', 'putlocker',
                'watchfree', 'torrent', '\bpirate', '\bxxx\b', '\bporn', 'sex(cam|chat)?',
                'escort', 'casino', 'betting|\bbet\b|gambl', 'viagra|cialis|pharma',
                'replica', 'crypto(pump|signals)', 'aiyifan', 'sfm-compile',
                'internet-chicks', 'baddiehub',
            ],
            'GUEST_POST_MARKERS' => ['write for us', 'guest post', 'guest posting',
                'guest author', 'become a contributor', 'contributor guidelines',
                'submit a post', 'submit an article', 'submit your', 'contribute',
                'sponsored post', 'sponsored content', 'advertise with us',
                'publish with us', 'add a guest post'],
            'GUEST_POST_SLUGS' => ['write-for-us', 'guest-post', 'guest-posting',
                'contribute', 'submit-post', 'submit-article', 'advertise',
                'sponsored-post', 'become-a-contributor'],

            'NETWORK_TOKENS' => ['magazine', 'news', 'daily', 'times', 'journal', 'herald',
                'tribune', 'gazette', 'media', 'digital', 'today', 'weekly', 'report',
                'bulletin', 'chronicle', 'wire', 'press', 'post'],
            'PBN_CLUSTER_MIN_SIZE' => 4,

            'TWO_LEVEL_SUFFIXES' => ['co.uk' => 1, 'org.uk' => 1, 'ac.uk' => 1, 'gov.uk' => 1,
                'me.uk' => 1, 'ltd.uk' => 1, 'plc.uk' => 1, 'net.uk' => 1, 'com.au' => 1, 'net.au' => 1,
                'org.au' => 1, 'edu.au' => 1, 'gov.au' => 1, 'co.nz' => 1, 'org.nz' => 1, 'co.in' => 1,
                'net.in' => 1, 'org.in' => 1, 'com.br' => 1, 'com.cn' => 1, 'co.jp' => 1, 'co.za' => 1],

            'TLD_SCORES' => ['com' => 1.0, 'org' => 0.95, 'net' => 0.9, 'edu' => 1.0, 'gov' => 1.0,
                'co.uk' => 0.95, 'uk' => 0.95, 'org.uk' => 0.92, 'ac.uk' => 1.0, 'gov.uk' => 1.0,
                'io' => 0.85, 'co' => 0.8, 'us' => 0.8, 'ca' => 0.85, 'au' => 0.85, 'com.au' => 0.85,
                'de' => 0.8, 'fr' => 0.8, 'es' => 0.75, 'it' => 0.75, 'nl' => 0.8, 'eu' => 0.8,
                'info' => 0.5, 'biz' => 0.45, 'online' => 0.4, 'site' => 0.4, 'xyz' => 0.35,
                'top' => 0.25, 'click' => 0.2, 'link' => 0.25, 'buzz' => 0.25, 'icu' => 0.2],
            'NEUTRAL_TLD_SCORE' => 0.65,
            'PREFERRED_LANGS' => ['en' => 1],
            'PREFERRED_GEO_TLDS' => ['uk' => 1, 'co.uk' => 1, 'org.uk' => 1],

            'PARKED_MARKERS' => ['domain is for sale', 'buy this domain',
                'this domain may be for sale', 'is for sale', 'parked domain',
                'parked free', 'courtesy of godaddy', 'sedoparking', 'domain parking',
                'hugedomains', 'available for purchase', 'this website is for sale',
                'default web page', 'apache2 ubuntu default', 'welcome to nginx',
                'index of /'],

            'STOPWORDS' => array_flip(['the', 'and', 'for', 'are', 'with', 'you', 'your',
                'our', 'this', 'that', 'from', 'have', 'has', 'was', 'were', 'will', 'can', 'all',
                'any', 'but', 'not', 'out', 'use', 'get', 'more', 'about', 'into', 'they', 'their',
                'what', 'when', 'where', 'which', 'who', 'how', 'why', 'than', 'then', 'them',
                'these', 'those', 'here', 'there', 'also', 'been', 'being', 'over', 'home',
                'page', 'menu', 'search', 'click', 'read', 'contact', 'privacy', 'policy',
                'terms', 'cookies', 'cookie', 'rights', 'reserved', 'copyright', 'website',
                'site', 'services', 'service', 'company', 'best', 'top', 'new', 'https', 'http',
                'www', 'com', 'call', 'england', 'south', 'north', 'east', 'west', 'area',
                'areas', 'local', 'near', 'team', 'years', 'experience', 'quality', 'trusted',
                'professional', 'free', 'quote', 'today', 'need', 'help', 'work', 'time',
                'made', 'make', 'well', 'good', 'great', 'every', 'first', 'last']),
        ];
    }
}
