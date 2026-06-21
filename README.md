<!-- ANIMATED HEADER -->
<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=210&section=header&text=Backlink%20Void%20Checker&fontSize=46&fontColor=ffffff&animation=fadeIn&desc=Score%20%E2%80%A2%20Audit%20%E2%80%A2%20Disavow%20%E2%80%A2%20Monitor&descSize=18&descAlignY=64" alt="Backlink Void Checker" />
</p>

<!-- ANIMATED TYPING SUBTITLE -->
<p align="center">
  <img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=20&duration=3200&pause=900&color=6E8EFB&center=true&vCenter=true&width=820&lines=Rank+every+backlink+prospect+from+0+to+100;Auto-build+a+Google+Disavow+file+in+one+click;Weekly+Telegram+alerts+when+a+link+turns+toxic;PHP+web+app+%2B+Python+CLI+%E2%80%94+no+database%2C+no+build+step" alt="What it does" />
</p>

<!-- BADGES -->
<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/release-v2.0.0-6E8EFB?style=for-the-badge">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white">
  <img alt="Python" src="https://img.shields.io/badge/Python-3.8%2B-3776AB?style=for-the-badge&logo=python&logoColor=white">
  <img alt="Database" src="https://img.shields.io/badge/database-none-2EA44F?style=for-the-badge">
  <img alt="Build" src="https://img.shields.io/badge/build-none-2EA44F?style=for-the-badge">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-0A7BBB?style=for-the-badge">
</p>

<p align="center">
  <b>Score backlink prospects, audit existing links for spam / toxicity, generate a
  ready-to-upload Google Disavow file, and get weekly Telegram alerts.</b>
</p>

---

## Overview

You have a list of domains — either **candidates you want a link from**, or
**sources that already link to you**. This tool:

1. **Ranks** each domain 0–100 by how good a backlink from it would be for *your*
   site — relevance, authority, guest-post friendliness, domain health, TLD /
   language / geo fit, and spam-safety.
2. **Separates** the unusable ones (piracy / adult / gambling, dead, parked,
   de-indexed) into an **Avoid** list.
3. **Builds a Google Disavow file** from the spam / toxic domains, ready to upload
   to Search Console.
4. **Monitors** a saved list of your existing backlinks and **alerts you on
   Telegram** if any of them ever turns spam / toxic (weekly, via cron).

It ships in **two editions**:

| Edition | File | Use it when |
|---|---|---|
| **Web app** (PHP) | `index.php` + `src/` | You want a browser UI on cPanel / shared hosting — no command line. |
| **Terminal** (Python) | [`terminal-version/`](terminal-version/) | You prefer a CLI, automation, or running on a server / cron. |

---

## Architecture

The web app is a clean object-oriented design: `Router` dispatches, `Engine`
analyses, `View` renders, while `Security` and `Monitor` handle encryption and
the Telegram monitor. `index.php` only bootstraps the autoloader and config.

```mermaid
flowchart LR
    U([Browser / CLI request]) --> R

    subgraph APP["src/ — one class per file"]
        direction LR
        R["Router<br/>dispatch + Notif controller"]
        E["Engine<br/>fetch → profile → score"]
        V["View<br/>HTML: report · form · login"]
        SEC["Security<br/>AES-256-CBC · HMAC"]
        M["Monitor<br/>state store · Telegram · scan"]
        C["Config<br/>weights · patterns · TLDs"]
        S2["Support<br/>escaping · URL/domain parse"]

        R --> E
        R --> M
        R --> SEC
        E --> V
        E -. reads .-> C
        E -. reads .-> S2
        M --> SEC
        M -. reads .-> C
    end

    V --> OUT([Report · Disavow · PDF / Excel / CSV])
    M --> TG([Weekly Telegram DM])

    classDef core fill:#11182722,stroke:#6E8EFB,stroke-width:1px,color:#e5e7eb;
    class R,E,V,SEC,M,C,S2 core;
```

---

## Scoring pipeline

Every domain flows through the same pipeline. Live mode fetches and profiles the
page; offline mode scores from the name and TLD only.

```mermaid
flowchart TD
    A([Domain list<br/>txt · csv · paste]) --> B{Live fetch?}
    B -- yes --> C[Profile page<br/>content · HTTPS · indexability · parked]
    B -- no --> D[Name / TLD signals only]
    C --> E[Score 6 weighted factors]
    D --> E
    E --> F{Healthy &amp; safe?}
    F -- yes --> G[[Prospects · ranked 0–100]]
    F -- spam / toxic --> H[[Avoid list]]
    H --> I[[disavow.txt for Search Console]]

    classDef good fill:#06351f,stroke:#2EA44F,color:#d1fae5;
    classDef bad fill:#3a0d0d,stroke:#ef4444,color:#fee2e2;
    class G good;
    class H,I bad;
```

