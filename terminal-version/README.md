# Backlink Void Checker — Terminal (CLI) edition

A standard-library Python scorer (no third-party dependencies). It fetches each
candidate domain, scores how good a backlink from it would be for your site, and
writes a ranked **HTML report** + a ranked **CSV**. Piracy/adult/gambling, dead,
parked and de-indexed domains are moved to a separate **Avoid** list.

> The Excel & Google-Disavow exports and the weekly Telegram monitor live in the
> web app (`../index.php`, the *For Removal / Disavow* and *Backlink Notif*
> tabs). Porting them here is planned.

## Requirements

Python **3.8+**. No `pip install` needed — it uses only the standard library
(`urllib`, `concurrent.futures`, `html.parser`, …).

## Usage

```bash
# Default: ranks backlinks.txt for the configured target site
python backlink_evaluator.py --input backlinks.txt

# Point it at your own site (its content defines relevance)
python backlink_evaluator.py -i backlinks.txt --target-url "https://your-site.com"

# Extra niche keywords, custom outputs, more workers
python backlink_evaluator.py -i backlinks.txt --niche "fintech, saas" -o report.html --csv-out out.csv --workers 16

# Offline mode (no live fetch — score on domain name / TLD only)
python backlink_evaluator.py -i backlinks.txt --no-fetch

# Feed real authority data (best): a CSV with a dr / domain-rating column
python backlink_evaluator.py -i ahrefs_export.csv
```

## Flags

| Flag | Default | Meaning |
|---|---|---|
| `--input, -i` | `backlinks.txt` | Candidate domains (`.txt` / `.csv` / `.json`) |
| `--output, -o` | `backlink_report.html` | Ranked HTML report |
| `--csv-out` | `prospects.csv` | Ranked prospects CSV |
| `--target-url` | configured site | Your website (defines relevance) |
| `--niche` | *(generic seed)* | Extra niche keywords (comma-separated) |
| `--workers` | `12` | Concurrent fetch workers |
| `--no-fetch` | off | Skip live enrichment (offline scoring) |
| `--no-verify-ssl` | off | Disable SSL verification |
| `--limit` | `0` | Process only the first N entries |

## Input format

One domain or URL per line. Optionally `domain,DR,spam` to supply a real Domain
Rating and toxicity score (used automatically when present). Lines starting with
`#` are ignored; duplicate registrable domains are de-duplicated.

## Notes

- **Authority is approximate in live mode.** Without a paid API the tool proxies
  authority from content/HTTPS/indexing. For an accurate ranking, feed a CSV with
  a `dr` / `domain rating` column.
- All weights, exclusions and patterns are constants at the top of
  `backlink_evaluator.py` — edit them to tune.
