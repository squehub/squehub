"""Stage the public catalog and prototype assets for the SqueHub site.

The staged tree is copied into the separate XAMPP application only after
review. No runtime request reads the development checkout or historical site.
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
from pathlib import Path


def search_terms(value: str) -> str:
    """Retain late-article search terms in a bounded deduplicated index."""
    return ' '.join(dict.fromkeys(re.findall(r'[\w.-]+', value.casefold())))


def replace_once(source: str, pattern: str, replacement: str, label: str) -> str:
    updated, count = re.subn(pattern, replacement, source, count=1, flags=re.DOTALL)
    if count != 1:
        raise ValueError(f'Prototype landing is missing its {label}')
    return updated


def replace_literal_once(source: str, pattern: str, replacement: str,
                         label: str) -> str:
    """Replace a generated HTML fragment without parsing its backslashes."""
    updated, count = re.subn(pattern, lambda _: replacement, source,
                             count=1, flags=re.DOTALL)
    if count != 1:
        raise ValueError(f'Prototype landing is missing its {label}')
    return updated


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--catalog', type=Path, required=True)
    parser.add_argument('--prototype-root', type=Path, required=True)
    parser.add_argument('--stage', type=Path, required=True)
    args = parser.parse_args()

    documentation = args.stage / 'Project/Documentation'
    documentation.mkdir(parents=True, exist_ok=True)
    shutil.copy2(args.catalog / 'manifest.json', documentation / 'manifest.json')
    for version in ('v1.x', 'v2.x'):
        target = documentation / 'Pages' / version
        target.mkdir(parents=True, exist_ok=True)
        for source in (args.catalog / 'Pages' / version).glob('*.html'):
            shutil.copy2(source, target / source.name)

    assets = args.stage / 'public/assets/docs'
    for css in ('variables.css', 'base.css', 'layout.css',
                'components.css', 'responsive.css', 'site.css'):
        destination = assets / 'css' / css
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(args.prototype_root / 'assets/css' / css, destination)
    shutil.copy2(Path(__file__).parent / 'PortalAssets/portal.css',
                 assets / 'css/portal.css')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/landing.css',
                 assets / 'css/landing.css')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/landing-platform.css',
                 assets / 'css/landing-platform.css')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/landing-studio.css',
                 assets / 'css/landing-studio.css')
    # Error pages use the same tokens but stay independent of the search UI.
    shutil.copy2(Path(__file__).parent / 'PortalAssets/error.css',
                 assets / 'css/error.css')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/ecosystem.css',
                 assets / 'css/ecosystem.css')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/preloader.css',
                 assets / 'css/preloader.css')
    destination = assets / 'js/docs.js'
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(args.prototype_root / 'assets/js/docs.js', destination)
    shutil.copy2(Path(__file__).parent / 'PortalAssets/preloader.js',
                 assets / 'js/preloader.js')
    shutil.copy2(Path(__file__).parent / 'PortalAssets/landing.js',
                 assets / 'js/landing.js')
    destination = assets / 'images/favicon.svg'
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(args.prototype_root / 'assets/images/favicon.svg', destination)
    for image in ('squehub-icon.png', 'sq-l.png', 'sq-r.png'):
        shutil.copy2(Path(__file__).parent / 'PortalAssets/Images' / image,
                     assets / 'images' / image)
    partner_logo = assets / 'images/partners/cybqu.svg'
    partner_logo.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(Path(__file__).parent / 'PortalAssets/Images/partners/cybqu.svg',
                 partner_logo)
    og_image = args.stage / 'public/assets/images/og/squehub.png'
    og_image.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(Path(__file__).parent / 'PortalAssets/Images/og/squehub.png',
                 og_image)

    catalog = json.loads((args.catalog / 'manifest.json').read_text(encoding='utf-8'))
    index = []
    for version, record in catalog['versions'].items():
        for page in record['pages']:
            index.append({
                'version': version,
                'url': (f'docs/{version}' if page['slug'] == 'index'
                        else f'docs/{version}/{page["slug"]}'),
                'title': page['title'],
                'category': page['category'],
                'summary': page['summary'],
                'headings': ' '.join(heading['title'] for heading in page['headings']),
                'sections': [heading['title'] for heading in page['headings']],
                'content': search_terms(page['search']),
            })
    (assets / 'js/search-index.js').write_text(
        'window.SQUEHUB_SEARCH_INDEX = '
        + json.dumps(index, ensure_ascii=False, separators=(',', ':'))
            .replace('<', '\\u003c').replace('\u2028', '\\u2028')
            .replace('\u2029', '\\u2029')
        + ';\n', encoding='utf-8')
    # The reviewed prototype landing is compiled as a SqueHub View. Runtime
    # requests never read the prototype tree or build-time Markdown sources.
    landing = (args.prototype_root / 'index.html').read_text(encoding='utf-8')
    landing = landing.replace('assets/css/', '/assets/docs/css/')
    landing = landing.replace('assets/js/', '/assets/docs/js/')
    landing = landing.replace('assets/images/', '/assets/docs/images/')
    landing = re.sub(
        r'(["\'])docs/(v[12]\.x)/(index|[a-z0-9-]+)\.html\1',
        lambda match: match.group(1) + '/docs/' + match.group(2)
            + ('' if match.group(3) == 'index' else '/' + match.group(3))
            + match.group(1),
        landing,
    )
    for static_path, route in {
        'packages/index.html': '/packages',
        'packages/media/index.html': '/packages/media',
        'kits/index.html': '/kits',
        'kits/app-starter/index.html': '/kits/app-starter',
        'community/index.html': '/community',
        'changelogs/index.html': '/changelogs',
        'contact/index.html': '/contact',
        'partner/index.html': '/partners',
    }.items():
        landing = landing.replace(f'href="{static_path}"', f'href="{route}"')
    landing = landing.replace('href="index.html"', 'href="/"')
    landing = landing.replace('data-site-root="./"', 'data-site-root="/"')
    landing = landing.replace('https://www.squehub.com/docs/v2.x',
                              'https://www.squehub.com/')
    landing = replace_once(
        landing, r'<title>SqueHub v2\.x · Documentation</title>',
        '<title>SqueHub — The PHP Framework for Modern Web Builders</title>',
        'homepage title',
    )
    landing = replace_once(
        landing,
        r'<meta name="description" content="Documentation — SqueHub v2 documentation">',
        '<meta name="description" content="SqueHub is a modern PHP framework for building secure, scalable web applications with simple APIs, powerful built-in tools, and flexible deployment.">',
        'homepage description',
    )
    landing = replace_once(
        landing,
        r'<link rel="stylesheet" href="/assets/docs/css/portal\.css">'
        r'<link rel="canonical" href="https://www\.squehub\.com/">'
        r'<meta property="og:type" content="website">'
        r'<meta property="og:title" content="SqueHub v2\.x documentation"></head>',
        '\n'.join((
            '    <link rel="stylesheet" href="/assets/docs/css/portal.css">',
            '    <link rel="canonical" href="https://www.squehub.com/">',
            '    <meta property="og:type" content="website">',
            '    <meta property="og:title" content="SqueHub — Modern PHP Framework">',
            '    <meta property="og:description" content="Build secure, scalable PHP applications with simple APIs, powerful built-in tools, and flexible deployment.">',
            '    <meta property="og:url" content="https://www.squehub.com/">',
            '    <meta property="og:image" content="https://www.squehub.com/assets/images/og/squehub.png">',
            '    <meta name="twitter:card" content="summary_large_image">',
            '    <meta name="twitter:title" content="SqueHub — Modern PHP Framework">',
            '    <meta name="twitter:description" content="Build secure, scalable PHP applications with simple APIs, powerful built-in tools, and flexible deployment.">',
            '    <meta name="twitter:image" content="https://www.squehub.com/assets/images/og/squehub.png">',
            '</head>',
        )),
        'homepage social metadata',
    )
    landing = replace_once(
        landing,
        r'<link rel="icon" href="/assets/docs/images/favicon\.svg" type="image/svg\+xml">',
        '<link rel="icon" href="/assets/docs/images/squehub-icon.png" type="image/png">',
        'official favicon',
    )
    landing = replace_once(
        landing,
        r'<span class="brand-mark"\s+aria-hidden="true">\s*<svg\b.*?</svg>\s*</span>',
        '<span class="brand-mark" aria-hidden="true"><img src="/assets/docs/images/squehub-icon.png" alt="" width="180" height="203"></span>',
        'brand mark',
    )
    landing = replace_once(
        landing, r'aria-label="Open documentation menu"',
        'aria-label="Open site menu"', 'menu label',
    )
    landing = replace_once(
        landing, r'aria-label="SqueHub documentation home"',
        'aria-label="SqueHub home"', 'brand label',
    )
    landing = replace_once(
        landing, r'<span>SqueHub<span class="brand-docs">/ Docs</span></span>',
        '<span>SqueHub</span>', 'brand text',
    )
    landing = replace_once(
        landing,
        r'<div class="version-wrap">.*?</div>\s*</div>\s*(?=<button class="theme-button)',
        '', 'documentation version selector',
    )
    landing = replace_once(
        landing,
        r'<nav class="top-nav" aria-label="Primary navigation">.*?</nav>',
        '<nav class="top-nav" aria-label="Primary navigation"><a href="/" aria-current="page">Home</a><a href="/docs/v2.x">Docs</a><a href="/packages">Packages</a><a href="/kits">Kits</a><a href="/community">Community</a><a href="/partners">Partners</a></nav>',
        'desktop navigation',
    )
    landing = replace_once(
        landing,
        r'<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden>.*?</nav>',
        '<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden><a href="/" aria-current="page">Home</a><a href="/docs/v2.x">Docs</a><a href="/packages">Packages</a><a href="/kits">Kits</a><a href="/community">Community</a><a href="/partners">Partners</a><a href="/changelogs">Changelogs</a><a href="/contact">Contact</a></nav>',
        'mobile navigation',
    )
    version_banner = (
        '<div class="current-version-banner" role="note">'
        '<span>Current SqueHub version</span><strong>v2.0.0</strong>'
        '<span class="current-version-state">In development</span></div>'
    )
    landing = replace_once(
        landing,
        r'(<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden>.*?</nav>)',
        r'\1' + '\n' + version_banner,
        'current version banner',
    )
    landing = replace_once(
        landing,
        r'href="/assets/docs/css/portal\.css"',
        'href="/assets/docs/css/portal.css?v=20261003c"',
        'portal stylesheet',
    )
    landing_main = (Path(__file__).parent / 'PortalAssets/landing-main.html').read_text(
        encoding='utf-8').strip()
    platform_marker = '<!-- portal-home:platform -->'
    if landing_main.count(platform_marker) != 1:
        raise ValueError('Landing main must contain one frontend platform marker')
    landing_main = landing_main.replace(
        platform_marker,
        (Path(__file__).parent / 'PortalAssets/landing-platform.html').read_text(
            encoding='utf-8').strip(),
    )
    studio_marker = '<!-- portal-home:studio -->'
    if landing_main.count(studio_marker) != 1:
        raise ValueError('Landing main must contain one Studio marker')
    landing_main = landing_main.replace(
        studio_marker,
        (Path(__file__).parent / 'PortalAssets/landing-studio.html').read_text(
            encoding='utf-8').strip(),
    )
    landing = replace_literal_once(
        landing, r'<main id="main">.*?</main>', landing_main,
        'landing main content',
    )
    landing = replace_once(
        landing, r'<footer class="site-footer">.*?</footer>',
        '<footer class="site-footer"><div><strong>SqueHub</strong><p>A modern PHP framework built for clarity and portability.</p></div><nav aria-label="Footer navigation"><a href="/docs/v2.x">Documentation</a><a href="/packages">Packages</a><a href="/kits">Kits</a><a href="/community">Community</a><a href="/partners">Partners</a><a href="/changelogs">Changelogs</a><a href="/contact">Contact</a></nav><small>SqueHub v2.0.0 development documentation</small></footer>',
        'landing footer',
    )
    landing = replace_once(
        landing,
        r'</head>',
        '    <link rel="stylesheet" href="/assets/docs/css/landing.css?v=20261003b">\n'
        '    <link rel="stylesheet" href="/assets/docs/css/landing-platform.css?v=20261003a">\n'
        '    <link rel="stylesheet" href="/assets/docs/css/landing-studio.css?v=20261003a">\n'
        '    <script defer src="/assets/docs/js/landing.js?v=20261003a"></script>\n'
        '    <link rel="stylesheet" href="/assets/docs/css/preloader.css?v=20261003b">\n'
        '    <script src="/assets/docs/js/preloader.js?v=20261003b"></script>\n'
        '</head>',
        'head',
    )
    preloader = (
        '<div id="squehub-preloader" aria-hidden="true"><div class="squehub-preloader-mark">'
        '<img class="squehub-preloader-piece squehub-preloader-left" src="/assets/docs/images/sq-l.png" alt="" width="230" height="267">'
        '<img class="squehub-preloader-piece squehub-preloader-right" src="/assets/docs/images/sq-r.png" alt="" width="202" height="231">'
        '</div></div>'
    )
    landing = replace_once(landing, r'(<body\b[^>]*>)',
                           r'\1' + '\n' + preloader + '\n', 'body')
    destination = args.stage / 'Project/Views/Docs/Landing.squehub.php'
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(landing, encoding='utf-8')
    print(f'Staged {len(index)} articles and version-aware search entries.')


if __name__ == '__main__':
    main()
