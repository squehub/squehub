#!/usr/bin/env python3
"""Verify the public documentation catalog and optional local HTTP site.

The verifier reads only generated inert fragments and checks local endpoints.
It never opens external links or reads application secrets.
"""

from __future__ import annotations

import argparse
from html import unescape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import struct
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, urlopen


class ArticleParser(HTMLParser):
    """Collect anchors and IDs while checking for executable HTML surfaces."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.ids: set[str] = set()
        self.links: list[str] = []
        self.problems: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in {'script', 'style', 'iframe', 'object', 'form', 'input'}:
            self.problems.append(f'active <{tag}> element')
        for name, value in attrs:
            if name.startswith('on') or name in {'srcdoc', 'style'}:
                self.problems.append(f'active {name} attribute')
            if name == 'id' and value:
                if value in self.ids:
                    self.problems.append(f'duplicate id {value}')
                self.ids.add(value)
            if name == 'href' and value:
                self.links.append(value)
                if value.lower().startswith(('javascript:', 'data:')):
                    self.problems.append('unsafe href scheme')


class NoRedirect(HTTPRedirectHandler):
    """Expose the actual /docs status instead of following its redirect."""

    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def response(base: str, path: str) -> tuple[int, dict[str, str], str]:
    request = Request(base.rstrip('/') + path, headers={'Accept': 'text/html'})
    try:
        with urlopen(request, timeout=10) as opened:
            return opened.status, dict(opened.headers), opened.read().decode('utf-8', 'replace')
    except HTTPError as error:
        return error.code, dict(error.headers), error.read().decode('utf-8', 'replace')


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--catalog', type=Path, required=True)
    parser.add_argument('--site-root', type=Path)
    parser.add_argument('--base-url', help='Local HTTP origin such as http://localhost')
    args = parser.parse_args()
    manifest = json.loads((args.catalog / 'manifest.json').read_text(encoding='utf-8'))
    pages = {
        (version, page['slug']): page
        for version, record in manifest['versions'].items()
        for page in record['pages']
    }
    issues: list[str] = []
    parsed: dict[tuple[str, str], ArticleParser] = {}
    for key, page in pages.items():
        document = ArticleParser()
        document.feed((args.catalog / page['fragment']).read_text(encoding='utf-8'))
        parsed[key] = document
        issues.extend(f'{key}: {problem}' for problem in document.problems)

    # Internal development records are deliberately omitted from the public
    # catalog. Guides that cite them must point to the curated public status.
    status_link = '/docs/v2.x/status'
    if ('v2.x', 'status') not in pages:
        issues.append('missing public v2 status guide')
    for slug in ('agent-and-ai', 'api-verification', 'setup'):
        document = parsed.get(('v2.x', slug))
        if document is None or status_link not in document.links:
            issues.append(f'{slug}: missing link to public v2 status')
    v2_index = parsed.get(('v2.x', 'index'))
    for path in ('/docs/v2.x/first-application', '/docs/v2.x/api-development',
                 '/docs/v2.x/agent-and-ai', status_link):
        if v2_index is None or path not in v2_index.links:
            issues.append(f'v2 curated home lacks learning path {path}')

    checked_links = 0
    for key, document in parsed.items():
        for link in document.links:
            if link.startswith('#'):
                checked_links += 1
                if link[1:] not in document.ids:
                    issues.append(f'{key}: missing local section {link}')
                continue
            path = urlsplit(link)
            if path.netloc and path.netloc.lower() not in {'squehub.com', 'www.squehub.com'}:
                continue
            if not path.path.startswith('/docs/'):
                continue
            checked_links += 1
            parts = path.path.strip('/').split('/')
            if len(parts) not in (2, 3) or parts[1] not in ('v1.x', 'v2.x'):
                issues.append(f'{key}: unsupported docs link {link}')
                continue
            target = (parts[1], parts[2] if len(parts) == 3 else 'index')
            if len(parts) == 2 and parts[1] == 'v1.x':
                # v1 has a route home assembled from its historical catalog.
                continue
            if target not in pages:
                issues.append(f'{key}: missing article {link}')
            elif path.fragment and path.fragment not in parsed[target].ids:
                issues.append(f'{key}: missing destination section {link}')

    if args.site_root:
        assets = args.site_root / 'Assets/docs'
        if not assets.is_dir():
            assets = args.site_root / 'public/assets/docs'
        for path in ('css/site.css', 'css/portal.css', 'css/landing.css',
                     'css/landing-platform.css', 'css/landing-studio.css',
                     'css/error.css', 'css/ecosystem.css', 'css/preloader.css',
                     'js/docs.js', 'js/landing.js', 'js/search-index.js',
                     'js/preloader.js', 'images/partners/cybqu.svg',
                     'images/squehub-icon.png', 'images/sq-l.png',
                     'images/sq-r.png'):
            if not (assets / path).is_file():
                issues.append(f'missing site asset {assets / path}')
        for name, source in (
            ('css/landing.css', Path(__file__).parent / 'PortalAssets/landing.css'),
            ('css/landing-platform.css',
             Path(__file__).parent / 'PortalAssets/landing-platform.css'),
            ('css/landing-studio.css',
             Path(__file__).parent / 'PortalAssets/landing-studio.css'),
            ('js/landing.js', Path(__file__).parent / 'PortalAssets/landing.js'),
            ('images/partners/cybqu.svg',
             Path(__file__).parent / 'PortalAssets/Images/partners/cybqu.svg'),
        ):
            staged = assets / name
            if staged.is_file() and staged.read_bytes() != source.read_bytes():
                issues.append(f'site asset differs from reviewed source: {name}')
        landing_path = args.site_root / 'Project/Views/Docs/Landing.squehub.php'
        if landing_path.is_file():
            landing = landing_path.read_text(encoding='utf-8')
            if landing.count('<h1 ') != 1 or 'From first route to' not in landing:
                issues.append('landing must have one current hero headline')
            if 'class="sq-hero-art" aria-hidden="true"' not in landing \
                    or not all(f'>{name}</span>' in landing for name in
                               ('QUEUE', 'SECURITY', 'DATA', 'VIEWS', 'ROUTING')):
                issues.append('landing hero 3D stack is incomplete or not decorative')
            if 'class="sq-agent-section"' not in landing \
                    or 'class="sq-agent-scene"' not in landing \
                    or 'href="/docs/v2.x/agent-and-ai"' not in landing \
                    or 'Optional in the v2 development source' not in landing:
                issues.append('landing Agent section is missing its local-development scope')
            if 'class="sq-platform-section"' not in landing \
                    or landing.count('class="sq-platform-pane ') != 3 \
                    or 'href="/docs/v2.x/frontend-profiles"' not in landing \
                    or 'profile:apply vite --preview' not in landing:
                issues.append('landing frontend scene or preview guidance is missing')
            if 'class="sq-studio-section"' not in landing \
                    or 'class="sq-studio-visual sq-studio-scene" aria-hidden="true"' not in landing \
                    or 'href="/docs/v2.x/studio"' not in landing \
                    or 'ILLUSTRATIVE INTERFACE' not in landing \
                    or 'php squehub studio' not in landing:
                issues.append('landing Studio scene or local read-only guidance is missing')
            if 'class="sq-ops sq-container"' not in landing \
                    or 'php squehub doctor' not in landing \
                    or 'href="/docs/v2.x/health"' not in landing:
                issues.append('landing operations guidance is missing')
            if 'href="/partners"' not in landing or 'href="https://www.cybqu.com/"' not in landing:
                issues.append('landing partnership links are missing')
            if 'src="/assets/docs/images/partners/cybqu.svg" alt="CybQu Technologies"' not in landing \
                    or 'SQUEHUB SPONSOR' not in landing:
                issues.append('landing sponsor identity is missing')
            if 'class="sq-partners-set" aria-hidden="true" inert' not in landing \
                    or not all(f'<strong>{label}</strong>' in landing for label in
                               ('Your organization here', 'Your project here',
                                'Your studio here')) \
                    or landing.count('SHOWCASE PLACEHOLDER') != 6:
                issues.append('landing partner placeholders or inert duplicate are missing')
            for version, slug in re.findall(r'href="/docs/(v[12]\.x)(?:/([a-z0-9-]+))?"', landing):
                if (version, slug or 'index') not in pages:
                    issues.append(f'landing links to missing guide: /docs/{version}/{slug}')
        else:
            issues.append(f'missing staged landing {landing_path}')
        landing_css = assets / 'css/landing.css'
        if landing_css.is_file():
            stylesheet = landing_css.read_text(encoding='utf-8')
            reduced_styles = stylesheet.split('@media(prefers-reduced-motion:reduce)', 1)
            if len(reduced_styles) != 2 \
                    or '.sq-partners-track' not in reduced_styles[1] \
                    or '.sq-agent-world' not in reduced_styles[1] \
                    or 'animation:none!important' not in reduced_styles[1] \
                    or '.sq-partners-strip:focus-within .sq-partners-track' not in stylesheet \
                    or '.sq-partners-marquee:hover .sq-partners-track' not in stylesheet \
                    or '@keyframes sq-agent-tilt' not in stylesheet:
                issues.append('landing motion lacks reduced-motion or pause controls')
        platform_css = assets / 'css/landing-platform.css'
        if platform_css.is_file():
            stylesheet = platform_css.read_text(encoding='utf-8')
            if '@keyframes sq-platform-drift' not in stylesheet \
                    or '@media(prefers-reduced-motion:reduce)' not in stylesheet \
                    or '.sq-platform-pane{animation:none}' not in stylesheet:
                issues.append('frontend scene lacks motion or reduced-motion fallback')
        studio_css = assets / 'css/landing-studio.css'
        if studio_css.is_file():
            stylesheet = studio_css.read_text(encoding='utf-8')
            if '@keyframes sq-studio-world-drift' not in stylesheet \
                    or '@keyframes sq-studio-card-drift' not in stylesheet \
                    or '@media(prefers-reduced-motion:reduce)' not in stylesheet \
                    or '.sq-studio-world,.sq-studio-callout{animation:none}' not in stylesheet:
                issues.append('Studio scene lacks motion or reduced-motion fallback')
        for image in ('squehub-icon.png', 'sq-l.png', 'sq-r.png'):
            staged = assets / 'images' / image
            official = Path(__file__).parent / 'PortalAssets/Images' / image
            if staged.is_file() and staged.read_bytes() != official.read_bytes():
                issues.append(f'site image differs from official {image}')
        og_source = Path(__file__).parent / 'PortalAssets/Images/og/squehub.png'
        og_paths = (args.site_root / 'Assets/images/og/squehub.png',
                    args.site_root / 'public/assets/images/og/squehub.png')
        og_file = next((path for path in og_paths if path.is_file()), None)
        if og_file is None:
            issues.append('missing /assets/images/og/squehub.png')
        elif og_file.read_bytes() != og_source.read_bytes():
            issues.append('site social image differs from the reviewed source')
        if og_source.is_file():
            image = og_source.read_bytes()
            if image[:8] != b'\x89PNG\r\n\x1a\n' \
                    or struct.unpack('>II', image[16:24]) != (1200, 630):
                issues.append('social image is not a 1200 × 630 PNG')
        search_file = assets / 'js/search-index.js'
        if search_file.is_file():
            script = search_file.read_text(encoding='utf-8')
            prefix = 'window.SQUEHUB_SEARCH_INDEX = '
            if not script.startswith(prefix) or not script.rstrip().endswith(';'):
                issues.append('search index has an unexpected wrapper')
            else:
                index = json.loads(script[len(prefix):].strip()[:-1])
                if len(index) != len(pages):
                    issues.append('search index page count differs from catalog')
                if not any('identityprovider' in item['content']
                           for item in index if item['version'] == 'v2.x'):
                    issues.append('late-article search terms were truncated')
                for item in index:
                    parts = item['url'].split('/')
                    if len(parts) not in (2, 3) or parts[:2] != ['docs', item['version']]:
                        issues.append(f'malformed search result URL: {item["url"]}')
                        continue
                    target = (item['version'], parts[2] if len(parts) == 3 else 'index')
                    if target not in pages and item['url'] != 'docs/v1.x':
                        issues.append(f'search result has no article: {item["url"]}')

    http_pages = 0
    if args.base_url:
        base = args.base_url.rstrip('/')
        redirect_request = Request(base + '/docs')
        try:
            with build_opener(NoRedirect).open(redirect_request, timeout=10) as opened:
                status, location = opened.status, opened.headers.get('Location')
        except HTTPError as error:
            status, location = error.code, error.headers.get('Location')
        if status != 302 or location != '/docs/v2.x':
            issues.append(f'/docs returned {status} with Location {location!r}')
        for version in ('v1.x', 'v2.x'):
            targets = [''] + [page['slug'] for page in manifest['versions'][version]['pages']
                              if page['slug'] != 'index']
            for slug in targets:
                path = f'/docs/{version}' + (f'/{slug}' if slug else '')
                status, headers, body = response(base, path)
                http_pages += 1
                if status != 200 or '<title>' not in body or 'rel="canonical"' not in body:
                    issues.append(f'{path} status/metadata invalid: {status}')
                expected_title = (f'{pages[(version, slug)]["title"]} — SqueHub '
                                  f'{"v2.0.0" if version == "v2.x" else "v1.x"} Documentation') \
                    if slug else ('SqueHub v2.0.0 Documentation' if version == 'v2.x'
                                  else 'SqueHub v1.x Documentation Archive')
                title = re.search(r'<title>(.*?)</title>', body, re.DOTALL)
                if title is None or unescape(title.group(1)) != expected_title:
                    issues.append(f'{path}: document title is not page-specific')
                if 'property="og:image" content="https://www.squehub.com/assets/images/og/squehub.png"' not in body \
                        or 'name="twitter:image" content="https://www.squehub.com/assets/images/og/squehub.png"' not in body:
                    issues.append(f'{path}: social preview image metadata is missing')
                if 'class="current-version-banner"' not in body \
                        or '<strong>v2.0.0</strong>' not in body \
                        or 'In development' not in body:
                    issues.append(f'{path} lacks the current development version')
                if headers.get('X-Content-Type-Options') != 'nosniff':
                    issues.append(f'{path} lacks nosniff')
                if version == 'v2.x' and not slug:
                    for guide in ('/docs/v2.x/agent-and-ai', status_link):
                        if f'href="{guide}"' not in body:
                            issues.append(f'{path} lacks learning path {guide}')
        for path in ('/docs/v1.x/no-such-page', '/docs/v2.x/no-such-page'):
            status, headers, body = response(base, path)
            if status != 404 or 'class="docs-not-found"' not in body \
                    or 'content="noindex,follow"' not in body \
                    or headers.get('Cache-Control') != 'no-store':
                issues.append(f'{path}: docs 404 status, design, or cache policy invalid')
        status, _, body = response(base, '/__squehub_missing_page__')
        if status != 404 or 'class="error-page"' not in body \
                or '/assets/docs/css/error.css' not in body \
                or 'content="noindex,follow"' not in body:
            issues.append('General 404 status or documentation design invalid')
        for path, expected in (('/.env', 403), ('/composer.lock', 403),
                               ('/Project/Routes/Web.php', 403)):
            status, _, _ = response(base, path)
            if status != expected:
                issues.append(f'{path}: expected {expected}, received {status}')
        home_status, _, home_body = response(base, '/')
        if home_status != 200 or 'From first route to' not in home_body \
                or '/docs/v2.x/installation' not in home_body \
                or 'class="sq-hero-art" aria-hidden="true"' not in home_body \
                or 'class="sq-agent-scene"' not in home_body \
                or 'class="sq-platform-section"' not in home_body \
                or 'class="sq-studio-section"' not in home_body \
                or 'href="/docs/v2.x/studio"' not in home_body \
                or '/assets/docs/css/landing-studio.css' not in home_body \
                or '/assets/docs/images/partners/cybqu.svg' not in home_body \
                or 'href="/partners"' not in home_body \
                or 'class="current-version-banner"' not in home_body \
                or '<strong>v2.0.0</strong>' not in home_body:
            issues.append(f'Site home does not show the current docs landing: {home_status}')
        for metadata in (
            '<title>SqueHub — The PHP Framework for Modern Web Builders</title>',
            'name="description" content="SqueHub is a modern PHP framework for building secure, scalable web applications with simple APIs, powerful built-in tools, and flexible deployment."',
            'property="og:type" content="website"',
            'property="og:title" content="SqueHub — Modern PHP Framework"',
            'property="og:description" content="Build secure, scalable PHP applications with simple APIs, powerful built-in tools, and flexible deployment."',
            'property="og:url" content="https://www.squehub.com/"',
            'property="og:image" content="https://www.squehub.com/assets/images/og/squehub.png"',
            'name="twitter:card" content="summary_large_image"',
            'name="twitter:title" content="SqueHub — Modern PHP Framework"',
            'name="twitter:description" content="Build secure, scalable PHP applications with simple APIs, powerful built-in tools, and flexible deployment."',
            'name="twitter:image" content="https://www.squehub.com/assets/images/og/squehub.png"',
        ):
            if home_body.count(metadata) != 1:
                issues.append(f'Site home metadata missing or duplicated: {metadata}')
        icon_status, icon_headers, _ = response(base, '/assets/docs/images/squehub-icon.png')
        if icon_status != 200 or not icon_headers.get('Content-Type', '').startswith('image/png'):
            issues.append(f'Official PNG favicon is not publicly served: {icon_status}')
        og_status, og_headers, _ = response(base, '/assets/images/og/squehub.png')
        if og_status != 200 or not og_headers.get('Content-Type', '').startswith('image/png'):
            issues.append(f'Social preview PNG is not publicly served: {og_status}')
        status, _, _ = response(base, '/index.html')
        if status != 404:
            issues.append(f'/index.html should have no separate route or file: {status}')

    print(f'Catalog pages: {len(pages)}; internal links: {checked_links}; '
          f'live documentation pages: {http_pages}; issues: {len(issues)}')
    for issue in issues:
        print(issue)
    if issues:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