### Weights at a glance

```mermaid
pie showData
    title Scoring weight distribution
    "Relevance" : 30
    "Authority" : 25
    "Domain health" : 13
    "Link-friendliness" : 12
    "TLD / language / geo" : 10
    "Spam safety" : 10
```

| Factor | Weight | Meaning |
|---|--:|---|
| Relevance | 30 | Topic match to your site (a relevant link is worth the most) |
| Authority | 25 | Domain strength (real DR if you provide it, else a content proxy) |
| Link-friendliness | 12 | Openly accepts guest posts → realistic to win |
| Domain health | 13 | Live, indexable, real content, not parked |
| TLD / language / geo | 10 | Reputable TLD, language fit |
| Spam safety | 10 | NOT a PBN / toxic neighborhood (a bad source can hurt you) |

Weights normalise over whatever signals are available. **Start outreach at the top
of the list, and prioritise rows tagged "guest post".**

> **Authority is approximate in live mode.** Without a paid API the tool proxies
> authority from content / HTTPS / indexing. For an accurate ranking, feed a CSV
> with a `dr` / domain-rating column (Ahrefs / Moz / Semrush) — it is used
> automatically.

---

## Features

- **Prospect scoring** — six weighted factors, transparent per-row "why" breakdown.
- **Avoid list** — toxic neighborhoods, dead / parked / de-indexed pages auto-excluded.
- **Google Disavow tab** — one click to download `disavow.txt` (`domain:` lines, with
  the matched signal as a comment). Conservative by design: only genuinely spam /
  toxic domains are listed — never healthy links.
- **Exports** — PDF, **Excel** (`.xls`), and CSV, scoped to *all results* or
  *guest-post-only*.
- **Backlink Notif** — paste your live backlinks + a Telegram bot token; get a weekly
  DM listing any newly spam / toxic domains. Runs for 1 year per start.
- **Login** — a username / password gate, asked **once per browser** then remembered
  ~1 year via a signed cookie.
- **Refresh-safe, encrypted cache** — big lists are analysed once; refreshing the
  results page re-serves an **encrypted, per-browser** cached report instead of
  re-running. A loading overlay with a live timer shows progress meanwhile.
- **Privacy first** — all at-rest data (monitor settings, cache) is **AES-256-CBC
  encrypted with HMAC authentication**; the Telegram token and chat id are stored only
  in that encrypted file and never rendered to the page (shown as dots).

---

## Quick start — Web app (cPanel / shared hosting)

1. In cPanel **File Manager**, upload the whole project into a folder under
   `public_html` (e.g. `public_html/backlink/`) so `index.php`, `config.php`, the
   `src/` folder and `.htaccess` are all there.
2. Open it in a browser: `https://yourdomain.com/backlink/`.
3. Sign in (default `admin` / `adminA` — **change these**, see Configuration).
4. Enter your site URL, paste candidate domains (one per line; optional
   `domain,DR,spam`) or upload a `.txt` / `.csv`, and click **Analyze & rank**.
5. Review the **Prospects** and **Google Disavow** tabs; export as PDF / Excel / CSV.

**Requirements:** PHP **7.4+** with the **cURL** and **OpenSSL** extensions (standard
on cPanel). `mbstring` is used if present but not required.

---

<details>
<summary><b>Configuration</b> — settings, secrets, and going-live checklist</summary>

<br>

Everything you need to change lives in **`config.php`** (root):

| Setting | Purpose | Default |
|---|---|---|
| `AUTH_USER` / `AUTH_PASS` | Login credentials (set both to `''` to disable) | `admin` / `adminA` |
| `ACCESS_PASSWORD` | Optional extra password on POST actions | `''` |
| `NOTIF_SECRET_KEY` | **At-rest encryption key — change to a long random string** | placeholder |
| `NOTIF_CRON_TOKEN` | Secret in the weekly cron URL — change to random | placeholder |
| `NOTIF_INTERVAL` | Weekly-check throttle | 7 days |
| `NOTIF_DURATION` | Monitor lifetime | 1 year |

> **Keep real secrets out of git:** create a `config.local.php` next to `config.php`
> with the same `define()` lines and your real values. It is loaded first and is
> **git-ignored**, so your secrets are never committed. The defaults in `config.php`
> only apply to whatever you have not already defined.

> **Change `admin` / `adminA` and `NOTIF_SECRET_KEY` before going live.**

</details>

<details>
<summary><b>Weekly Telegram monitor (cron)</b> — set up the automated audit</summary>

<br>

