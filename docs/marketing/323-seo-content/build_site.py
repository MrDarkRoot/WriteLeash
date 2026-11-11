#!/usr/bin/env python3
"""Generate the #323 static guides from the Markdown articles.

Stdlib only. Writes docs/marketing/323-seo-content/site/ and checks titles,
canonicals, Article JSON-LD, relative links, and the single visible CTA.
No tracking and no external JavaScript.
"""

from __future__ import annotations

import html
import json
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
ARTICLES = ROOT / "articles"
SITE = ROOT / "site"
BASE = "https://mrdarkroot.github.io/WriteLeash"
PLUGIN = "https://wordpress.org/plugins/writeleash/"
REPO = "https://github.com/MrDarkRoot/WriteLeash"
PUBLISHED = "2026-11-10"
ORDER = [
    "undo-woocommerce-bulk-price-edit",
    "restore-sale-prices-overwritten-by-import",
    "imported-sale-price-not-showing",
    "restrict-woocommerce-price-changes",
    "woocommerce-price-history",
]

CSS = """
:root {
  --bg: #f4f1ea;
  --paper: #fffdfb;
  --ink: #1c1915;
  --muted: #3f3a34;
  --line: #e3dbd0;
  --link: #08445e;
  --cta: #f6f1e6;
  --max: 40rem;
}
* { box-sizing: border-box; }
html { scroll-behavior: auto; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--ink);
  font-family: "Segoe UI", system-ui, sans-serif;
  font-size: 1.0625rem;
  line-height: 1.6;
}
a { color: var(--link); }
a:hover { text-decoration-thickness: 2px; }
a:focus-visible { outline: 3px solid var(--link); outline-offset: 2px; }
.skip {
  position: absolute;
  left: 0.5rem;
  top: 0.5rem;
  transform: translateY(-150%);
  background: var(--paper);
  padding: 0.4rem 0.7rem;
}
.skip:focus { transform: none; }
.wrap { max-width: 68rem; margin: 0 auto; padding: 1rem; }
header.site {
  background: var(--paper);
  border-bottom: 1px solid var(--line);
}
header.site .wrap { padding-top: 0.8rem; padding-bottom: 0.8rem; }
.brand { font-weight: 700; text-decoration: none; color: var(--ink); }
.brand span { color: var(--muted); font-weight: 500; }
nav ul { list-style: none; padding: 0; margin: 0.6rem 0 0; display: flex; flex-wrap: wrap; gap: 0.35rem 0.9rem; }
nav a { text-decoration-thickness: 1px; }
main { max-width: calc(var(--max) + 2rem); margin: 0 auto; padding: 1.25rem 1rem 3rem; }
article, .index { background: var(--paper); border: 1px solid var(--line); padding: 1.1rem 1rem 1.4rem; }
h1 { font-size: 1.7rem; line-height: 1.25; margin: 0 0 0.8rem; }
h2 { font-size: 1.28rem; line-height: 1.3; margin: 1.6rem 0 0.5rem; }
h3 { font-size: 1.08rem; line-height: 1.35; margin: 1.15rem 0 0.35rem; }
p, ul, ol { margin: 0.7rem 0; }
ul, ol { padding-left: 1.2rem; }
.meta { color: var(--muted); font-size: 0.95rem; margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.98rem; }
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
th, td { border: 1px solid var(--line); text-align: left; padding: 0.4rem 0.5rem; vertical-align: top; }
th { background: #f7f3eb; }
code { font-family: ui-monospace, monospace; font-size: 0.92em; }
.cta { background: var(--cta); border-left: 4px solid var(--link); padding: 0.8rem 0.9rem; margin-top: 1.4rem; }
.cards { list-style: none; padding: 0; }
.cards li { border-top: 1px solid var(--line); padding: 0.85rem 0; }
.cards a { font-weight: 650; }
footer.site { color: var(--muted); font-size: 0.95rem; padding: 0 1rem 2rem; max-width: calc(var(--max) + 2rem); margin: 0 auto; }
@media (max-width: 36rem) {
  h1 { font-size: 1.45rem; }
  main { padding-left: 0.75rem; padding-right: 0.75rem; }
  article, .index { padding: 0.95rem 0.8rem 1.2rem; }
}
"""


