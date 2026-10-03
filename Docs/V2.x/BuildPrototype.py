"""Refresh the standalone HTML prototype from the curated public catalog.

The prototype is a design review surface. The XAMPP application serves the
same catalog through SqueHub routes; it does not serve this directory.
"""

from __future__ import annotations

import argparse
import html
import json
import re
import shutil
from pathlib import Path


def esc(value: object) -> str:
    return html.escape(str(value), quote=True)


def prototype_url(version: str, slug: str | None = None) -> str:
    return f"docs/{version}/{slug + '.html' if slug else 'index.html'}"


def group_pages(pages: list[dict]) -> dict[str, list[dict]]:
    grouped: dict[str, list[dict]] = {}
    for page in pages:
        grouped.setdefault(page['category'], []).append(page)
    return grouped


def search_terms(value: str) -> str:
    """Index every article term once without shipping entire article bodies."""
    return ' '.join(dict.fromkeys(re.findall(r'[\w.-]+', value.casefold())))


LANDING_DISCOVERY = '''<!-- portal-home:discovery -->
        <section class="section-wrap ecosystem-showcase" aria-labelledby="ecosystem-title">
            <div class="section-heading">
                <div><span class="eyebrow">Explore the ecosystem</span>
                    <h2 id="ecosystem-title">More ways to build with SqueHub</h2>
                </div><p class="section-aside">Packages and Kits extend an application in different ways. These directories will list SqueHub releases when they are available.</p>
            </div>
            <div class="ecosystem-grid">
                <article class="ecosystem-card"><span class="ecosystem-number">01 / REUSABLE CAPABILITIES</span>
                    <h3>Packages</h3><p>Add focused services, routes, and views through a managed runtime capability.</p>
                    <a class="text-link" href="packages/index.html">Browse Packages <span aria-hidden="true">↗</span></a>
                </article>
                <article class="ecosystem-card"><span class="ecosystem-number">02 / APPLICATION SOLUTIONS</span>
                    <h3>Kits</h3><p>Compose Packages and application files into a reviewable starting point.</p>
                    <a class="text-link" href="kits/index.html">Browse Kits <span aria-hidden="true">↗</span></a>
                </article>
                <article class="ecosystem-card"><span class="ecosystem-number">03 / PORTABILITY</span>
                    <h3>Snapshots &amp; recovery</h3><p>Plan for source bundles, database snapshots, and persistent files as separate parts of recovery.</p>
                    <a class="text-link" href="docs/v2.x/recovery.html">Explore recovery <span aria-hidden="true">↗</span></a>
                </article>
            </div>
        </section>
        <section class="section-wrap example-showcase" aria-labelledby="example-title">
            <div class="section-heading"><div><span class="eyebrow">Planned SqueHub ecosystem</span>
                <h2 id="example-title">What’s taking shape</h2></div>
                <p class="section-aside">These official Package and Kit entries are planned. Neither has been released or is available to install.</p></div>
            <div class="example-grid">
                <article class="example-card"><div class="example-card-top"><span class="example-type">PACKAGE</span><span class="example-badge">Planned · Not released</span></div>
                    <h3>squehub/media</h3><p>Planned media workflows built on SqueHub Storage. Explore the concept and its documentation path.</p>
                    <a href="packages/media/index.html">Explore planned Package <span aria-hidden="true">↗</span></a></article>
                <article class="example-card"><div class="example-card-top"><span class="example-type">KIT</span><span class="example-badge">Planned · Not released</span></div>
                    <h3>squehub/app-starter</h3><p>A planned application starter that composes the existing Auth capability.</p>
                    <a href="kits/app-starter/index.html">Explore planned Kit <span aria-hidden="true">↗</span></a></article>
            </div>
        </section>
        <section class="section-wrap connect-showcase" aria-labelledby="connect-title">
            <div class="section-heading"><div><span class="eyebrow">Stay connected</span>
                <h2 id="connect-title">Follow the work as it develops</h2></div></div>
            <div class="connect-grid">
                <a href="community/index.html"><span>01 / COMMUNITY</span><strong>Find the conversation</strong><small>Ways to learn, contribute, and connect around SqueHub.</small><b aria-hidden="true">↗</b></a>
                <a href="changelogs/index.html"><span>02 / CHANGELOGS</span><strong>See what changed</strong><small>Development notes and release history as versions become available.</small><b aria-hidden="true">↗</b></a>
                <a href="contact/index.html"><span>03 / CONTACT</span><strong>Get in touch</strong><small>Find the right contact path for your question.</small><b aria-hidden="true">↗</b></a>
            </div>
        </section>
        <!-- /portal-home:discovery -->'''