1. Open the **Backlink Notif** tab, paste your existing backlink domains, and your
   Telegram **bot token** (from [@BotFather](https://t.me/BotFather)) and **chat id**
   (from [@userinfobot](https://t.me/userinfobot)). Message your bot once so it can DM
   you. Submit — you will get a "Backlink Checker started" confirmation.
2. In cPanel, go to **Cron Jobs** and add one weekly job (replace host + token):

   ```cron
   0 9 * * 1 curl -fsS "https://YOURDOMAIN/backlink/index.php?cron=run&token=PUT-YOUR-CRON-TOKEN-HERE" >/dev/null 2>&1
   ```

   The endpoint **self-throttles to once / 7 days**, so triggering it more often is
   harmless. It alerts you only about **newly** spam / toxic domains.

</details>

<details>
<summary><b>Security model</b> — how data is protected at rest and in transit</summary>

<br>

- The web app **fetches arbitrary URLs server-side**. If the host is public, keep the
  login enabled and / or add cPanel **Directory Privacy**.
- `config.php`, `config.local.php`, and the entire `src/` directory are blocked from
  direct web access via `.htaccess` (defense in depth).
- The `notif_data/` directory (encrypted monitor state + per-browser cache) is
  auto-created with a deny-all `.htaccess` and is **git-ignored**.
- Encryption: **AES-256-CBC**, **encrypt-then-HMAC** (tamper-evident), with independent
  derived keys. The login cookie is HMAC-signed.
- The browser is told **`no-store`**; refresh-safe results come from the server-side
  encrypted cache, not the browser cache.

</details>

---

## Project structure

```
.
├── index.php            # Thin bootstrap (web root entry point)
├── config.php           # Your editable settings / secrets
├── .htaccess            # noindex headers + blocks config / src from the web
├── robots.txt
├── src/                 # Object-oriented application (one class per file)
│   ├── Config.php       #   algorithmic defaults (weights, patterns, TLD tables)
│   ├── Support.php      #   helpers: escaping, URL / domain parsing, data dir
│   ├── Engine.php       #   the scoring pipeline (fetch → profile → score)
│   ├── Security.php     #   AES encryption, login cookie, per-browser cache
│   ├── Monitor.php      #   Backlink Notif: state store + Telegram + scan
│   ├── View.php         #   all HTML (report, form, notif page, login)
│   └── Router.php       #   request dispatch + the Notif controller
└── terminal-version/    # Python CLI edition (see its own README)
    └── backlink_evaluator.py
```

---

## Terminal version (Python CLI)

A dependency-free (standard-library) scorer that produces the same ranked HTML report
plus a ranked CSV. See [`terminal-version/README.md`](terminal-version/README.md).

```bash
cd terminal-version

# Rank a list for your site (HTML report + CSV)
python backlink_evaluator.py --input backlinks.txt --target-url "https://your-site.com"

# Custom outputs, extra niche keywords, more workers
python backlink_evaluator.py -i backlinks.txt -o report.html --csv-out out.csv --niche "fintech, saas" --workers 16

# Skip live fetching (offline — name / TLD scoring only)
python backlink_evaluator.py -i backlinks.txt --no-fetch
```

<details>
<summary><b>All CLI flags</b></summary>

<br>

| Flag | Default | Meaning |
|---|---|---|
| `--input, -i` | `backlinks.txt` | Candidate domains (.txt / .csv / .json) |
| `--output, -o` | `backlink_report.html` | Ranked HTML report |
| `--csv-out` | `prospects.csv` | Ranked prospects CSV |
| `--target-url` | your site | Your website (defines relevance) |
| `--niche` | *(generic seed)* | Extra niche keywords |
| `--workers` | `12` | Concurrent fetch workers |
| `--no-fetch` / `--no-verify-ssl` / `--limit N` | off / off / 0 | Offline mode / no SSL check / cap entries |

</details>

> The **Excel & Google-Disavow exports and the weekly Telegram monitor** are currently
> **web-app features** (the For Removal / Disavow and Backlink Notif tabs). Porting them
> to the CLI is planned.

---

## Versions

```mermaid
timeline
    title Release history
    v1 : Original scorer : Ranking : Avoid list : HTML report : PDF / CSV
    v2 (current) : Excel &amp; scoped exports : Google Disavow tab : Backlink Notif (weekly Telegram) : Login + encrypted per-browser cache : OOP multi-file architecture
```

- **v1** — the original scorer: ranking, Avoid list, HTML report, PDF / CSV.
- **v2 (current)** — adds Excel & scoped exports, Google Disavow tab, Backlink Notif
  (weekly Telegram alerts), login, encrypted per-browser cache & loading timer, and the
  object-oriented multi-file architecture.

See [Releases](../../releases) for downloadable packages.

---

## License

MIT — see [`LICENSE`](LICENSE). Use it freely; no warranty. Disavowing healthy links
can hurt your rankings — always review the generated disavow file before uploading.

<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=120&section=footer" alt="" />
</p>
