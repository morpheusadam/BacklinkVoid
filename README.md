<div align="center">

# 🛡️ Backlink Void Checker

### Score backlink prospects 0–100, audit existing links for spam & toxicity, auto-generate a Google Disavow file, and get weekly Telegram alerts — a PHP web app + Python CLI with no database and no build step.

<p>
  <img src="https://img.shields.io/github/license/morpheusadam/backlinkvoidchecker?style=for-the-badge&color=4c1" alt="License" />
  <img src="https://img.shields.io/github/stars/morpheusadam/backlinkvoidchecker?style=for-the-badge&color=ffca28" alt="Stars" />
  <img src="https://img.shields.io/github/forks/morpheusadam/backlinkvoidchecker?style=for-the-badge&color=42a5f5" alt="Forks" />
  <img src="https://img.shields.io/github/last-commit/morpheusadam/backlinkvoidchecker?style=for-the-badge&color=8e44ad" alt="Last commit" />
  <img src="https://img.shields.io/github/repo-size/morpheusadam/backlinkvoidchecker?style=for-the-badge&color=e67e22" alt="Repo size" />
</p>

<p>
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/Python-3.8%2B-3776AB?style=for-the-badge&logo=python&logoColor=white" alt="Python" />
  <img src="https://img.shields.io/badge/Database-None-2EA44F?style=for-the-badge&logo=sqlite&logoColor=white" alt="No database" />
  <img src="https://img.shields.io/badge/Build-None-2EA44F?style=for-the-badge&logo=gnubash&logoColor=white" alt="No build step" />
  <img src="https://img.shields.io/badge/Telegram-Alerts-26A5E4?style=for-the-badge&logo=telegram&logoColor=white" alt="Telegram alerts" />
</p>

</div>

---

## 📖 Overview

**Backlink Void Checker** is an **SEO backlink auditing tool** that ranks every domain in your link list **0–100** by how valuable a backlink from it would really be for *your* site — weighing relevance, authority, guest-post friendliness, domain health, TLD / language / geo fit, and spam-safety. The unusable sources (piracy / adult / gambling, dead, parked, de-indexed) are pushed into an **Avoid** list, and the genuinely spam / toxic ones are compiled into a **ready-to-upload Google Disavow file** for Search Console.

It is built for **SEO specialists, link builders, bloggers, and agencies** who want a transparent, no-API way to qualify outreach prospects and protect a site from toxic links. The tool runs as a **PHP web app** for cPanel / shared hosting (browser UI, no command line) *and* as a **dependency-free Python CLI** for automation and cron — with **no database and no build step** in either edition.

A built-in **Backlink Notif** monitor watches your existing backlinks and sends a **weekly Telegram alert** whenever one of them turns spam / toxic, so link rot never goes unnoticed.

> 🔎 **Keywords:** backlink checker, backlink audit, SEO toxicity audit, link prospecting tool, Google Disavow file generator, toxic backlink detector, spam link checker, link building tool, PHP SEO tool, Python SEO CLI, Telegram backlink monitor.

---

## ✨ Features

- 🎯 **Prospect scoring** — six weighted factors with a transparent per-row "why" breakdown for every domain.
- 🚫 **Avoid list** — toxic neighborhoods plus dead / parked / de-indexed pages are auto-excluded from your prospects.
- 📄 **Google Disavow tab** — one click downloads `disavow.txt` (`domain:` lines, with the matched signal as a comment). Conservative by design: only genuinely spam / toxic domains are listed — never healthy links.
- 📊 **Exports** — PDF, **Excel** (`.xls`), and CSV, scoped to *all results* or *guest-post-only*.
- 🔔 **Backlink Notif** — paste your live backlinks plus a Telegram bot token and get a weekly DM listing any newly spam / toxic domains (runs for 1 year per start, via cron).
- 🔐 **Login gate** — username / password, asked **once per browser** then remembered ~1 year via a signed cookie.
- ♻️ **Refresh-safe encrypted cache** — large lists are analyzed once; a refresh re-serves an **encrypted, per-browser** cached report instead of re-running, with a live loading timer.
- 🛡️ **Privacy first** — all at-rest data (monitor settings, cache) is **AES-256-CBC encrypted with HMAC authentication**; the Telegram token and chat id are never rendered to the page.
- 🪶 **Zero dependencies / no database** — the Python CLI is pure standard library; the PHP app only needs cURL + OpenSSL. No build step in either edition.

---

## 🛠️ Tech Stack

| Layer | Technology |
| --- | --- |
| Web app | **PHP 7.4+** (object-oriented, one class per file) — cURL + OpenSSL |
| CLI | **Python 3.8+** (standard library only) |
| Crypto | **AES-256-CBC**, encrypt-then-HMAC, signed login cookie |
| Storage | **None** — flat encrypted files for monitor state & cache |
| Alerts | **Telegram Bot API** (weekly, via cron) |
| Output | HTML report · **PDF · Excel (.xls) · CSV** · `disavow.txt` |

<p>
  <img src="https://skillicons.dev/icons?i=php,python,bash" alt="Tech stack" />
</p>

---

## 🚀 Getting Started

### Web app (cPanel / shared hosting)

**Prerequisites:** PHP **7.4+** with the **cURL** and **OpenSSL** extensions (standard on cPanel). `mbstring` is used if present but not required.