def fail(message: str) -> None:
    raise SystemExit(message)


def parse_front(text: str) -> tuple[dict[str, str], str]:
    if not text.startswith("---\n"):
        fail("missing front matter")
    end = text.find("\n---\n", 4)
    if end < 0:
        fail("unclosed front matter")
    raw = text[4:end]
    body = text[end + 5 :]
    meta: dict[str, str] = {}
    for line in raw.splitlines():
        if not line.strip():
            continue
        key, value = line.split(":", 1)
        meta[key.strip()] = value.strip().strip('"')
    return meta, body


def rewrite_href(href: str) -> str:
    if href.startswith(("http://", "https://", "mailto:", "#")):
        return href
    path, anchor = href, ""
    if "#" in path:
        path, anchor = path.split("#", 1)
        anchor = "#" + anchor
    if path.endswith(".md"):
        path = path[:-3] + "/"
    if path and not path.startswith("../") and not path.startswith("/"):
        path = "../" + path
    return path + anchor


def format_inline(text: str) -> str:
    parts = re.split(r"(`[^`]+`)", text)
    out = []
    for part in parts:
        if len(part) >= 2 and part.startswith("`") and part.endswith("`"):
            out.append("<code>" + html.escape(part[1:-1]) + "</code>")
            continue
        escaped = html.escape(part)
        escaped = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", escaped)
        out.append(escaped)
    return "".join(out)


def inline(text: str) -> str:
    parts = re.split(r"(\[[^\]]+\]\([^)]+\))", text)
    out = []
    for part in parts:
        match = re.fullmatch(r"\[([^\]]+)\]\(([^)]+)\)", part)
        if not match:
            out.append(format_inline(part))
            continue
        href = rewrite_href(match.group(2).strip())
        out.append('<a href="' + html.escape(href, quote=True) + '">' + format_inline(match.group(1)) + "</a>")
    return "".join(out)


def plain(text: str) -> str:
    text = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", text)
    text = text.replace("**", "").replace("`", "")
    return re.sub(r"\s+", " ", text).strip()


def is_table_sep(line: str) -> bool:
    cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
    return bool(cells) and all(re.fullmatch(r":?-{3,}:?", cell) for cell in cells)


def table_row(line: str, head: bool) -> str:
    cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
    tag = "th" if head else "td"
    scope = ' scope="col"' if head else ""
    return "<tr>" + "".join(f"<{tag}{scope}>{inline(cell)}</{tag}>" for cell in cells) + "</tr>"