def preview_page(prefix: str, title: str, eyebrow: str, intro: str,
                 content: str) -> str:
    """Keep static prototype catalog links useful during design review."""
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,follow"><title>{esc(title)} · SqueHub preview</title>
<link rel="stylesheet" href="{prefix}assets/css/site.css"><link rel="stylesheet" href="{prefix}assets/css/portal.css"></head>
<body class="preview-page"><a class="skip-link" href="#main">Skip to content</a>
<header class="site-header"><div class="header-inner"><a class="brand" href="{prefix}index.html">SqueHub<span class="brand-docs">/ Preview</span></a>
<nav class="preview-nav" aria-label="Primary navigation"><a href="{prefix}docs/v2.x/index.html">Docs</a><a href="{prefix}packages/index.html">Packages</a><a href="{prefix}kits/index.html">Kits</a><a href="{prefix}community/index.html">Community</a></nav></div></header>
<main id="main" class="preview-main"><span class="eyebrow">{esc(eyebrow)}</span><h1>{esc(title)}</h1><p class="preview-lead">{esc(intro)}</p>{content}</main>
<footer class="site-footer"><div><strong>SqueHub</strong><p>v2.0.0 development preview</p></div><nav aria-label="Footer navigation"><a href="{prefix}index.html">Home</a><a href="{prefix}changelogs/index.html">Changelogs</a><a href="{prefix}contact/index.html">Contact</a></nav></footer>
</body></html>\n'''


def build_preview_pages(prototype: Path) -> None:
    pages = {
        'packages/index.html': preview_page('../', 'Packages', 'Ecosystem / Packages',
            'A place for SqueHub Packages as they are released. The listed media Package is planned and not available to install.',
            '<div class="preview-box"><span class="example-badge">Planned · Not released</span><h2>squehub/media</h2><p>A planned Package for media workflows built on SqueHub Storage.</p><a class="text-link" href="media/index.html">Explore planned Package →</a></div><p class="preview-note">Learn how Packages work in the <a href="../docs/v2.x/packages.html">Packages guide</a>.</p>'),
        'packages/media/index.html': preview_page('../../', 'squehub/media', 'Ecosystem / Planned Package',
            'squehub/media is a planned official Package. It has not been released and is not available to install.',
            '<div class="preview-box"><span class="example-badge">Planned · Not released</span><h2>Overview</h2><p>Media workflows would build on SqueHub Storage. The exact feature set and distribution details await a release.</p><a class="text-link" href="../../docs/v2.x/storage.html">Read Storage documentation →</a></div><p class="preview-note"><a href="../index.html">← All Packages</a></p>'),
        'kits/index.html': preview_page('../', 'Kits', 'Ecosystem / Kits',
            'A place for SqueHub Kits as they are released. The listed application starter is planned and not available to install.',
            '<div class="preview-box"><span class="example-badge">Planned · Not released</span><h2>squehub/app-starter</h2><p>A planned Kit that composes the existing Auth capability into an application starter.</p><a class="text-link" href="app-starter/index.html">Explore planned Kit →</a></div><p class="preview-note">Learn how Kits work in the <a href="../docs/v2.x/kits.html">Kits guide</a>.</p>'),
        'kits/app-starter/index.html': preview_page('../../', 'squehub/app-starter', 'Ecosystem / Planned Kit',
            'squehub/app-starter is a planned official Kit. It has not been released and is not available to install.',
            '<div class="preview-box"><span class="example-badge">Planned · Not released</span><h2>Overview</h2><p>The application starter would compose SqueHub’s existing Auth capability. Its exact files and distribution details await a release.</p><a class="text-link" href="../../docs/v2.x/authentication.html">Read Auth documentation →</a></div><p class="preview-note"><a href="../index.html">← All Kits</a></p>'),
        'community/index.html': preview_page('../', 'Community', 'SqueHub / Community',
            'Follow SqueHub development, share ideas, and find contribution guidance.',
            '<div class="preview-box"><h2>Get involved</h2><p>Start with the <a href="../docs/v2.x/contributions.html">contribution guide</a> and the current <a href="../docs/v2.x/index.html">v2 development documentation</a>.</p></div>'),
        'changelogs/index.html': preview_page('../', 'Changelogs', 'SqueHub / Changelogs',
            'Track the framework as v2 work moves toward a qualified release.',
            '<div class="preview-box"><span class="example-badge">Development</span><h2>v2.x is in progress</h2><p>There is no published v2.0.0 release history to present yet. Read the <a href="../docs/v2.x/upgrade-from-v1.html">upgrade guide</a> for the current development changes.</p></div>'),
        'contact/index.html': preview_page('../', 'Contact', 'SqueHub / Contact',
            'Find the right channel for a SqueHub question or contribution.',
            '<div class="preview-box"><h2>Start with the documentation</h2><p>For a technical question, consult the <a href="../docs/v2.x/index.html">development documentation</a> and <a href="../docs/v2.x/contributions.html">contribution guide</a>. Official contact channels will be listed here when confirmed.</p></div>'),
    }
    for relative, document in pages.items():
        target = prototype / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(document, encoding='utf-8')
    for relative in ('packages/sample-package/index.html', 'kits/sample-kit/index.html'):
        stale = (prototype / relative).resolve()
        if stale.is_relative_to(prototype.resolve()) and stale.is_file():
            stale.unlink()
            if not any(stale.parent.iterdir()):
                stale.parent.rmdir()


def sidebar(version: str, pages: list[dict], active: str | None) -> str:
    sections = []
    for category, entries in group_pages([page for page in pages if page['slug'] != 'index']).items():
        expanded = any(entry['slug'] == active for entry in entries)
        links = ''.join(
            f'<a href="{esc(entry["slug"])}.html"'
            + (' aria-current="page"' if entry['slug'] == active else '')
            + f'>{esc(entry["title"])}</a>' for entry in entries
        )
        sections.append(
            f'<section class="nav-section"><button class="section-toggle" type="button" '
            f'aria-expanded="{str(expanded).lower()}"><span>{esc(category)}</span>'
            f'<span aria-hidden="true">⌄</span></button><div class="section-links"'
            + ('' if expanded else ' hidden') + f'>{links}</div></section>'
        )
    return '<nav aria-label="Documentation sections">' + ''.join(sections) + '</nav>'


def home_fragment(version: str, pages: list[dict]) -> str:
    if version == 'v1.x':
        intro = (
            '<p class="doc-intro">This is the historical SqueHub v1.x reference. '
            'Its examples use the v1 APIs and directory layout. For new applications, '
            '<a href="../v2.x/index.html">use the v2.x documentation</a>.</p>'
            '<div class="version-note"><strong>Historical reference</strong> '
            'The v1 <code>Dumper</code>, root views, and <code>$router-&gt;add()</code> '
            'examples are preserved here for migration, not recommended for v2.</div>'
        )
    else:
        intro = (
            '<p class="doc-intro">Build a SqueHub application from the current '
            'v2.0.0 development source. The public Composer release is not yet '
            'qualified as v2.0.0. Begin with installation, then follow the guides '
            'for routing, views, data, security, and deployment.</p>'
            '<div class="version-note"><strong>Development documentation</strong> '
            'The commands on these pages describe the v2 development checkout. '
            'A future v2 release needs separate distribution verification.</div>'
            '<section class="docs-quickstart" aria-labelledby="docs-quickstart-title">'
            '<div><span class="eyebrow">A practical starting point</span>'
            '<h2 id="docs-quickstart-title">Build your first SqueHub page</h2>'
            '<p>Install the complete development source, configure the application, '
            'then create a route and a View. The published Composer create-project '
            'flow awaits the v2 release.</p><a href="installation.html">Installation guide →</a></div>'
            '<div class="code-block"><div class="code-bar"><span>PowerShell</span>'
            '<button class="copy-button" type="button" aria-label="Copy setup commands">Copy</button></div>'
            '<pre><code>composer install\nCopy-Item .example.env .env\n'
            'php squehub key:generate\n# Set the printed value as APP_KEY in .env\n'
            'php squehub doctor\nphp squehub start</code></pre></div></section>'
            '<section class="docs-paths" aria-label="Popular guides"><h2>Continue building</h2><p>'
            '<a href="first-application.html">First application</a>'
            '<a href="routing.html">Routes</a><a href="views.html">Views and layouts</a>'
            '<a href="database.html">Database</a><a href="security.html">Security</a>'
            '<a href="deployment.html">Deployment</a>'
            '<a href="upgrade-from-v1.html">Upgrade from v1</a></p></section>'
        )
    cards = []
    for category, entries in group_pages([page for page in pages if page['slug'] != 'index']).items():
        first = entries[0]
        cards.append(
            f'<a class="portal-card" href="{esc(first["slug"])}.html">'
            f'<span>{len(entries)} guides</span><strong>{esc(category)}</strong>'
            f'<small>{esc(first["summary"][:125])}</small></a>'
        )
    return intro + '<h2 class="docs-browse-title">Browse by topic</h2>' \
        + '<div class="portal-grid">' + ''.join(cards) + '</div>'


def rewrite_fragment_for_static(fragment: str) -> str:
    # Static preview links are local; the application's compiled fragments
    # retain canonical clean /docs URLs.
    converted = re.sub(
        r'href="/docs/(v1\.x|v2\.x)/([a-z0-9-]+)(#[a-z0-9-]+)?"',
        lambda match: f'href="../{match.group(1)}/{match.group(2)}.html{match.group(3) or ""}"',
        fragment,
    )
    return (converted.replace('href="/docs/v1.x"', 'href="../v1.x/index.html"')
            .replace('href="/docs/v2.x"', 'href="../v2.x/index.html"'))


def static_page(template: str, version: str, page: dict | None,
                pages: list[dict], other_pages: list[dict], fragment: str) -> str:
    slug = page['slug'] if page else None
    title = page['title'] if page else ('SqueHub v1.x archive' if version == 'v1.x'
                                         else 'SqueHub v2.x documentation')
    summary = page['summary'] if page else ('Historical SqueHub v1.x documentation.'
                                             if version == 'v1.x' else 'The SqueHub v2.0.0 development guide.')
    category = page['category'] if page else 'Documentation'
    counterpart = next((p for p in other_pages if p['slug'] == slug), None)
    other_version = 'v1.x' if version == 'v2.x' else 'v2.x'
    other_url = f'../{other_version}/{counterpart["slug"]}.html' if counterpart else f'../{other_version}/index.html'
    canonical = f'https://www.squehub.com/docs/{version}' + (f'/{slug}' if slug else '')
    body = template
    body = re.sub(r'<title>.*?</title>',
                  lambda _: f'<title>{esc(title)} · SqueHub Docs</title>', body, count=1)
    body = re.sub(r'<meta name="description" content="[^"]*">',
                  lambda _: f'<meta name="description" content="{esc(summary[:155])}">',
                  body, count=1)
    body = body.replace('</head>',
        f'<link rel="canonical" href="{canonical}"><meta property="og:type" content="article">'
        f'<meta property="og:title" content="{esc(title)} · SqueHub Docs">'
        f'<meta property="og:description" content="{esc(summary[:155])}">'
        '<link rel="stylesheet" href="../../assets/css/portal.css"></head>', 1)
    body = body.replace('assets/js/site.js', 'assets/js/docs.js')
    body = body.replace('id="search-results" aria-live="polite"',
                        'id="search-results" role="listbox" aria-label="Documentation search results" aria-live="polite"')
    body = body.replace('id="search-input" type="search"',
                        'id="search-input" role="combobox" aria-autocomplete="list" '
                        'aria-controls="search-results" aria-expanded="false" type="search"')
    body = re.sub(r'<script>try\s*\{[^<]*squehub-theme[^<]*</script>', '', body, count=1)
    body = body.replace('<body class="reader-page">',
                        f'<body class="reader-page" data-version="{version}" data-site-root="../../">', 1)
    start = body.index('<nav class="top-nav"')
    end = body.index('</nav>', start) + len('</nav>')
    body = body[:start] + (
        '<nav class="top-nav" aria-label="Primary navigation">'
        '<a href="../v2.x/index.html">Docs</a><a href="../v2.x/first-application.html">Guides</a>'
        '<a href="../v2.x/api-development.html">API</a>'
        '<a href="../v2.x/packages.html">Ecosystem</a>'
        '<a href="../v2.x/deployment.html">Deployment</a></nav>'
    ) + body[end:]
    start = body.index('<nav class="mobile-site-nav"')
    end = body.index('</nav>', start) + len('</nav>')
    body = body[:start] + (
        '<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden>'
        '<a href="../v2.x/index.html">Docs</a><a href="../v2.x/first-application.html">Guides</a>'
        '<a href="../v2.x/api-development.html">API</a></nav>'
    ) + body[end:]
    start = body.index('<div class="version-menu"')
    end = body.index('</div>', start) + len('</div>')
    body = body[:start] + (
        '<div class="version-menu" role="menu" hidden><span class="menu-eyebrow">Documentation version</span>'
        f'<a role="menuitem" href="{esc(other_url)}">{other_version}'
        f'<small>{"Historical" if other_version == "v1.x" else "Development"}</small></a>'
        f'<a role="menuitem" aria-current="page" href="{esc((slug + ".html") if slug else "index.html")}">'
        f'{version}<small>{"Historical" if version == "v1.x" else "Development"}</small></a></div>'
    ) + body[end:]
    body = body.replace('>v2.0.0 <span aria-hidden="true">⌄</span>',
                        f'>{version} <span aria-hidden="true">⌄</span>', 1)
    start = body.index('<div class="sidebar-intro">')
    end = body.index('<div class="sidebar-footer">', start)
    body = body[:start] + (
        '<div class="sidebar-intro"><span class="eyebrow">SqueHub documentation</span>'
        f'<strong>Browse {version}</strong></div>' + sidebar(version, pages, slug)
    ) + body[end:]
    start = body.index('<div class="sidebar-footer">')
    end = body.index('</div>', start) + len('</div>')
    body = body[:start] + (
        '<div class="sidebar-footer"><a href="../v2.x/upgrade-from-v1.html">Upgrade from v1</a>'
        f'<a href="../{version}/index.html">Documentation home</a>'
        f'<span>{version} · Documentation</span></div>'
    ) + body[end:]
    start = body.index('<nav class="breadcrumb"')
    end = body.index('</nav>', start) + len('</nav>')
    body = body[:start] + (
        '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.html">Docs</a>'
        f'<span>/</span><span>{esc(category)}</span><span>/</span>'
        f'<span aria-current="page">{esc(title)}</span></nav>'
    ) + body[end:]
    start = body.index('<header class="article-header">')
    end = body.index('</header>', start) + len('</header>')
    body = body[:start] + (
        f'<header class="article-header"><span class="article-kicker">{esc(category)}'
        f' <span>·</span> {version}</span><h1>{esc(title)}</h1>'
        f'<p class="lead">{esc(summary)}</p><div class="article-meta"><span class="badge">{version}</span>'
        f'<span>{"Historical v1 reference" if version == "v1.x" else "v2.0.0 development documentation"}</span>'
        '</div></header>'
    ) + body[end:]
    start = body.index('<div class="article-content">')
    end = body.index('<nav class="page-nav"', start)
    legacy = ('<div class="version-note"><strong>Historical v1.x reference.</strong> '
              'These examples describe the original API. '
              '<a href="../v2.x/index.html">Read the current v2.x guides</a> '
              'or the <a href="../v2.x/upgrade-from-v1.html">upgrade guide</a>.</div>'
              if version == 'v1.x' and page else '')
    body = body[:start] + '<div class="article-content">' + legacy + fragment + '</div>' + body[end:]
    start = body.index('<nav class="page-nav"')
    end = body.index('</nav>', start) + len('</nav>')
    nav = ''
    if page:
        position = next(i for i, item in enumerate(pages) if item['slug'] == slug)
        if position:
            previous = pages[position - 1]
            nav += f'<a class="prev" href="{esc(previous["slug"])}.html"><small>← Previous</small><strong>{esc(previous["title"])}</strong></a>'
        if position + 1 < len(pages):
            following = pages[position + 1]
            nav += f'<a class="next" href="{esc(following["slug"])}.html"><small>Next →</small><strong>{esc(following["title"])}</strong></a>'
    body = body[:start] + f'<nav class="page-nav" aria-label="Previous and next pages">{nav}</nav>' + body[end:]
    body = body.replace('v2.0.0 · Current', f'{version} · Documentation')
    body = body.replace('SqueHub v2.0.0 documentation', f'SqueHub {version} documentation')
    start = body.index('<footer class="site-footer">')
    end = body.index('</footer>', start) + len('</footer>')
    body = body[:start] + (
        '<footer class="site-footer"><div><strong>SqueHub</strong>'
        '<p>Clear APIs. Careful internals.</p></div>'
        '<nav aria-label="Footer navigation">'
        '<a href="../v2.x/index.html">Current documentation</a>'
        '<a href="../v1.x/index.html">v1.x archive</a>'
        '<a href="../v2.x/upgrade-from-v1.html">Upgrade</a></nav>'
        f'<small>SqueHub {version} documentation</small></footer>'
    ) + body[end:]
    return body


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--catalog', type=Path, required=True)
    parser.add_argument('--prototype-root', type=Path, required=True)
    args = parser.parse_args()
    catalog = json.loads((args.catalog / 'manifest.json').read_text(encoding='utf-8'))['versions']
    prototype = args.prototype_root
    template = (prototype / 'docs/getting-started/installation.html').read_text(encoding='utf-8')
    pages_by_version = {version: record['pages'] for version, record in catalog.items()}
    index = []
    for version, pages in pages_by_version.items():
        target = prototype / 'docs' / version
        target.mkdir(parents=True, exist_ok=True)
        other = pages_by_version['v1.x' if version == 'v2.x' else 'v2.x']
        home = static_page(template, version, None, pages, other,
                           home_fragment(version, pages))
        (target / 'index.html').write_text(home, encoding='utf-8')
        for page in pages:
            if page['slug'] == 'index':
                index.append({
                    'version': version, 'url': prototype_url(version),
                    'title': page['title'], 'category': page['category'],
                    'summary': page['summary'],
                    'headings': ' '.join(item['title'] for item in page['headings']),
                    'sections': [item['title'] for item in page['headings']],
                    'content': search_terms(page['search']),
                })
                continue
            fragment = (args.catalog / page['fragment']).read_text(encoding='utf-8')
            document = static_page(template, version, page, pages, other,
                                   rewrite_fragment_for_static(fragment))
            (target / f'{page["slug"]}.html').write_text(document, encoding='utf-8')
            index.append({
                'version': version, 'url': prototype_url(version, page['slug']),
                'title': page['title'], 'category': page['category'],
                'summary': page['summary'],
                'headings': ' '.join(item['title'] for item in page['headings']),
                'sections': [item['title'] for item in page['headings']],
                'content': search_terms(page['search']),
            })
    (prototype / 'assets/js/search-index.js').write_text(
        'window.SQUEHUB_SEARCH_INDEX = ' + json.dumps(index, ensure_ascii=False,
            separators=(',', ':')).replace('<', '\\u003c') + ';\n', encoding='utf-8')
    landing = (prototype / 'index.html').read_text(encoding='utf-8')
    # The prototype is rebuilt during editing; strip our own head additions
    # before adding them again so each build remains byte-for-byte stable.
    for pattern in (
        r'<link rel="stylesheet" href="assets/css/portal\.css">',
        r'<link rel="canonical" href="https://www\.squehub\.com(?:/docs/v2\.x|/)?">',
        r'<meta property="og:type" content="website">',
        r'<meta property="og:title" content="SqueHub v2\.x documentation">',
    ):
        landing = re.sub(pattern, '', landing)
    landing = landing.replace('docs/getting-started/installation.html',
                              'docs/v2.x/installation.html')
    landing = landing.replace('docs/introduction.html', 'docs/v2.x/index.html')
    landing = landing.replace('releases/index.html#v1', 'docs/v1.x/index.html')
    landing = landing.replace('assets/js/site.js', 'assets/js/docs.js')
    landing = landing.replace('id="search-results" aria-live="polite"',
                              'id="search-results" role="listbox" '
                              'aria-label="Documentation search results" aria-live="polite"')
    landing = landing.replace('id="search-input" type="search"',
                              'id="search-input" role="combobox" '
                              'aria-autocomplete="list" aria-controls="search-results" '
                              'aria-expanded="false" type="search"')
    landing = re.sub(r'<script>try\s*\{[^<]*squehub-theme[^<]*</script>', '',
                     landing, count=1)
    landing = landing.replace('<body class="portal-page">',
                              '<body class="portal-page" data-version="v2.x" data-site-root="./">')
    start = landing.index('<nav class="top-nav"')
    end = landing.index('</nav>', start) + len('</nav>')
    landing = landing[:start] + (
        '<nav class="top-nav" aria-label="Primary navigation">'
        '<a href="docs/v2.x/index.html">Docs</a>'
        '<a href="packages/index.html">Packages</a>'
        '<a href="kits/index.html">Kits</a>'
        '<a href="community/index.html">Community</a>'
        '<a href="changelogs/index.html">Changelogs</a></nav>'
    ) + landing[end:]
    start = landing.index('<nav class="mobile-site-nav"')
    end = landing.index('</nav>', start) + len('</nav>')
    landing = landing[:start] + (
        '<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden>'
        '<a href="docs/v2.x/index.html">Docs</a>'
        '<a href="packages/index.html">Packages</a>'
        '<a href="kits/index.html">Kits</a>'
        '<a href="community/index.html">Community</a>'
        '<a href="changelogs/index.html">Changelogs</a>'
        '<a href="contact/index.html">Contact</a></nav>'
    ) + landing[end:]
    landing = re.sub(r'(<button class="version-button"[^>]*>).*?(</button>)',
                     r'\g<1>v2.x <span aria-hidden="true">⌄</span>\2', landing,
                     count=1, flags=re.S)
    start = landing.index('<div class="version-menu"')
    end = landing.index('</div>', start) + len('</div>')
    landing = landing[:start] + (
        '<div class="version-menu" role="menu" hidden>'
        '<span class="menu-eyebrow">Documentation version</span>'
        '<a role="menuitem" href="docs/v1.x/index.html">v1.x<small>Historical</small></a>'
        '<a role="menuitem" aria-current="page" href="docs/v2.x/index.html">'
        'v2.x<small>Development</small></a></div>'
    ) + landing[end:]
    for old, current in {
        'docs/routing/index.html': 'docs/v2.x/routing.html',
        'docs/database/index.html': 'docs/v2.x/database.html',
        'docs/security/authentication.html': 'docs/v2.x/authentication.html',
        'docs/infrastructure/queue.html': 'docs/v2.x/queue.html',
        'docs/deployment/index.html': 'docs/v2.x/deployment.html',
        'docs/deployment/apache.html': 'docs/v2.x/deployment.html',
        'docs/infrastructure/redis.html': 'docs/v2.x/redis.html',
        'docs/source-docs.html': 'docs/v2.x/index.html',
        'guides/index.html': 'docs/v2.x/first-application.html',
        'api.html': 'docs/v2.x/api-development.html',
        'releases/index.html': 'docs/v2.x/upgrade-from-v1.html',
    }.items():
        landing = landing.replace(f'href="{old}"', f'href="{current}"')
    landing = landing.replace('</head>',
        '<link rel="stylesheet" href="assets/css/portal.css">'
        '<link rel="canonical" href="https://www.squehub.com/">'
        '<meta property="og:type" content="website">'
        '<meta property="og:title" content="SqueHub v2.x documentation"></head>', 1)
    landing = re.sub(r'<title>.*?</title>',
                     '<title>SqueHub v2.x · Documentation</title>', landing, count=1)
    landing = landing.replace('THE V2 DOCUMENTATION', 'V2.0.0 DEVELOPMENT DOCUMENTATION')
    landing = re.sub(
        r'<div class="terminal-lines">.*?</div>',
        '<div class="terminal-lines"><span><i>01</i><b>$</b> composer install</span>'
        '<span><i>02</i><b>$</b> cp .example.env .env</span>'
        '<span><i>03</i><b>$</b> php squehub key:generate</span>'
        '<span><i>04</i><b>$</b> Set printed key in .env as APP_KEY</span>'
        '<span><i>05</i><b>$</b> php squehub doctor</span>'
        '<span><i>06</i><b>$</b> php squehub start</span></div>',
        landing, count=1, flags=re.S)
    landing = landing.replace('Ready when you are', 'Development source')
    landing = landing.replace('v2.0.0</span>', 'v2.x</span>')
    landing = landing.replace('SqueHub v2.0.0 documentation',
                              'SqueHub v2.0.0 development documentation')
    # Rebuild the home additions from a single source of truth. The prototype
    # itself is the reviewed input for the staged SqueHub landing View.
    landing = re.sub(r'\s*<!-- portal-home:discovery -->.*?<!-- /portal-home:discovery -->\s*',
                     '\n        ', landing, flags=re.S)
    landing = landing.replace('<section class="section-wrap categories">',
                              LANDING_DISCOVERY + '\n        <section class="section-wrap categories">', 1)
    start = landing.index('<footer class="site-footer">')
    end = landing.index('</footer>', start) + len('</footer>')
    landing = landing[:start] + (
        '<footer class="site-footer"><div><strong>SqueHub</strong>'
        '<p>A modern PHP framework built for clarity and portability.</p></div>'
        '<nav aria-label="Footer navigation">'
        '<a href="docs/v2.x/index.html">Documentation</a>'
        '<a href="packages/index.html">Packages</a>'
        '<a href="kits/index.html">Kits</a>'
        '<a href="community/index.html">Community</a>'
        '<a href="changelogs/index.html">Changelogs</a>'
        '<a href="contact/index.html">Contact</a>'
        '</nav><small>SqueHub v2.0.0 development documentation</small></footer>'
    ) + landing[end:]
    landing = re.sub(r'(?m)^[ \t]+$', '', landing)
    (prototype / 'index.html').write_text(landing, encoding='utf-8')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/portal.css',
                 prototype / 'assets/css/portal.css')
    build_preview_pages(prototype)
    # Historical prototype entry points lead to their maintained versioned
    # guides. An explicit link remains usable if a browser blocks the refresh.
    for source, target in {
        'api.html': 'docs/v2.x/api-development.html',
        'guides/index.html': '../docs/v2.x/first-application.html',
        'releases/index.html': '../docs/v2.x/upgrade-from-v1.html',
    }.items():
        destination = prototype / source
        destination.parent.mkdir(parents=True, exist_ok=True)
        anchor = '<span id="v1"></span>' if source == 'releases/index.html' else ''
        destination.write_text(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width,initial-scale=1">'
            '<meta name="robots" content="noindex,follow">'
            f'<meta http-equiv="refresh" content="0;url={esc(target)}">'
            '<title>Documentation moved · SqueHub</title></head><body>'
            + anchor
            + f'<p>This guide moved to <a href="{esc(target)}">the v2.x documentation</a>.</p>'
            + '</body></html>\n', encoding='utf-8')
    print(f'Built {len(index)} versioned prototype articles.')


if __name__ == '__main__':
    main()
