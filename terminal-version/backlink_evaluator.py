#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
backlink_evaluator.py  ―  Backlink PROSPECT scorer
==================================================

GOAL
----
You have a list of candidate domains and you have NOT acquired links from them
yet. This tool tells you which ones are the BEST places to get a backlink FROM,
ranked best-first, for YOUR website — checking every factor that matters.

It does this by:
  1. Fetching YOUR site (the target) and deriving its real topic profile from
     the domain name + page content.
  2. Fetching each candidate domain (live) and measuring all the signals.
  3. Scoring each candidate 0-100 on how good a backlink from it would be:
        relevance · authority · link-friendliness · health · TLD/lang/geo · safety
  4. Excluding domains you should NOT chase (piracy/adult/gambling, dead,
     parked, de-indexed) into a separate "avoid" list.
  5. Exporting a styled, ranked HTML report + a CSV.

A high-value prospect is one that is topically relevant to your site, strong and
trustworthy, alive and indexable, safe (not a PBN/toxic neighborhood), and ―
ideally ― visibly open to guest posts / contributions so outreach is realistic.

Standard library only is required. `requests` / `tldextract` are used if present.

USAGE
-----
    python backlink_evaluator.py --input backlinks.txt
    python backlink_evaluator.py --input backlinks.txt --target-url https://your-site.com
    python backlink_evaluator.py --help