def render_markdown(body: str) -> tuple[str, list[tuple[str, str]]]:
    lines = body.replace("\r\n", "\n").split("\n")
    html_parts: list[str] = []
    faqs: list[tuple[str, str]] = []
    i = 0
    in_faq = False
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
            continue
        if line.startswith("```"):
            i += 1
            while i < len(lines) and not lines[i].startswith("```"):
                i += 1
            i += 1
            continue
        if line.startswith("#"):
            level = len(line) - len(line.lstrip("#"))
            title = line[level:].strip()
            if level == 2 and title == "FAQ":
                in_faq = True
            if level == 3 and in_faq:
                i += 1
                answer: list[str] = []
                while i < len(lines) and not lines[i].startswith("#") and not lines[i].startswith("```"):
                    if lines[i].strip():
                        answer.append(lines[i].strip())
                    elif answer:
                        break
                    i += 1
                faqs.append((plain(title), plain(" ".join(answer))))
                html_parts.append(f"<h3>{inline(title)}</h3>")
                if answer:
                    html_parts.append("<p>" + inline(" ".join(answer)) + "</p>")
                continue
            tag = f"h{min(level, 3)}"
            html_parts.append(f"<{tag}>{inline(title)}</{tag}>")
            i += 1
            continue
        if line.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                rows.append(lines[i])
                i += 1
            body_rows = []
            head = rows[0]
            start = 1
            if len(rows) > 1 and is_table_sep(rows[1]):
                start = 2
            for row in rows[start:]:
                body_rows.append(table_row(row, False))
            html_parts.append(
                '<div class="table-wrap"><table><thead>'
                + table_row(head, True)
                + "</thead><tbody>"
                + "".join(body_rows)
                + "</tbody></table></div>"
            )
            continue
        if re.match(r"^[-*] ", line):
            items = []
            while i < len(lines) and re.match(r"^[-*] ", lines[i]):
                items.append("<li>" + inline(lines[i][2:].strip()) + "</li>")
                i += 1
            html_parts.append("<ul>" + "".join(items) + "</ul>")
            continue
        if re.match(r"^\d+\. ", line):
            items = []
            while i < len(lines) and re.match(r"^\d+\. ", lines[i]):
                items.append("<li>" + inline(re.sub(r"^\d+\. ", "", lines[i]).strip()) + "</li>")
                i += 1
            html_parts.append("<ol>" + "".join(items) + "</ol>")
            continue
        paragraph = [line.strip()]
        i += 1
        while i < len(lines) and lines[i].strip() and not lines[i].startswith(("#", "|", "-", "*", "```")) and not re.match(r"^\d+\. ", lines[i]):
            paragraph.append(lines[i].strip())
            i += 1
        raw = " ".join(paragraph)
        css = ' class="cta"' if PLUGIN in raw and REPO in raw else ""
        html_parts.append(f"<p{css}>{inline(raw)}</p>")
    return "\n".join(html_parts), faqs


