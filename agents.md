# AGENTS.md — Backlink Void Checker

A guide for any agent/developer working on this project. It records the
architecture, every feature built, the critical host-limit bug and its fix, how
to run/test, and what's still outstanding.

Repo: `github.com/morpheusadam/backlinkvoidchecker` · Branch `main` · Tags
`v1.0.0`, `v3.0.0`.

---

## 1. What this is

A two-edition tool:

| Edition | Entry | Use |
|---|---|---|
| **Web app (PHP, OOP)** | `index.php` + `src/` | Browser tool for cPanel / shared hosting (e.g. Hostinger). |
| **Terminal (Python)** | `terminal-version/backlink_evaluator.py` | Standard-library CLI scorer. |

It (a) **scores candidate domains** for how good a backlink from each would be,
(b) **audits a list for spam/toxicity** and builds a **Google Disavow file**, and
(c) **monitors existing backlinks weekly** and alerts on **Telegram**.

> ⚠️ Brand-neutral: never reference "drainage"/"plumbing". Defaults use
> `example.com` + generic niche keywords. Keep it that way.

---

## 2. File map

```
index.php              # thin bootstrap (root): ini, polyfills, no-cache headers,
                       #   APP_ROOT, load config(.local), autoloader, Router::dispatch
config.php             # EDITABLE secrets/settings (committed with placeholders)
config.local.php       # optional real secrets (git-ignored; loaded first)
.htaccess              # noindex headers; blocks config*.php from the web
robots.txt             # Disallow: /
README.md  LICENSE(MIT)  .gitignore  agents.md
src/
  .htaccess            # deny-all (classes are include-only, never web-served)
  Config.php           # Config::defaults() — weights, spam patterns, TLD tables
  Support.php          # h(), URL/domain helpers, dataDir()/ensureDataDir()
  Engine.php           # scoring pipeline, spam audit, streaming/batch helpers
  Security.php         # AES-256-CBC+HMAC crypto, login cookie, per-browser cache
  Monitor.php          # Backlink Notif: encrypted state, Telegram, weekly scan
  View.php             # ALL html: report, form+console, notif page, login
  Router.php           # dispatch + every endpoint (see §4)
terminal-version/
  backlink_evaluator.py  # Python CLI (base scorer; genericized)
  README.md  backlinks.txt
notif_data/            # AUTO-created, git-ignored, encrypted runtime data:
  .htaccess (deny-all)
  state.enc            # Monitor (Telegram) state
  cache/               # per-browser report cache + job + prog_<offset> batch files
```

`index.php` only bootstraps. All logic lives in `src/` (one class per file,
plain class names, simple `spl_autoload_register` → `src/<Class>.php`).

---

## 3. Architecture (OOP)

| Class | Responsibility |
|---|---|
| `Config` | `defaults()` returns the big config array (weights, BAD_TLDS, TOXIC patterns, GUEST_POST markers, TLD scores, stopwords…). |
| `Support` | `h()` escaping; `normalizeUrl`, `registrableDomain`, `hostOf`, `splitConcatenated`; `dataDir()`/`ensureDataDir()` (creates `notif_data/` + deny `.htaccess`). |
| `Engine` | The brain. `parseRecords`, `fetchMany` (curl_multi, per-completion callback), `extractSignals`, `buildProfile`, `detectPbnClusters`, `isToxicNeighborhood`, `computeSpamPoints`, scoring (`scoreProspect`/`classifyProspect`), `auditRisk` (3-tier), `processOne`, `verdict`, `assembleReport`, `runPipeline`. |
| `Security` | `encrypt`/`decrypt` (AES-256-CBC + encrypt-then-HMAC); login (`authEnabled`, `makeToken`, `tokenValid`, `isLoggedIn`, `set/clearAuthCookie`); per-browser cache (`clientUid`, `cachePut`, `cacheGet`). |
| `Monitor` | Backlink Notif: `loadState`/`saveState`/`clearState` (encrypted), `cleanDomains`, `mask`, `telegramSend`, `scan`, `startedMessage`, `runCheck`. |
| `View` | `report`, `form`, `notifPage`, `login`, `topNav` — pure presentation. |
| `Router` | `dispatch()` + endpoint handlers (`health`, `prepareJob`, `sseStream`, `batchCheck`, `handleNotif`) + `collectInput`. |