"""

from __future__ import annotations

import argparse
import csv
import html
import json
import os
import re
import socket
import ssl
import sys
import time
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, field
from typing import Optional
from urllib.parse import urlparse

try:
    import requests  # type: ignore
    _HAS_REQUESTS = True
except Exception:  # pragma: no cover
    import urllib.request
    import urllib.error
    _HAS_REQUESTS = False

try:
    import tldextract  # type: ignore
    _HAS_TLDEXTRACT = True
except Exception:  # pragma: no cover
    _HAS_TLDEXTRACT = False


# =========================================================================== #
#                          CONFIGURATION  (edit me)                           #
# =========================================================================== #

# --- YOUR site & niche -------------------------------------------------------
# The tool fetches TARGET_URL and merges keywords from its content with the
# seed list below, so relevance reflects YOUR domain name + YOUR content.
TARGET_URL = "https://example.com/"
NICHE_KEYWORDS = [          # seed terms (always considered, even if site is down)
    "business", "technology", "marketing", "finance", "health",
    "lifestyle", "news", "education", "software", "design",
    "startup", "ecommerce", "travel", "food", "home",
]
RELEVANCE_SATURATION = 3.0  # ~this many strong niche hits == fully relevant

# --- Prospect scoring weights (how good is a backlink FROM this domain?) ------
# They need not sum to 100; the tool normalizes over the AVAILABLE factors.
WEIGHTS = {
    "relevance":         30,   # topical fit to your site (most important)
    "authority":         25,   # how strong/trusted the referring domain is
    "link_friendliness": 12,   # does it openly accept guest posts/contributions?
    "domain_health":     13,   # live, indexable, real content, not parked
    "tld_lang_geo":      10,   # reputable TLD, English, UK fit
    "spam_safety":       10,   # NOT a PBN / toxic neighborhood
}

# --- Hard exclusions (move to "avoid", never rank as a prospect) -------------
# Getting a link from these is useless or harmful.
EXCLUDE_TOXIC_NEIGHBORHOODS = True   # piracy / adult / gambling / pharma
EXCLUDE_DEAD = True                  # 404/410/5xx / DNS fail
EXCLUDE_PARKED = True                # parked / for-sale placeholder
EXCLUDE_NOINDEX = True               # de-indexed -> a link passes no value

# --- Live-fetch behavior -----------------------------------------------------
ENABLE_LIVE_FETCH = True
REQUEST_TIMEOUT = 12
MAX_WORKERS = 12
VERIFY_SSL = True
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)
MAX_HTML_BYTES = 1_500_000

# --- Spam-safety signals (a backlink from a spammy/PBN site can hurt you) -----
SPAM_SAFETY_CAP = 6.0       # spam points at/above this => safety 0
BAD_TLDS = {
    "top", "sbs", "xyz", "monster", "cfd", "buzz", "site", "online", "click",
    "work", "pro", "cloud", "icu", "tk", "ml", "ga", "cf", "gq", "link",
}
SPAMMY_NAME_SUBSTRINGS = [
    "backlink", "seo-tool", "seotool", "linkbuild", "link-build", "buylink",
    "buy-link", "guestpost-service", "cheapseo", "rankboost", "linkfarm",
]
TOXIC_NEIGHBORHOOD_PATTERNS = [
    r"stream(east|ing)?", r"movies?(da|flix|hub|joy)?", r"123movies", r"putlocker",
    r"watchfree", r"torrent", r"\bpirate", r"\bxxx\b", r"\bporn", r"sex(cam|chat)?",
    r"escort", r"casino", r"betting|\bbet\b|gambl", r"viagra|cialis|pharma",
    r"replica", r"crypto(pump|signals)", r"aiyifan", r"sfm-compile",
    r"internet-chicks", r"baddiehub",
]

# --- Link-friendliness markers (signs the site accepts contributions) --------
GUEST_POST_MARKERS = [
    "write for us", "write for us", "guest post", "guest posting",
    "guest author", "become a contributor", "contributor guidelines",
    "submit a post", "submit an article", "submit your", "contribute",
    "sponsored post", "sponsored content", "advertise with us",
    "publish with us", "add a guest post",
]
GUEST_POST_SLUGS = [
    "write-for-us", "guest-post", "guest-posting", "contribute", "submit-post",
    "submit-article", "advertise", "sponsored-post", "become-a-contributor",
]

# --- PBN naming-network detection -------------------------------------------
NETWORK_TOKENS = {
    "magazine", "mag", "news", "daily", "times", "journal", "post", "herald",
    "tribune", "gazette", "media", "digital", "today", "weekly", "report",
    "bulletin", "chronicle", "wire", "press",
}
PBN_CLUSTER_MIN_SIZE = 4

TWO_LEVEL_SUFFIXES = {
    "co.uk", "org.uk", "ac.uk", "gov.uk", "me.uk", "ltd.uk", "plc.uk", "net.uk",
    "com.au", "net.au", "org.au", "edu.au", "gov.au", "co.nz", "org.nz",
    "co.in", "net.in", "org.in", "com.br", "com.cn", "co.jp", "co.za",
}
TLD_SCORES = {
    "com": 1.0, "org": 0.95, "net": 0.9, "edu": 1.0, "gov": 1.0,
    "co.uk": 0.95, "uk": 0.95, "org.uk": 0.92, "ac.uk": 1.0, "gov.uk": 1.0,
    "io": 0.85, "co": 0.8, "us": 0.8, "ca": 0.85, "au": 0.85, "com.au": 0.85,
    "de": 0.8, "fr": 0.8, "es": 0.75, "it": 0.75, "nl": 0.8, "eu": 0.8,
    "info": 0.5, "biz": 0.45, "online": 0.4, "site": 0.4, "xyz": 0.35,
    "top": 0.25, "click": 0.2, "link": 0.25, "buzz": 0.25, "icu": 0.2,
}
NEUTRAL_TLD_SCORE = 0.65
PREFERRED_LANGS = {"en"}
PREFERRED_GEO_TLDS = {"uk", "co.uk", "org.uk"}   # a UK business prefers UK sites

PARKED_MARKERS = [
    "domain is for sale", "buy this domain", "this domain may be for sale",
    "is for sale", "parked domain", "parked free", "courtesy of godaddy",
    "sedoparking", "domain parking", "hugedomains", "available for purchase",
    "this website is for sale", "default web page", "apache2 ubuntu default",
    "welcome to nginx", "index of /",
]

# Stopwords for deriving YOUR site's topic profile from its content.
STOPWORDS = {
    "the", "and", "for", "are", "with", "you", "your", "our", "this", "that",
    "from", "have", "has", "was", "were", "will", "can", "all", "any", "but",
    "not", "out", "use", "get", "more", "about", "into", "they", "their",
    "what", "when", "where", "which", "who", "how", "why", "than", "then",
    "them", "these", "those", "here", "there", "also", "been", "being", "over",
    "home", "page", "menu", "search", "click", "read", "contact", "privacy",
    "policy", "terms", "cookies", "cookie", "rights", "reserved", "copyright",
    "website", "site", "services", "service", "company", "best", "top", "new",
    "https", "http", "www", "com", "uk",
    # generic service / geo filler that creates false relevance matches
    "call", "england", "south", "north", "east", "west", "area", "areas",
    "local", "near", "team", "years", "experience", "quality", "trusted",
    "professional", "free", "quote", "today", "need", "help", "work", "time",
    "made", "make", "well", "good", "great", "every", "first", "last",
}


# =========================================================================== #
#                              DATA STRUCTURES                                 #
# =========================================================================== #

@dataclass
class Backlink:
    raw: str
    source_url: str = ""
    registrable_domain: str = ""
    tld: str = ""

    # Optional metadata from a richer CSV/JSON --------------------------------
    dr: Optional[float] = None
    spam_score: Optional[float] = None       # external toxicity 0-100
    external_links_meta: Optional[int] = None

    # Live-fetched signals ----------------------------------------------------
    http_status: Optional[int] = None
    final_url: str = ""
    redirected: bool = False
    reachable: bool = False
    is_dead: bool = False
    indexable: bool = True
    parked: bool = False
    lang: str = ""
    title: str = ""
    meta_description: str = ""
    text_sample: str = ""
    text_word_count: int = 0
    outbound_links: int = 0
    internal_links: int = 0
    link_friendly: bool = False
    friendly_markers: list = field(default_factory=list)

    # Spam-safety -------------------------------------------------------------
    spam_points: int = 0
    spam_signals: list = field(default_factory=list)

    # Prospect verdict --------------------------------------------------------
    score: float = 0.0
    factors: list = field(default_factory=list)       # [(name, pts, max, note)]
    factor_values: dict = field(default_factory=dict)  # {name: 0..1}
    status: str = "prospect"                           # prospect | avoid
    avoid_reasons: list = field(default_factory=list)
    notes: list = field(default_factory=list)


# =========================================================================== #
#                          INPUT LOADING & CLEANING                           #
# =========================================================================== #

COLUMN_ALIASES = {
    "source_url": ["source", "source url", "source_url", "referring page url",
                   "url", "from", "page", "backlink", "referring url", "domain"],
    "dr":         ["dr", "domain rating", "domain_rating", "da",
                   "domain authority", "authority", "ascore", "page ascore",
                   "authority score"],
    "spam_score": ["spam score", "spam_score", "toxicity", "toxicity score"],
    "external_links_meta": ["external links", "external_links", "outbound",
                            "outbound links"],
}


def _match_column(header: str) -> Optional[str]:
    h = header.strip().lower()
    for canonical, aliases in COLUMN_ALIASES.items():
        if h in aliases:
            return canonical
    return None


def split_concatenated(raw: str) -> list[str]:
    raw = raw.strip()
    if not raw:
        return []
    parts = re.split(r"(?=https?://)", raw, flags=re.IGNORECASE)
    parts = [p.strip() for p in parts if p.strip()]
    return parts or [raw]


def get_registrable_domain(host: str) -> tuple[str, str]:
    host = host.lower().strip().strip(".")
    if not host:
        return "", ""
    if _HAS_TLDEXTRACT:
        ext = tldextract.extract(host)
        if ext.domain and ext.suffix:
            return f"{ext.domain}.{ext.suffix}", ext.suffix
        return host, ext.suffix or ""
    labels = host.split(".")
    if len(labels) >= 3 and ".".join(labels[-2:]) in TWO_LEVEL_SUFFIXES:
        return ".".join(labels[-3:]), ".".join(labels[-2:])
    if len(labels) >= 2:
        return ".".join(labels[-2:]), labels[-1]
    return host, ""


def normalize_url(raw: str, default_scheme: str = "https") -> Optional[str]:
    s = raw.strip().strip('"').strip("'").strip()
    if not s:
        return None
    if not re.match(r"^https?://", s, flags=re.IGNORECASE):
        s = f"{default_scheme}://{s}"
    try:
        p = urlparse(s)
    except Exception:
        return None
    host = (p.netloc or "").lower()
    if not host or "." not in host:
        return None
    if not re.match(r"^[a-z0-9.\-:]+$", host):
        return None
    return f"{p.scheme.lower()}://{host}{p.path or '/'}"


def load_backlinks(path: str) -> list[Backlink]:
    ext = os.path.splitext(path)[1].lower()
    rows: list[Backlink] = []
    if ext == ".json":
        with open(path, "r", encoding="utf-8-sig") as fh:
            data = json.load(fh)
        if isinstance(data, dict):
            data = data.get("backlinks") or data.get("data") or [data]
        for item in data:
            if isinstance(item, str):
                rows.extend(_rows_from_raw_line(item))
            elif isinstance(item, dict):
                bl = _backlink_from_mapping(item)
                if bl:
                    rows.append(bl)
    elif ext == ".csv":
        with open(path, "r", encoding="utf-8-sig", newline="") as fh:
            sample = fh.read(4096)
            fh.seek(0)
            has_header = csv.Sniffer().has_header(sample) if sample else False
            if has_header:
                reader = csv.DictReader(fh)
                mapping = {c: _match_column(c) for c in (reader.fieldnames or [])}
                for r in reader:
                    bl = _backlink_from_mapping(
                        {mapping.get(k) or k: v for k, v in r.items()})
                    if bl:
                        rows.append(bl)
            else:
                for line in fh:
                    rows.extend(_rows_from_raw_line(line.split(",")[0]))
    else:
        with open(path, "r", encoding="utf-8-sig") as fh:
            for line in fh:
                rows.extend(_rows_from_raw_line(line))
    return rows


def _rows_from_raw_line(line: str) -> list[Backlink]:
    out = []
    for piece in split_concatenated(line):
        norm = normalize_url(piece)
        bl = Backlink(raw=piece.strip())
        if norm:
            bl.source_url = norm
            bl.registrable_domain, bl.tld = get_registrable_domain(
                urlparse(norm).netloc)
        out.append(bl)
    return out


def _backlink_from_mapping(d: dict) -> Optional[Backlink]:
    src = d.get("source_url") or d.get("url") or ""
    norm = normalize_url(str(src)) if src else None
    bl = Backlink(raw=str(src))
    if norm:
        bl.source_url = norm
        bl.registrable_domain, bl.tld = get_registrable_domain(
            urlparse(norm).netloc)
    bl.dr = _to_float(d.get("dr"))
    bl.spam_score = _to_float(d.get("spam_score"))
    bl.external_links_meta = _to_int(d.get("external_links_meta"))
    return bl


def _to_float(v):
    if v is None or v == "":
        return None
    try:
        return float(str(v).replace("%", "").replace(",", "").strip())
    except ValueError:
        return None


def _to_int(v):
    f = _to_float(v)
    return int(f) if f is not None else None


# =========================================================================== #
#                              LIVE ENRICHMENT                                 #
# =========================================================================== #

_DEAD_STATUSES = {404, 410, 500, 502, 503, 504}


def fetch_html(url: str):
    headers = {
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9",
    }
    if _HAS_REQUESTS:
        try:
            resp = requests.get(url, headers=headers, timeout=REQUEST_TIMEOUT,
                                allow_redirects=True, verify=VERIFY_SSL,
                                stream=True)
            body = resp.raw.read(MAX_HTML_BYTES, decode_content=True) or b""
            return (resp.status_code, resp.url,
                    resp.headers.get("Content-Language", ""), body)
        except requests.exceptions.RequestException:
            return (None, url, "", b"")
    ctx = ssl.create_default_context()
    if not VERIFY_SSL:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    req = urllib.request.Request(url, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT,
                                    context=ctx) as resp:
            body = resp.read(MAX_HTML_BYTES)
            return (resp.getcode(), resp.geturl(),
                    resp.headers.get("Content-Language", ""), body)
    except urllib.error.HTTPError as e:
        return (e.code, url, "", b"")
    except (urllib.error.URLError, socket.timeout, ssl.SSLError, OSError):
        return (None, url, "", b"")


_TITLE_RE = re.compile(r"<title[^>]*>(.*?)</title>", re.I | re.S)
_META_DESC_RE = re.compile(
    r'<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']', re.I | re.S)
_META_ROBOTS_RE = re.compile(
    r'<meta[^>]+name=["\']robots["\'][^>]+content=["\'](.*?)["\']', re.I | re.S)
_HTML_LANG_RE = re.compile(r"<html[^>]*\blang=[\"']([a-zA-Z\-]+)", re.I)
_A_HREF_RE = re.compile(r'<a\b[^>]*\bhref=["\']([^"\']+)["\']', re.I)
_A_TEXT_RE = re.compile(r'<a\b[^>]*>(.*?)</a>', re.I | re.S)
_TAG_RE = re.compile(r"<[^>]+>")
_SCRIPT_STYLE_RE = re.compile(r"<(script|style)[^>]*>.*?</\1>", re.I | re.S)


def decode_body(body: bytes) -> str:
    for enc in ("utf-8", "latin-1"):
        try:
            return body.decode(enc, errors="ignore")
        except Exception:
            continue
    return ""


def extract_signals(bl, status, final_url, lang_header, body) -> None:
    bl.http_status = status
    bl.final_url = final_url or bl.source_url
    bl.reachable = status is not None
    bl.redirected = (bool(final_url) and urlparse(final_url).netloc.lower()
                     != urlparse(bl.source_url).netloc.lower())
    bl.is_dead = (status is None) or (status in _DEAD_STATUSES)
    if not body:
        return
    text_html = decode_body(body)

    m = _TITLE_RE.search(text_html)
    if m:
        bl.title = html.unescape(_TAG_RE.sub("", m.group(1))).strip()[:300]
    m = _META_DESC_RE.search(text_html)
    if m:
        bl.meta_description = html.unescape(m.group(1)).strip()[:500]
    m = _HTML_LANG_RE.search(text_html)
    if m:
        bl.lang = m.group(1).split("-")[0].lower()
    elif lang_header:
        bl.lang = lang_header.split(",")[0].split("-")[0].lower()
    m = _META_ROBOTS_RE.search(text_html)
    if m and "noindex" in m.group(1).lower():
        bl.indexable = False

    own = bl.registrable_domain
    out_links = internal = 0
    for href in _A_HREF_RE.findall(text_html):
        low = href.lower()
        if href.startswith("#") or low.startswith(("mailto:", "tel:", "javascript:")):
            continue
        if any(slug in low for slug in GUEST_POST_SLUGS):
            bl.link_friendly = True
        if href.startswith("http"):
            if own and own in urlparse(href).netloc.lower():
                internal += 1
            else:
                out_links += 1
        else:
            internal += 1
    bl.outbound_links = out_links
    bl.internal_links = internal

    visible = html.unescape(_TAG_RE.sub(" ", _SCRIPT_STYLE_RE.sub(" ", text_html)))
    bl.text_word_count = len(visible.split())
    bl.text_sample = " ".join(visible.split())[:6000].lower()

    # link-friendliness (guest post / contribute / write-for-us)
    hay = (bl.title + " " + bl.text_sample)
    for marker in GUEST_POST_MARKERS:
        if marker in hay:
            bl.link_friendly = True
            if marker not in bl.friendly_markers:
                bl.friendly_markers.append(marker)

    low = (bl.title + " " + visible[:4000]).lower()
    if any(marker in low for marker in PARKED_MARKERS):
        bl.parked = True
    if bl.text_word_count < 40 and ("sale" in low or "domain" in low):
        bl.parked = True


def enrich_all(backlinks, workers) -> None:
    targets = [bl for bl in backlinks if bl.source_url]
    total = len(targets)
    if not total:
        return
    done = 0
    print(f"[*] Fetching {total} candidate domains with {workers} workers ...",
          file=sys.stderr)

    def work(bl):
        status, final, lang, body = fetch_html(bl.source_url)
        extract_signals(bl, status, final, lang, body)
        return bl

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(work, bl): bl for bl in targets}
        for fut in as_completed(futures):
            try:
                fut.result()
            except Exception as exc:
                futures[fut].notes.append(f"fetch error: {exc}")
            done += 1
            if done % 10 == 0 or done == total:
                print(f"    {done}/{total} done", file=sys.stderr)


# =========================================================================== #
#                      YOUR SITE'S TOPIC PROFILE                               #
# =========================================================================== #

def top_keywords(text: str, n: int = 15) -> list[str]:
    words = re.findall(r"[a-z]{4,}", text.lower())
    counts = Counter(w for w in words if w not in STOPWORDS)
    return [w for w, _ in counts.most_common(n)]


def build_target_profile(url: str, seed: list[str]) -> list[str]:
    """Fetch YOUR site and merge content keywords with the seed niche list."""
    kws = list(seed)
    # add tokens from the domain name itself (e.g. my-tech-blog -> tech, blog)
    dom, _ = get_registrable_domain(urlparse(
        normalize_url(url) or url).netloc) if url else ("", "")
    kws += [t for t in re.findall(r"[a-z]{4,}", dom) if t not in STOPWORDS]
    if ENABLE_LIVE_FETCH and url:
        status, final, lang, body = fetch_html(normalize_url(url) or url)
        if body:
            tmp = Backlink(raw=url, source_url=normalize_url(url) or url)
            tmp.registrable_domain, tmp.tld = dom, ""
            extract_signals(tmp, status, final, lang, body)
            kws += top_keywords(
                f"{tmp.title} {tmp.meta_description} {tmp.text_sample}", n=15)
            print(f"[*] Target profile from your site '{dom}': "
                  f"{', '.join(top_keywords(tmp.text_sample, 8))} ...",
                  file=sys.stderr)
    # de-duplicate, keep order, drop empties
    return list(dict.fromkeys(k.lower().strip() for k in kws if k.strip()))


# =========================================================================== #
#                     NETWORK / PBN FOOTPRINT DETECTION                        #
# =========================================================================== #

def detect_pbn_clusters(backlinks) -> set:
    buckets = defaultdict(list)
    for bl in backlinks:
        if not bl.registrable_domain:
            continue
        sld = (bl.registrable_domain[: -(len(bl.tld) + 1)]
               if bl.tld else bl.registrable_domain).lower()
        for tok in NETWORK_TOKENS:
            if len(tok) >= 4 and tok in sld:
                buckets[(tok, bl.tld)].append(bl.registrable_domain)
    flagged = set()
    for members in buckets.values():
        if len(set(members)) >= PBN_CLUSTER_MIN_SIZE:
            flagged |= set(members)
    return flagged


# =========================================================================== #
#                       SPAM-SAFETY (avoid risky link sources)                #
# =========================================================================== #

def _is_toxic_neighborhood(bl) -> bool:
    dom = (bl.registrable_domain or bl.raw).lower()
    return any(re.search(p, dom, flags=re.IGNORECASE)
               for p in TOXIC_NEIGHBORHOOD_PATTERNS)


def compute_spam_points(bl, pbn_flagged) -> tuple[int, list]:
    pts, sig = 0, []
    if bl.dr is not None and bl.dr <= 0:
        pts += 2; sig.append("DR 0")
    ext = (bl.external_links_meta if bl.external_links_meta is not None
           else (bl.outbound_links or None))
    if ext and ext > 100:
        pts += 2; sig.append(f"{ext} outbound links (link-farm risk)")
    if bl.tld in BAD_TLDS:
        pts += 2; sig.append(f"suspicious TLD .{bl.tld}")
    name = (bl.registrable_domain or "").lower()
    if any(s in name for s in SPAMMY_NAME_SUBSTRINGS):
        pts += 2; sig.append("spammy domain name")
    if bl.registrable_domain in pbn_flagged:
        pts += 2; sig.append("PBN/link-network footprint")
    if bl.spam_score is not None and bl.spam_score >= 60:
        pts += 2; sig.append(f"toxicity {bl.spam_score:g}")
    return pts, sig


# =========================================================================== #
#                            PROSPECT SCORING                                  #
# =========================================================================== #

def _relevance_value(bl, niche):
    if not niche:
        return None
    strong = f"{bl.title} {bl.meta_description}".lower()
    domain = (bl.registrable_domain or "").lower()
    body = bl.text_sample or ""
    if not bl.reachable and not bl.title:
        return None
    hits = 0.0
    for kw in niche:
        k = kw.lower().strip()
        if not k:
            continue
        if k in strong:
            hits += 1.0
        elif k in domain:
            hits += 0.6
        elif k in body:
            hits += 0.4
    return min(1.0, hits / max(1.0, RELEVANCE_SATURATION))


def _authority_value(bl):
    if bl.dr is not None:
        return max(0.0, min(1.0, bl.dr / 100.0)), f"DR={bl.dr:g}"
    if not bl.reachable:
        return None, "unreachable"
    s = 0.0
    if bl.final_url.startswith("https://"):
        s += 0.2
    if bl.indexable:
        s += 0.2
    s += 0.6 * min(1.0, bl.text_word_count / 800.0)
    return min(1.0, s), f"~{bl.text_word_count}w content (proxy; DR needs API)"


def _health_value(bl):
    if not bl.reachable:
        return 0.0, "no response"
    s, notes = 1.0, []
    if bl.is_dead:
        s -= 0.7; notes.append("dead")
    if bl.parked:
        s -= 0.8; notes.append("parked")
    if not bl.indexable:
        s -= 0.4; notes.append("noindex")
    if bl.redirected:
        s -= 0.15; notes.append("redirected")
    return max(0.0, min(1.0, s)), ", ".join(notes) or "live & clean"


def _friendliness_value(bl):
    if bl.link_friendly:
        m = ", ".join(bl.friendly_markers[:2]) or "guest-post path found"
        return 1.0, f"accepts contributions ({m})"
    return 0.55, "no public guest-post path (direct outreach still possible)"


def _tld_lang_geo_value(bl):
    base = TLD_SCORES.get(bl.tld, NEUTRAL_TLD_SCORE)
    lang = 1.0 if bl.lang in PREFERRED_LANGS else (0.5 if bl.lang else 0.8)
    geo = 1.0 if bl.tld in PREFERRED_GEO_TLDS else 0.85
    return (min(1.0, 0.45 * base + 0.35 * lang + 0.20 * geo),
            f"tld .{bl.tld or '?'}, lang {bl.lang or '?'}")


def _spam_safety_value(bl):
    val = max(0.0, 1.0 - bl.spam_points / SPAM_SAFETY_CAP)
    note = "clean" if bl.spam_points == 0 else f"{bl.spam_points} risk signal(s)"
    return val, note


def score_prospect(bl, niche) -> None:
    auth_v, auth_n = _authority_value(bl)
    health_v, health_n = _health_value(bl)
    friend_v, friend_n = _friendliness_value(bl)
    tlg_v, tlg_n = _tld_lang_geo_value(bl)
    safe_v, safe_n = _spam_safety_value(bl)
    raw = [
        ("relevance", _relevance_value(bl, niche), "topical fit to your site"),
        ("authority", auth_v, auth_n),
        ("link_friendliness", friend_v, friend_n),
        ("domain_health", health_v, health_n),
        ("tld_lang_geo", tlg_v, tlg_n),
        ("spam_safety", safe_v, safe_n),
    ]
    available = sum(WEIGHTS[k] for k, v, _ in raw if v is not None)
    if available <= 0:
        bl.score = 0.0
        bl.factors = [("(no signals)", 0, 0, "nothing measurable")]
        return
    total, factors = 0.0, []
    for key, value, note in raw:
        if value is None:
            continue
        max_pts = WEIGHTS[key] / available * 100.0
        pts = value * max_pts
        total += pts
        factors.append((key, round(pts, 1), round(max_pts, 1), note))
        bl.factor_values[key] = round(value, 3)
    bl.score = round(total, 1)
    bl.factors = factors


def classify_prospect(bl) -> None:
    reasons = []
    if not bl.registrable_domain:
        reasons.append("malformed / unparseable entry")
    if EXCLUDE_TOXIC_NEIGHBORHOODS and _is_toxic_neighborhood(bl):
        reasons.append("toxic neighborhood (piracy/adult/gambling) — never link from here")
    if ENABLE_LIVE_FETCH and bl.source_url and bl.reachable:
        if EXCLUDE_DEAD and bl.is_dead:
            reasons.append("dead page (cannot place a link)")
        if EXCLUDE_PARKED and bl.parked:
            reasons.append("parked / for-sale placeholder")
        if EXCLUDE_NOINDEX and not bl.indexable:
            reasons.append("de-indexed (a link here passes no value)")
    elif ENABLE_LIVE_FETCH and bl.source_url and not bl.reachable:
        reasons.append("unreachable (no response)")
    bl.avoid_reasons = reasons
    bl.status = "avoid" if reasons else "prospect"


def dedupe_domains(backlinks) -> list:
    seen, out = set(), []
    for bl in backlinks:
        d = bl.registrable_domain
        if d:
            if d in seen:
                continue
            seen.add(d)
        out.append(bl)
    return out


# =========================================================================== #
#                               HTML REPORT                                    #
# =========================================================================== #

def _why(bl) -> str:
    top = sorted(bl.factors, key=lambda f: f[1], reverse=True)[:3]
    parts = [f"{n.replace('_', ' ')} {p:g}/{m:g}" for n, p, m, _ in top]
    return " · ".join(parts)


def build_html_report(prospects, avoid, meta) -> str:
    avg = (sum(b.score for b in prospects) / len(prospects)) if prospects else 0.0
    friendly_n = sum(1 for b in prospects if b.link_friendly)

    rows = []
    for i, bl in enumerate(prospects, 1):
        url = html.escape(bl.final_url or bl.source_url)
        dom = html.escape(bl.registrable_domain or bl.source_url)
        title = html.escape(bl.title or "")
        rel = round(bl.factor_values.get("relevance", 0) * 100)
        auth = round(bl.factor_values.get("authority", 0) * 100)
        badge = "good" if bl.score >= 70 else "ok" if bl.score >= 50 else "warn"
        friendly = ('<span class="tag yes">guest&nbsp;post</span>'
                    if bl.link_friendly else '<span class="tag no">—</span>')
        rows.append(f"""
        <tr>
          <td class="rank">{i}</td>
          <td class="src"><a href="{url}" target="_blank" rel="noopener nofollow">{dom}</a>
            <div class="muted small">{title}</div></td>
          <td data-sort="{bl.score}"><span class="score {badge}">{bl.score:g}</span></td>
          <td data-sort="{rel}">{rel}%</td>
          <td data-sort="{auth}">{auth}%</td>
          <td>{friendly}</td>
          <td class="small muted">{html.escape(_why(bl))}</td>
        </tr>""")
    prospect_rows = "\n".join(rows) or (
        '<tr><td colspan="7" class="muted" style="padding:1.5rem;text-align:center">'
        'No suitable prospects found.</td></tr>')

    avoid_rows = []
    for bl in sorted(avoid, key=lambda b: b.registrable_domain):
        dom = html.escape(bl.registrable_domain or bl.raw)
        why = html.escape("; ".join(bl.avoid_reasons))
        avoid_rows.append(
            f'<tr><td>{dom}</td><td class="small muted">{why}</td></tr>')
    avoid_table = "\n".join(avoid_rows) or (
        '<tr><td colspan="2" class="muted" style="padding:1rem;text-align:center">'
        'Nothing excluded.</td></tr>')

    generated = meta.get("generated_at", "")
    target = html.escape(meta.get("target_url", "") or "—")
    profile = html.escape(", ".join(meta.get("profile", [])[:18]) or "—")

    # Extra CSS: the PDF button + a classic print stylesheet (the fallback path).
    # Kept as a plain string (single braces) and injected, so it doesn't collide
    # with the f-string's brace-doubling.
    print_css = """
  .pdfbtn { background:#38bdf8; color:#06121f; border:0; border-radius:10px;
            padding:.85rem 1.7rem; font-size:1.02rem; font-weight:700;
            cursor:pointer; box-shadow:0 4px 14px rgba(56,189,248,.25); }
  .pdfbtn:hover { filter:brightness(1.08); }
  @media print {
    body { background:#fff !important; color:#111 !important;
           font-family:Georgia,'Times New Roman',serif; }
    .wrap { max-width:none; padding:0; }
    header h1, h2 { color:#111 !important; }
    h2.danger { color:#8a1f1f !important; }
    header p, .muted, .small { color:#333 !important; }
    .method { background:#fff !important; border:1px solid #ccc; color:#111; }
    .method h3 { color:#111 !important; }
    .cards div { background:#fff !important; border:1px solid #bbb; }
    .cards .n { color:#111 !important; }
    table { background:#fff !important; border:1px solid #999; }
    th { background:#eee !important; color:#111 !important; }
    td, th { border-bottom:1px solid #ccc; }
    td.src a, td a { color:#111 !important; text-decoration:none; }
    .score { color:#111 !important; background:#eee !important; border:1px solid #999; }
    .tag.yes { background:#ddd !important; color:#111 !important; }
    .tag.no { background:#fff !important; }
    #pdfbar, .pdfbtn, button { display:none !important; }
  }
"""

    # Client-side PDF builder (jsPDF + autoTable). Plain string, injected below.
    pdf_script = """
function pdfText(el){ return el ? el.innerText.trim() : ''; }
function generatePDF(){
  if(!(window.jspdf && window.jspdf.jsPDF)){ window.print(); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({unit:'pt', format:'a4'});
  const W = doc.internal.pageSize.getWidth();
  const H = doc.internal.pageSize.getHeight();
  const M = 40;
  const site = pdfText(document.querySelector('header strong'));
  const gen  = pdfText(document.getElementById('gen'));
  const cards = Array.prototype.map.call(document.querySelectorAll('.cards .n'), function(e){return e.innerText.trim();});
  const summary = 'Domains checked: '+(cards[0]||'')+'    Good prospects: '+(cards[1]||'')+
                  '    Guest-post friendly: '+(cards[2]||'')+'    Avg score: '+(cards[3]||'')+
                  '    Avoid: '+(cards[4]||'');
  function footer(){
    doc.setFont('times','italic'); doc.setFontSize(8); doc.setTextColor(140);
    doc.text('Backlink Prospect Report - '+site, M, H-18);
    doc.text('Page '+doc.internal.getNumberOfPages(), W-M, H-18, {align:'right'});
  }
  doc.setFont('times','bold'); doc.setFontSize(22); doc.setTextColor(20);
  doc.text('Backlink Prospect Report', M, 56);
  doc.setDrawColor(30); doc.setLineWidth(1.2); doc.line(M, 66, W-M, 66);
  doc.setFont('times','normal'); doc.setFontSize(10); doc.setTextColor(90);
  doc.text('Your site: '+site, M, 86);
  doc.text(gen, M, 100);
  doc.setFontSize(9); doc.text(summary, M, 116);

  const prows = Array.prototype.filter.call(document.querySelectorAll('#t tbody tr'), function(r){return r.children.length===7;})
    .map(function(tr){
      const c = tr.children;
      const a = c[1].querySelector('a');
      const domain = (a ? a.innerText : c[1].innerText).trim();
      const outreach = c[5].innerText.trim()==='\\u2014' ? '' : 'Guest post';
      return [c[0].innerText.trim(), domain, c[2].innerText.trim(),
              c[3].innerText.trim(), c[4].innerText.trim(), outreach, c[6].innerText.trim()];
    });
  doc.autoTable({
    startY: 130,
    head: [['#','Domain','Score','Rel %','Auth %','Outreach','Why (top factors)']],
    body: prows,
    theme: 'grid',
    styles: {font:'times', fontSize:9, textColor:[33,33,33], cellPadding:4, overflow:'linebreak', lineColor:[205,205,205], lineWidth:0.5},
    headStyles: {fillColor:[20,30,55], textColor:255, fontStyle:'bold', halign:'left'},
    alternateRowStyles: {fillColor:[246,248,250]},
    columnStyles: {0:{cellWidth:26, halign:'center'}, 1:{cellWidth:120},
                   2:{cellWidth:38, halign:'center'}, 3:{cellWidth:40, halign:'center'},
                   4:{cellWidth:42, halign:'center'}, 5:{cellWidth:56}, 6:{cellWidth:'auto'}},
    margin: {left:M, right:M, bottom:34},
    didDrawPage: footer
  });
  const arows = Array.prototype.filter.call(document.querySelectorAll('#avoid tbody tr'), function(r){return r.children.length===2;})
    .map(function(tr){ return [tr.children[0].innerText.trim(), tr.children[1].innerText.trim()]; });
  if(arows.length){
    let y = doc.lastAutoTable.finalY + 26;
    if(y > H-90){ doc.addPage(); y=56; }
    doc.setFont('times','bold'); doc.setFontSize(14); doc.setTextColor(150,40,40);
    doc.text('Avoid - unsafe or unusable link sources', M, y);
    doc.autoTable({
      startY: y+10,
      head: [['Domain','Reason']],
      body: arows,
      theme: 'grid',
      styles: {font:'times', fontSize:9, textColor:[33,33,33], cellPadding:4, overflow:'linebreak', lineColor:[205,205,205], lineWidth:0.5},
      headStyles: {fillColor:[120,30,30], textColor:255, fontStyle:'bold'},
      columnStyles: {0:{cellWidth:175}, 1:{cellWidth:'auto'}},
      margin: {left:M, right:M, bottom:34},
      didDrawPage: footer
    });
  }
  doc.save('backlink-prospects.pdf');
}
"""

    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Backlink Prospect Report</title>
<style>
  :root {{
    --bg:#0f172a; --card:#1e293b; --line:#334155; --txt:#e2e8f0; --muted:#94a3b8;
    --accent:#38bdf8; --good:#22c55e; --ok:#eab308; --warn:#f97316; --danger:#ef4444;
  }}
  * {{ box-sizing:border-box; }}
  body {{ margin:0; background:var(--bg); color:var(--txt);
         font:15px/1.55 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }}
  .wrap {{ max-width:1200px; margin:0 auto; padding:2rem 1.25rem 4rem; }}
  header h1 {{ margin:0 0 .25rem; font-size:1.7rem; }}
  header p {{ margin:.1rem 0; color:var(--muted); }}
  h2 {{ font-size:1.15rem; margin:2rem 0 .3rem; }}
  h2.danger {{ color:var(--danger); }}
  .method {{ background:linear-gradient(135deg,#16263f,#1e293b);
             border:1px solid var(--line); border-radius:14px;
             padding:1.1rem 1.35rem; margin:1.4rem 0; }}
  .method h3 {{ margin:0 0 .5rem; color:var(--accent); font-size:1.05rem; }}
  .method ol {{ margin:.4rem 0 .3rem 1.2rem; }} .method li {{ margin:.25rem 0; }}
  .cards {{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:1rem; margin:1.5rem 0; }}
  .cards div {{ background:var(--card); border:1px solid var(--line);
                border-radius:14px; padding:1rem 1.2rem; }}
  .cards .n {{ font-size:1.8rem; font-weight:700; }}
  .cards .l {{ color:var(--muted); font-size:.8rem; text-transform:uppercase;
               letter-spacing:.04em; }}
  table {{ width:100%; border-collapse:collapse; background:var(--card);
           border:1px solid var(--line); border-radius:14px; overflow:hidden;
           margin-top:.4rem; }}
  th,td {{ padding:.65rem .8rem; text-align:left; border-bottom:1px solid var(--line);
           vertical-align:top; }}
  th {{ background:#172033; cursor:pointer; font-size:.8rem; text-transform:uppercase;
        letter-spacing:.03em; }}
  th:hover {{ color:var(--accent); }}
  tr:last-child td {{ border-bottom:none; }}
  td.rank {{ font-weight:700; color:var(--muted); width:3rem; }}
  td.src a {{ color:var(--accent); text-decoration:none; font-weight:600;
              word-break:break-all; }}
  td.src a:hover {{ text-decoration:underline; }}
  .muted {{ color:var(--muted); }} .small {{ font-size:.83rem; }}
  .score {{ display:inline-block; min-width:2.4rem; text-align:center;
            padding:.22rem .55rem; border-radius:999px; font-weight:700; color:#06121f; }}
  .score.good {{ background:var(--good); }} .score.ok {{ background:var(--ok); }}
  .score.warn {{ background:var(--warn); }}
  .tag {{ display:inline-block; padding:.15rem .5rem; border-radius:999px;
          font-size:.75rem; font-weight:700; }}
  .tag.yes {{ background:var(--good); color:#06121f; }}
  .tag.no {{ background:#26344a; color:var(--muted); }}
  footer {{ margin-top:2rem; color:var(--muted); font-size:.83rem; }}
{print_css}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>🎯 Backlink Prospect Report</h1>
    <p>Your site: <strong>{target}</strong></p>
    <p class="small">Topic profile (from your domain + content): {profile}</p>
    <p class="small" id="gen">Generated {generated}</p>
  </header>

  <section class="method">
    <h3>🥇 How prospects are ranked (best place to GET a link from)</h3>
    <ol>
      <li><strong>Relevance</strong> (weight {WEIGHTS['relevance']}) — how well the
          site's topic matches yours. A relevant link is worth the most.</li>
      <li><strong>Authority</strong> (weight {WEIGHTS['authority']}) — how strong &
          trusted the domain is (real DR if you supply it, else a content proxy).</li>
      <li><strong>Link-friendliness</strong> (weight {WEIGHTS['link_friendliness']}) —
          does it openly accept guest posts / contributions? Easier to actually win.</li>
      <li><strong>Health</strong> (weight {WEIGHTS['domain_health']}) — live, indexable,
          real content, not parked.</li>
      <li><strong>TLD / language / geo</strong> (weight {WEIGHTS['tld_lang_geo']}) —
          reputable TLD, English, UK fit.</li>
      <li><strong>Safety</strong> (weight {WEIGHTS['spam_safety']}) — NOT a PBN or toxic
          neighborhood (a link from a spammy site can hurt you).</li>
    </ol>
    <p class="small muted">Piracy/adult/gambling, dead, parked, and de-indexed
       domains are excluded into the "avoid" list below.</p>
  </section>

  <section class="cards">
    <div><div class="n">{meta['total']}</div><div class="l">Domains checked</div></div>
    <div><div class="n" style="color:var(--good)">{len(prospects)}</div><div class="l">Good prospects</div></div>
    <div><div class="n" style="color:var(--accent)">{friendly_n}</div><div class="l">Guest-post friendly</div></div>
    <div><div class="n">{avg:.1f}</div><div class="l">Avg score</div></div>
    <div><div class="n" style="color:var(--warn)">{len(avoid)}</div><div class="l">Avoid</div></div>
  </section>

  <h2>✅ Best prospects (ranked — start at the top)</h2>
  <table id="t">
    <thead><tr>
      <th onclick="sortT(0,'num')">#</th>
      <th onclick="sortT(1,'str')">Domain</th>
      <th onclick="sortT(2,'num')">Score ▾</th>
      <th onclick="sortT(3,'num')">Relevance</th>
      <th onclick="sortT(4,'num')">Authority</th>
      <th onclick="sortT(5,'str')">Outreach</th>
      <th onclick="sortT(6,'str')">Why</th>
    </tr></thead>
    <tbody>{prospect_rows}</tbody>
  </table>

  <h2 class="danger">⛔ Avoid ({len(avoid)})</h2>
  <p class="muted small">Unsafe or unusable as a link source.</p>
  <table id="avoid"><thead><tr><th>Domain</th><th>Reason</th></tr></thead>
    <tbody>{avoid_table}</tbody></table>

  <div id="pdfbar" style="text-align:center;margin:2.6rem 0 1rem">
    <button class="pdfbtn" onclick="generatePDF()">⬇&nbsp;&nbsp;Download PDF</button>
    <div class="muted small" style="margin-top:.55rem">
      Classic, paginated PDF with every prospect in ranked order (plus the avoid list).</div>
  </div>

  <footer>
    Weights: relevance {WEIGHTS['relevance']} · authority {WEIGHTS['authority']} ·
    friendliness {WEIGHTS['link_friendliness']} · health {WEIGHTS['domain_health']} ·
    tld/lang/geo {WEIGHTS['tld_lang_geo']} · safety {WEIGHTS['spam_safety']}
    (normalized over available signals). For true authority ranking, add a DR
    column from Ahrefs/Moz/Semrush.
  </footer>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
{pdf_script}
function sortT(col, type) {{
  const tb = document.querySelector('#t tbody');
  const rows = Array.from(tb.querySelectorAll('tr')).filter(r => r.children.length === 7);
  const dir = tb.getAttribute('data-dir')==='asc' ? -1 : 1;
  tb.setAttribute('data-dir', dir===1?'asc':'desc');
  rows.sort((a,b) => {{
    let x=a.children[col].getAttribute('data-sort')??a.children[col].innerText;
    let y=b.children[col].getAttribute('data-sort')??b.children[col].innerText;
    if(type==='num'){{return ((parseFloat(x)||0)-(parseFloat(y)||0))*dir;}}
    return x.localeCompare(y)*dir;
  }});
  rows.forEach(r => tb.appendChild(r));
}}
</script>
</body>
</html>"""


# =========================================================================== #
#                                   MAIN                                       #
# =========================================================================== #

def parse_args(argv=None):
    p = argparse.ArgumentParser(
        description="Rank candidate domains by how good a backlink FROM them "
                    "would be for your site.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    p.add_argument("--input", "-i", default="backlinks.txt",
                   help="Candidate domains (.txt one-per-line, .csv, or .json).")
    p.add_argument("--output", "-o", default="backlink_report.html",
                   help="Output HTML prospect report.")
    p.add_argument("--csv-out", default="prospects.csv",
                   help="Output ranked prospects CSV.")
    p.add_argument("--target-url", default=TARGET_URL,
                   help="YOUR website (its content defines relevance).")
    p.add_argument("--niche", default="",
                   help="Extra comma-separated niche keywords (optional).")
    p.add_argument("--workers", type=int, default=MAX_WORKERS)
    p.add_argument("--no-fetch", action="store_true",
                   help="Skip live HTTP enrichment.")
    p.add_argument("--no-verify-ssl", action="store_true")
    p.add_argument("--limit", type=int, default=0)
    return p.parse_args(argv)


def main(argv=None) -> int:
    global ENABLE_LIVE_FETCH, VERIFY_SSL

    args = parse_args(argv)
    if args.no_fetch:
        ENABLE_LIVE_FETCH = False
    if args.no_verify_ssl:
        VERIFY_SSL = False
    extra = [k.strip() for k in args.niche.split(",") if k.strip()]

    if not os.path.exists(args.input):
        print(f"[!] Input file not found: {args.input}", file=sys.stderr)
        return 2

    backlinks = load_backlinks(args.input)
    if args.limit:
        backlinks = backlinks[: args.limit]
    if not backlinks:
        print("[!] No candidate domains parsed from input.", file=sys.stderr)
        return 1
    print(f"[*] Loaded {len(backlinks)} candidate entries.", file=sys.stderr)

    # Build YOUR site's topic profile (domain name + live content + seeds).
    niche = build_target_profile(args.target_url, NICHE_KEYWORDS + extra)

    pbn_flagged = detect_pbn_clusters(backlinks)
    if pbn_flagged:
        print(f"[*] PBN/network footprint flagged {len(pbn_flagged)} domains.",
              file=sys.stderr)

    if ENABLE_LIVE_FETCH:
        enrich_all(backlinks, workers=args.workers)
    else:
        print("[*] Live fetch disabled; results limited.", file=sys.stderr)

    backlinks = dedupe_domains(backlinks)
    for bl in backlinks:
        bl.spam_points, bl.spam_signals = compute_spam_points(bl, pbn_flagged)
        score_prospect(bl, niche)
        classify_prospect(bl)

    prospects = sorted((b for b in backlinks if b.status == "prospect"),
                       key=lambda b: b.score, reverse=True)
    avoid = [b for b in backlinks if b.status == "avoid"]

    meta = {
        "total": len(backlinks),
        "target_url": args.target_url,
        "profile": niche,
        "generated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
    }
    with open(args.output, "w", encoding="utf-8") as fh:
        fh.write(build_html_report(prospects, avoid, meta))

    if args.csv_out:
        with open(args.csv_out, "w", encoding="utf-8", newline="") as fh:
            w = csv.writer(fh)
            w.writerow(["rank", "domain", "score", "relevance_%", "authority_%",
                        "guest_post_friendly", "spam_points", "url", "why"])
            for i, bl in enumerate(prospects, 1):
                w.writerow([
                    i, bl.registrable_domain, bl.score,
                    round(bl.factor_values.get("relevance", 0) * 100),
                    round(bl.factor_values.get("authority", 0) * 100),
                    "yes" if bl.link_friendly else "no",
                    bl.spam_points, bl.final_url or bl.source_url, _why(bl)])

    avg = (sum(b.score for b in prospects) / len(prospects)) if prospects else 0
    print("\n========== PROSPECT SUMMARY ==========", file=sys.stderr)
    print(f" Domains checked     : {len(backlinks)}", file=sys.stderr)
    print(f" Good prospects      : {len(prospects)}", file=sys.stderr)
    print(f" Guest-post friendly : "
          f"{sum(1 for b in prospects if b.link_friendly)}", file=sys.stderr)
    print(f" Avoid               : {len(avoid)}", file=sys.stderr)
    print(f" Average score       : {avg:.1f}", file=sys.stderr)
    print(f" Report / CSV        : {args.output} / {args.csv_out}", file=sys.stderr)
    if prospects:
        print("\n Top 12 prospects to pursue:", file=sys.stderr)
        for i, bl in enumerate(prospects[:12], 1):
            f = "  [guest-post]" if bl.link_friendly else ""
            print(f"  {i:>2}. {bl.registrable_domain:<32} {bl.score:>5g}{f}",
                  file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