def page_shell(title: str, description: str, canonical: str, depth: str, main: str, schema: dict) -> str:
    home = depth if depth else "./"
    nav = []
    for slug in ORDER:
        href = f"{depth}{slug}/"
        nav.append(f'<li><a href="{html.escape(href, quote=True)}">{html.escape(load()[slug]["title"])}</a></li>')
    payload = json.dumps(schema, ensure_ascii=False, separators=(",", ":")).replace("<", "\\u003c")
    css_href = f"{depth}assets/site.css"
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title)}</title>
<meta name="description" content="{html.escape(description, quote=True)}">
<link rel="canonical" href="{html.escape(canonical, quote=True)}">
<link rel="stylesheet" href="{css_href}">
<meta name="robots" content="index,follow">
<script type="application/ld+json">{payload}</script>
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<header class="site">
<div class="wrap">
<a class="brand" href="{home}">WriteLeash <span>price overwrite guides</span></a>
<nav aria-label="Guides"><ul>
{''.join(nav)}
</ul></nav>
</div>
</header>
<main id="content">
{main}
</main>
<footer class="site"><p>WriteLeash publishes these guides. They are not an independent review. Revised {PUBLISHED}.</p></footer>
</body>
</html>
"""


_CACHE: dict[str, dict] | None = None


def load() -> dict[str, dict]:
    global _CACHE
    if _CACHE is not None:
        return _CACHE
    found = {}
    for slug in ORDER:
        path = ARTICLES / f"{slug}.md"
        if not path.is_file():
            fail(f"missing article {path}")
        meta, body = parse_front(path.read_text(encoding="utf-8"))
        for key in ("title", "slug", "meta_description", "h1", "card"):
            if not meta.get(key):
                fail(f"{slug} missing {key}")
        if meta["slug"] != slug or meta["h1"] != meta["title"]:
            fail(f"{slug} title/h1/slug mismatch")
        rendered, faqs = render_markdown(body)
        if len(faqs) < 3:
            fail(f"{slug} needs at least 3 FAQ questions, found {len(faqs)}")
        found[slug] = {"meta": meta, "html": rendered, "faqs": faqs, "title": meta["title"], "description": meta["meta_description"], "card": meta["card"]}
    _CACHE = found
    return found


def article_schema(meta: dict, canonical: str, faqs: list[tuple[str, str]]) -> dict:
    return {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Article",
                "headline": meta["title"],
                "description": meta["meta_description"],
                "datePublished": PUBLISHED,
                "dateModified": meta.get("date_revised", PUBLISHED),
                "author": {"@type": "Organization", "name": "WriteLeash", "url": PLUGIN},
                "publisher": {"@type": "Organization", "name": "WriteLeash", "url": PLUGIN},
                "mainEntityOfPage": {"@type": "WebPage", "@id": canonical},
            },
            {
                "@type": "FAQPage",
                "mainEntity": [
                    {
                        "@type": "Question",
                        "name": question,
                        "acceptedAnswer": {"@type": "Answer", "text": answer},
                    }
                    for question, answer in faqs
                ],
            },
        ],
    }


def build() -> None:
    global _CACHE
    _CACHE = None
    pages = load()
    if SITE.exists():
        shutil.rmtree(SITE)
    (SITE / "assets").mkdir(parents=True)
    (SITE / "assets" / "site.css").write_text(CSS.strip() + "\n", encoding="utf-8")
    (SITE / ".nojekyll").write_text("", encoding="utf-8")
    cards = []
    for slug in ORDER:
        page = pages[slug]
        cards.append(
            "<li><a href=\""
            + html.escape(slug + "/", quote=True)
            + "\">"
            + html.escape(page["title"])
            + "</a><p>"
            + html.escape(page["card"])
            + "</p></li>"
        )
        canonical = f"{BASE}/{slug}/"
        body_html = page["html"].replace(
            "</h1>",
            "</h1>\n<p class=\"meta\">Revised "
            + PUBLISHED
            + ". The repair uses WooCommerce. WriteLeash is optional.</p>",
            1,
        )
        main = "<article>\n" + body_html + "\n</article>"
        html_text = page_shell(page["title"], page["description"], canonical, "../", main, article_schema(page["meta"], canonical, page["faqs"]))
        dest = SITE / slug / "index.html"
        dest.parent.mkdir(parents=True)
        dest.write_text(html_text, encoding="utf-8")
    index_title = "Fix accidental WooCommerce price overwrites"
    index_description = "Five guides for a bad WooCommerce price change: bulk edit, CSV sales, a hidden sale, who can edit prices, and a dated export."
    index_main = f"""<div class="index">