---

## 4. Endpoints (all on `index.php` via query string)

Order matters in `Router::dispatch()`:

1. `?cron=run&token=<NOTIF_CRON_TOKEN>[&force=1]` — weekly Telegram scan. **No login** (token-gated). Runs before everything.
2. `?health=1` — self-test page (PHP/OpenSSL/cURL, time-limit raisability, writable dir, classes loaded, cache round-trip). **No login.** Diagnostic for stubborn hosts.
3. **Login gate** (if `AUTH_USER`/`AUTH_PASS` set): `?logout=1`; `POST action=login`; else shows `View::login`.
4. After login, `$uid = Security::clientUid()`.
5. `?tab=notif` or `POST action=notif_*` → `handleNotif` (Backlink Notif page; submit/cancel).
6. `?report=1` → serve the cached report for this browser (refresh-safe).
7. `?prepare=1` (POST) → validate list, build profile + PBN once, store encrypted **job**, return `{ok,total}` JSON.
8. `?sse=1` (GET) → Server-Sent Events stream (`hello`→`item`×N→`summary`→`done`). For hosts that allow long streaming.
9. `?batch=1` (POST `{offset,size}`) → check one slice, return JSON verdicts; on last slice assemble + cache the full report. **The reliable default path.**
10. `POST` (no action) → no-JS analyze fallback (`Engine::runPipeline` → cache → redirect `?report=1`).
11. `GET` default → the input form.

---

## 5. Features (what & where)

- **Prospect scorer** — 6 weighted factors (relevance 30, authority 25,
  link-friendliness 12, domain_health 13, tld_lang_geo 10, spam_safety 10);
  per-row "why". Avoid list for toxic/dead/parked/de-indexed. (`Engine`, `View::report`)
- **For Removal / Disavow — 3-tier audit** (`Engine::auditRisk`):
  - **DISAVOW** (hard, auto-listed): toxic-content pattern, spammy TLD, spammy
    name substring, or provided toxicity ≥ 60.
  - **REVIEW** (soft, never auto-disavowed): parked/dead +40, noindex +30, thin
    content +25, irrelevant niche +15, PBN footprint +20.
  - **KEEP**. Conservative bias: only hard signals disavow. Auto-builds the
    `domain:example.com` disavow file (download/copy).
- **Exports** — PDF (jsPDF/CDN), **Excel** (`.xls` via HTML-table-as-worksheet,
  no lib), CSV; scope = current / guest-post-only. **Sort-by** dropdown.
- **Live spam-check console** — dark, monospace, auto-scroll, colored verdicts
  (🟢 clean / 🟡 suspicious / 🔴 spam), live counts, summary, client-built
  `disavow.txt`, "Open full report". **Batch-driven** (see §6).
- **Backlink Notif** (`Monitor`) — paste backlinks + Telegram bot token + chat id;
  weekly cron scan alerts on **newly** spam/toxic domains. 1-year lifetime,
  auto-expire, "🚀 Backlink Checker started" message (emojis, link count, weekly
  cadence). Cancel **keeps** creds (blank fields on re-submit reuse them).
- **Login** — `admin`/`adminA` default, remembered ~1 year via HMAC-signed cookie.
- **Encrypted per-browser cache** — finished report stored encrypted; refresh
  re-serves it (PRG); never re-runs.

---

## 6. ⭐ The critical lesson (the "100 of 700" bug)

**Symptom:** user pastes 700 domains → only ~100 in output, and the loader shows
nothing. Reported repeatedly; "fixed" several times but never worked **on the
host**.

**Root cause (NOT a code cap — there is none):** the host (**Hostinger**) ignores
`set_time_limit()` / `max_execution_time` and **hard-kills one long request** after
~30–60 s. Fetching 700 domains in a single request died after ~100, before
rendering/caching — so the loader (the same request) also showed nothing. Local
tests never reproduced it because the CLI has no time limit.