1. In cPanel **File Manager**, upload the whole project into a folder under `public_html` (e.g. `public_html/backlink/`) so `index.php`, `config.php`, the `src/` folder and `.htaccess` are all there.
2. Open it in a browser: `https://yourdomain.com/backlink/`.
3. Sign in (default `admin` / `adminA` — **change these**, see Configuration).
4. Enter your site URL, paste candidate domains (one per line; optional `domain,DR,spam`) or upload a `.txt` / `.csv`, and click **Analyze & rank**.
5. Review the **Prospects** and **Google Disavow** tabs; export as PDF / Excel / CSV.

### Terminal version (Python CLI)

```bash
git clone https://github.com/morpheusadam/backlinkvoidchecker.git
cd backlinkvoidchecker/terminal-version

# Rank a list for your site (HTML report + CSV)
python backlink_evaluator.py --input backlinks.txt --target-url "https://your-site.com"

# Custom outputs, extra niche keywords, more workers
python backlink_evaluator.py -i backlinks.txt -o report.html --csv-out out.csv --niche "fintech, saas" --workers 16

# Skip live fetching (offline — name / TLD scoring only)
python backlink_evaluator.py -i backlinks.txt --no-fetch
```

> The Excel / Google-Disavow exports and the weekly Telegram monitor are currently **web-app features**; porting them to the CLI is planned. See [`terminal-version/README.md`](terminal-version/README.md) for all CLI flags.

---

## 📊 Scoring Weights

Every domain flows through the same pipeline: live mode fetches and profiles the page, offline mode scores from the name and TLD only. Weights normalise over whatever signals are available — **start outreach at the top of the list and prioritise rows tagged "guest post".**

| Factor | Weight | Meaning |
| --- | --: | --- |
| Relevance | 30 | Topic match to your site (a relevant link is worth the most) |
| Authority | 25 | Domain strength (real DR if provided, else a content proxy) |
| Domain health | 13 | Live, indexable, real content, not parked |
| Link-friendliness | 12 | Openly accepts guest posts → realistic to win |
| TLD / language / geo | 10 | Reputable TLD, language fit |
| Spam safety | 10 | NOT a PBN / toxic neighborhood (a bad source can hurt you) |

> ⚠️ **Authority is approximate in live mode.** Without a paid API the tool proxies authority from content / HTTPS / indexing. For an accurate ranking, feed a CSV with a `dr` / domain-rating column (Ahrefs / Moz / Semrush) — it is used automatically.

---

## ⚙️ Configuration

Everything you normally change lives in **`config.php`** (root):

| Setting | Purpose | Default |
| --- | --- | --- |
| `AUTH_USER` / `AUTH_PASS` | Login credentials (set both to `''` to disable) | `admin` / `adminA` |
| `ACCESS_PASSWORD` | Optional extra password on POST actions | `''` |
| `NOTIF_SECRET_KEY` | **At-rest encryption key — change to a long random string** | placeholder |
| `NOTIF_CRON_TOKEN` | Secret in the weekly cron URL — change to random | placeholder |
| `NOTIF_INTERVAL` | Weekly-check throttle | 7 days |
| `NOTIF_DURATION` | Monitor lifetime | 1 year |

> Keep real secrets out of git: create a **`config.local.php`** next to `config.php` with the same `define()` lines and your real values. It is loaded first and is git-ignored. **Change `admin` / `adminA` and `NOTIF_SECRET_KEY` before going live.**

**Weekly Telegram monitor (cron):**

```cron
0 9 * * 1 curl -fsS "https://YOURDOMAIN/backlink/index.php?cron=run&token=PUT-YOUR-CRON-TOKEN-HERE" >/dev/null 2>&1
```

The endpoint self-throttles to once / 7 days and only alerts about **newly** spam / toxic domains.

---

## 🗂️ Project Structure

```text
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

## 🤝 Contributing

Contributions are welcome! Open an [issue](https://github.com/morpheusadam/backlinkvoidchecker/issues) or submit a pull request with new scoring signals, export formats, or CLI feature parity.

## 📜 License

Distributed under the **MIT License**. See [`LICENSE`](LICENSE) for details. Disavowing healthy links can hurt your rankings — always review the generated disavow file before uploading.

---

<div align="center">

### 👤 Author — Morpheus Adam

Web developer & cheerful hacker · PHP · Laravel · Go

<p>
  <a href="https://github.com/morpheusadam"><img src="https://img.shields.io/badge/GitHub-morpheusadam-181717?style=for-the-badge&logo=github&logoColor=white" alt="GitHub" /></a>
  <a href="https://sam.zeonic.me"><img src="https://img.shields.io/badge/Website-sam.zeonic.me-4c1?style=for-the-badge&logo=googlechrome&logoColor=white" alt="Website" /></a>
  <a href="mailto:morpheusadam95@gmail.com"><img src="https://img.shields.io/badge/Email-Contact-D14836?style=for-the-badge&logo=gmail&logoColor=white" alt="Email" /></a>
</p>

⭐ **If this tool helped you clean up your backlink profile, consider giving it a star!** ⭐

</div>


---

## ⭐ Star History

<a href="https://star-history.com/#morpheusadam/backlinkvoidchecker&Date">
  <img src="https://api.star-history.com/svg?repos=morpheusadam/backlinkvoidchecker&type=Date" alt="backlinkvoidchecker — Star History Chart" width="70%" />
</a>

<div align="center">

### If this project helps you, please give it a ⭐

A star helps other developers discover **backlinkvoidchecker** and supports continued development.

</div>