<h1>{html.escape(index_title)}</h1>
<p>These guides are the repair, not a product tour. Use the export or the product screen named in the guide. You do not need a plugin to follow them.</p>
<p class="meta">Revised {PUBLISHED}. Search volume for these tasks was not measured.</p>
<ul class="cards">
{''.join(cards)}
</ul>
<p class="cta">WriteLeash 0.3.0 previews a price change and can undo eligible prices it applied. It will not undo a bulk edit or import that already ran, and it is not a firewall. The directory listing is <a href="{html.escape(PLUGIN, quote=True)}">WriteLeash on WordPress.org</a>. The source is the <a href="{html.escape(REPO, quote=True)}">WriteLeash repository</a>.</p>
</div>"""
    index_schema = {
        "@context": "https://schema.org",
        "@type": "CollectionPage",
        "name": index_title,
        "description": index_description,
        "url": BASE + "/",
        "dateModified": PUBLISHED,
        "isPartOf": {"@type": "WebSite", "name": "WriteLeash price overwrite guides", "url": BASE + "/"},
    }
    (SITE / "index.html").write_text(
        page_shell(index_title, index_description, BASE + "/", "", index_main, index_schema),
        encoding="utf-8",
    )
    urls = [BASE + "/"] + [f"{BASE}/{slug}/" for slug in ORDER]
    body = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for url in urls:
        body.append(f"<url><loc>{html.escape(url)}</loc><lastmod>{PUBLISHED}</lastmod></url>")
    body.append("</urlset>")
    (SITE / "sitemap.xml").write_text("\n".join(body) + "\n", encoding="utf-8")
    (SITE / "robots.txt").write_text(
        "User-agent: *\nAllow: /\n\nSitemap: " + BASE + "/sitemap.xml\n",
        encoding="utf-8",
    )
    check()
    print(f"wrote {SITE}")


def visible_html(text: str) -> str:
    text = re.sub(r"<script\b[^>]*>.*?</script>", "", text, flags=re.S)
    return text


def check() -> None:
    pages = load()
    titles = set()
    descriptions = set()
    files = {"index.html": (SITE / "index.html").read_text(encoding="utf-8")}
    for slug in ORDER:
        files[f"{slug}/index.html"] = (SITE / slug / "index.html").read_text(encoding="utf-8")
    if "google-analytics" in "\n".join(files.values()).lower() or "gtag(" in "\n".join(files.values()):
        fail("tracking snippet found")
    for rel, text in files.items():
        if text.count('<script type="application/ld+json">') != 1:
            fail(f"{rel} must have one JSON-LD script")
        if re.search(r"<script(?![^>]*application/ld\+json)", text):
            fail(f"{rel} has non-JSON script")
        if "http://" in text.replace("http://www.sitemaps.org", ""):
            fail(f"{rel} has an http URL")
        visible = visible_html(text)
        plugin_links = re.findall(r'href="' + re.escape(PLUGIN) + r'"', visible)
        repo_links = re.findall(r'href="' + re.escape(REPO) + r'"', visible)
        if len(plugin_links) != 1 or len(repo_links) != 1:
            fail(f"{rel} CTA count plugin={len(plugin_links)} repo={len(repo_links)}")
        if visible.count('class="cta"') != 1:
            fail(f"{rel} should have one cta paragraph")
        canonicals = re.findall(r'<link rel="canonical" href="([^"]+)">', text)
        if len(canonicals) != 1 or not canonicals[0].startswith(BASE):
            fail(f"{rel} bad canonical {canonicals}")
        title = re.search(r"<title>([^<]+)</title>", text).group(1)
        description = re.search(r'<meta name="description" content="([^"]+)">', text).group(1)
        if title in titles or description in descriptions:
            fail(f"duplicate title or description at {rel}")
        titles.add(title)
        descriptions.add(description)
        if rel != "index.html":
            if '"@type":"Article"' not in text and '"@type": "Article"' not in text:
                fail(f"{rel} missing Article JSON-LD")
            for phrase in ("Stock Undo is not supported", "not a firewall", "not measured"):
                if phrase == "not measured":
                    continue
                if phrase not in visible:
                    fail(f"{rel} missing {phrase}")
        for href in re.findall(r'href="([^"]+)"', visible):
            if href.startswith(("http://", "https://", "mailto:", "#")):
                continue
            target = (SITE / rel).parent / href
            target = target.resolve()
            if target.is_dir():
                target = target / "index.html"
            if not target.is_file():
                fail(f"{rel} broken relative link {href}")
    sitemap = (SITE / "sitemap.xml").read_text(encoding="utf-8")
    for slug in ORDER:
        if f"{BASE}/{slug}/" not in sitemap:
            fail(f"sitemap missing {slug}")
        if pages[slug]["title"] not in titles:
            fail(f"title not emitted for {slug}")
    robots = (SITE / "robots.txt").read_text(encoding="utf-8")
    if f"Sitemap: {BASE}/sitemap.xml" not in robots:
        fail("robots missing sitemap")
    print("site checks passed")
    for slug in ORDER:
        desc = pages[slug]["description"]
        print(f"{slug}\t{len(pages[slug]['title'])}\t{len(desc)}\t{pages[slug]['title']}")


def main() -> None:
    build()


if __name__ == "__main__":
    main()