**Fix — never do it in one request:** the browser drives the analysis in **small
batches**. `?prepare=1` stores the job once, then a loop of short `?batch=1`
requests (12 domains each) — every request finishes well within any host limit,
so the **whole** list completes. Verdicts render line-by-line for a live feel.
SSE (`?sse=1`) is kept for hosts that allow long streaming, but **batching is the
default** (no dependency on one long request or on the CDN not buffering).

**Proven:** 300 domains → all 300 analyzed across 25 short requests; report shows
300. Scales to 1000+.

**Takeaway for future agents:** on shared hosting, **never** assume you can
process a large list synchronously in one request. Chunk it client-side. Use
`?health=1` to see the host's real limits (it reports whether the time limit can
be raised). I cannot deploy to the user's host — `?health=1` is how the host gets
diagnosed remotely.

---

## 7. Security model

- All at-rest data (`notif_data/`) encrypted with **AES-256-CBC + encrypt-then-
  HMAC** (`Security::encrypt/decrypt`), independent derived keys.
- Telegram token + chat id live **only** in `state.enc`; never rendered (masked
  `••••`).
- Login cookie is **HMAC-signed** (works without OpenSSL).
- `config.php`/`config.local.php` blocked from web by root `.htaccess`; whole
  `src/` blocked by `src/.htaccess`; `notif_data/` blocked by its own `.htaccess`.
- Browser told `no-store`; refresh-safety comes from the **server-side** encrypted
  cache, not the browser cache.
- Per-request fetch timeout 5 s (8 s for the single prepare fetch); bounded
  workers; graceful failure everywhere (never throws/locks).

---

## 8. Configuration (`config.php`)

| Const | Meaning | Default |
|---|---|---|
| `AUTH_USER` / `AUTH_PASS` | Login (both `''` to disable) | `admin` / `adminA` |
| `ACCESS_PASSWORD` | Extra password on POST actions | `''` |
| `NOTIF_SECRET_KEY` | **At-rest encryption key — CHANGE IT** | placeholder |
| `NOTIF_CRON_TOKEN` | Secret in the cron URL — CHANGE IT | placeholder |
| `NOTIF_INTERVAL` / `NOTIF_DURATION` | 7 days / 1 year | — |

Real secrets → put the same `define()`s in **`config.local.php`** (git-ignored,
loaded first). **Change `admin/adminA` and `NOTIF_SECRET_KEY` before going live.**

Weekly cron (cPanel → Cron Jobs):
```
0 9 * * 1 curl -fsS "https://YOURDOMAIN/backlink/index.php?cron=run&token=PUT-CRON-TOKEN" >/dev/null 2>&1
```

---

## 9. Deployment

Upload **`index.php` + `config.php` + the whole `src/` folder** together (plus
`.htaccess`, `robots.txt`) under `public_html/...`. Missing `src/` → blank/500.
Then open `?health=1` to verify. PHP 7.4+ with **cURL + OpenSSL** (mbstring
optional, polyfilled).

---

## 10. How to run / test locally (IMPORTANT)

The **default `php` on PATH lacks openssl/curl/mbstring** → encryption/cache/login
report as unavailable. Use the winget PHP build whose `ext/` has the DLLs:

```bash
PHPDIR=$(dirname "$(php -r 'echo PHP_BINARY;')")     # .../PHP.PHP.8.3.../
EXT="$PHPDIR/ext"
PHPX="php -d extension_dir=$EXT -d extension=openssl -d extension=curl -d extension=mbstring"
```

- Lint: `for f in index.php src/*.php; do php -l "$f"; done`
- HTTP/SSE/cookie tests: `$PHPX -S 127.0.0.1:8095 -t .` then `curl`.
- Forge a valid login cookie (no browser):
  `bls_auth = base64(json{"u":"admin","exp":<future>}) . "." . hmac_sha256(payload, sha256("auth|".NOTIF_SECRET_KEY))`, and any 32-hex `bls_uid`.
- Git Bash gotcha: `UID` is read-only; `bc` is absent (use `perl`); `curl
  --data-urlencode @/tmp/file` paths are flaky — prefer a small PHP client that
  curls localhost for batch-loop tests.

Verified flows: login gate, form console, `?prepare=1`→`?batch=1` loop (all
domains), `?report=1` cache, `?sse=1` (events arrive incrementally — timestamped
T+0.00/0.30/0.72/1.08s with workers=1), notif submit/cancel/persistence, cron
auth + throttle, `?health=1`, no-JS POST fallback.

---

## 11. Conventions

- One class per file, plain names, static methods; `$cfg` array threaded through
  (faithful to the original procedural design — keeps behavior identical).
- Heredocs for HTML; only interpolate pre-computed `$vars` (no function calls
  inside heredocs). Nowdoc `<<<'JS'` for client JS.
- Always `Support::h()` on output. Fail gracefully (return false/null, never throw).
- Keep `index.php` a thin bootstrap; logic in `src/`.

---

## 12. Git / versions

- Commits: `35b4e30` (initial OOP app + CLI), `1d623a4` (SSE), `55cc67a`
  (batch-driven fix + `?health=1`).
- Tags: **`v1.0.0`** (base scorer lineage), **`v3.0.0`** (current full app;
  re-pointed to latest).
- Remote `origin` set; repo is empty. **Cannot push from the dev environment (no
  GitHub credentials).** User finishes with:
  ```
  git push -u origin main
  git push -f origin v3.0.0 v1.0.0
  gh release create v3.0.0 --generate-notes   # + v1.0.0
  ```

---

## 13. Outstanding / TODO

- [ ] **Push to GitHub + create releases** — needs the user's credentials (the
      dev env has none; `gh` not installed).
- [ ] **Verify on the real Hostinger host** via `?health=1`, then run an analysis.
- [ ] **Python CLI feature port** — the background agent only **relocated +
      genericized** the base scorer into `terminal-version/`. The Excel /
      Google-Disavow exports and the Telegram monitor are **web-only**; porting to
      the CLI is not done.
- [ ] Optional: prune stale `notif_data/cache/prog_<offset>` files between runs.

---

## 14. Work log (chronological)

1. **Report exports** — added Excel (`.xls`), guest-post-only / current scope, and
   a **Google Disavow** tab (download/copy `domain:` file) to the report.
2. **Backlink Notif** — encrypted Telegram weekly monitor: AES state file, cron
   endpoint with 7-day throttle, 1-year lifetime, detailed start message.
3. **Notif credential persistence** — Cancel pauses but keeps token/chat id; blank
   fields on re-submit reuse them (fixed "asks again after edit").
4. **Login + cache + loader** — `admin/adminA` remembered per browser; encrypted
   per-browser report cache (PRG, refresh-safe); loading overlay.
5. **Brand-neutralize** — removed all drainage/plumbing refs → `example.com` +
   generic niche.
6. **OOP refactor** — split the monolithic `index.php` into `config.php` + `src/`
   classes with a thin bootstrap; behavior-identical (re-tested).
7. **Repo scaffolding** — README, MIT LICENSE, `.gitignore` (secrets/runtime),
   `.htaccess` hardening, `git init`, tags `v1.0.0`/`v3.0.0`.
8. **For Removal / Disavow audit** — 3-tier DISAVOW/REVIEW/KEEP classifier; first
   attempt at "analyze all domains" (offline fallback so deadline-skipped domains
   aren't dumped to Avoid); Sort-by filter; exact analyzed counts.
9. **SSE streaming** — `?prepare=1` + `?sse=1` (EventSource) live console with a
   6-s buffering watchdog → batch-polling fallback.
10. **DEBUG: the real fix** — diagnosed the host execution-time limit as the true
    cause of "100 of 700" + blank loader. Made **batch polling the primary path**
    (12-domain short requests), bulletproofed the console (always opens, clear
    errors → `?health=1`), and added the **`?health=1`** self-test endpoint.

---

*Honest constraints throughout: the dev environment has no access to the user's
Hostinger host and no GitHub credentials — hence `?health=1` (remote diagnosis)
and the documented push/release handoff.*
