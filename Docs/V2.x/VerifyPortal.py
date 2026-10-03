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
from urllib.parse import urljoin, urlsplit
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


class VisibleTextParser(HTMLParser):
    """Read page text without treating URL paths or HTML attributes as copy."""

    ignored_tags = {'script', 'style', 'svg', 'template'}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.ignored_depth = 0
        self.parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in self.ignored_tags:
            self.ignored_depth += 1

    def handle_endtag(self, tag: str) -> None:
        if tag in self.ignored_tags and self.ignored_depth:
            self.ignored_depth -= 1

    def handle_data(self, data: str) -> None:
        if not self.ignored_depth:
            self.parts.append(data)


def has_visible_v2(source: str) -> bool:
    parser = VisibleTextParser()
    parser.feed(source)
    return re.search(r'\bv2\b', ' '.join(parser.parts), re.IGNORECASE) is not None


MAX_STATIC_ASSETS = 64
MAX_STATIC_ASSET_BYTES = 2 * 1024 * 1024
UNRESOLVED_DOC_TOKEN = re.compile(r'DOCSTOKEN\d+END')


def unresolved_doc_token_issue(page: str, body: str) -> str | None:
    """Reject visible renderer placeholders before publication checks pass."""
    if UNRESOLVED_DOC_TOKEN.search(body):
        return f'{page}: unresolved documentation token'
    return None


def local_asset_path(value: str) -> str | None:
    """Map only SqueHub static URLs to a path on the selected local origin."""
    try:
        parsed = urlsplit(value)
    except ValueError:
        return None
    if parsed.scheme and parsed.scheme not in {'http', 'https'}:
        return None
    if parsed.netloc and parsed.netloc.lower() not in {'squehub.com', 'www.squehub.com'}:
        return None
    path = parsed.path
    if not (path.startswith('/assets/docs/')
            or path == '/assets/images/og/squehub.png'):
        return None
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', path) or '..' in path.split('/'):
        return None
    return path + (f'?{parsed.query}' if parsed.query else '')


class AssetReferences(HTMLParser):
    """Collect only static assets referenced by rendered public HTML."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.paths: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        candidates = [values.get('href'), values.get('src')]
        if tag == 'meta' and (values.get('property') == 'og:image'
                              or values.get('name') == 'twitter:image'):
            candidates.append(values.get('content'))
        if values.get('srcset'):
            candidates.extend(part.strip().split(' ', 1)[0]
                              for part in values['srcset'].split(','))
        for value in candidates:
            path = local_asset_path(value) if value else None
            if path:
                self.paths.add(path)


def referenced_assets(html: str) -> set[str]:
    parser = AssetReferences()
    parser.feed(html)
    return parser.paths


def stylesheet_assets(path: str, body: bytes) -> set[str]:
    """Follow same-origin CSS url() references, including imported stylesheets."""
    source = body.decode('utf-8', 'replace')
    found: set[str] = set()
    for match in re.finditer(r'url\(\s*[\'\"]?([^\'\")]+)', source):
        target = local_asset_path(urljoin(path, match.group(1).strip()))
        if target:
            found.add(target)
    return found


def header_value(headers: dict[str, str], name: str) -> str | None:
    """HTTP field names are case-insensitive, including on LiteSpeed responses."""
    return next((value for field, value in headers.items()
                 if field.lower() == name.lower()), None)


def static_asset_issue(path: str, status: int, headers: dict[str, str],
                       body: bytes) -> str | None:
    """Reject error-page fallbacks, wrong media types, and invalid asset bytes."""
    if status != 200:
        return f'{path}: static asset returned {status}'
    if len(body) > MAX_STATIC_ASSET_BYTES:
        return f'{path}: static asset exceeds {MAX_STATIC_ASSET_BYTES} bytes'
    content_type = (header_value(headers, 'Content-Type') or '').split(';', 1)[0].strip().lower()
    suffix = urlsplit(path).path.rsplit('.', 1)[-1].lower()
    expected = {
        'css': {'text/css'},
        'js': {'text/javascript', 'application/javascript'},
        'png': {'image/png'},
        'svg': {'image/svg+xml'},
    }.get(suffix)
    if expected is None:
        return f'{path}: unsupported public static asset type'
    if content_type not in expected:
        return f'{path}: static asset has unexpected Content-Type {content_type!r}'
    if not body.strip():
        return f'{path}: static asset is empty'
    if suffix == 'png':
        if (len(body) < 24 or body[:8] != b'\x89PNG\r\n\x1a\n'
                or body[12:16] != b'IHDR'):
            return f'{path}: static asset is not a PNG'
        if urlsplit(path).path == '/assets/images/og/squehub.png' \
                and struct.unpack('>II', body[16:24]) != (1200, 630):
            return f'{path}: social image is not 1200 × 630'
    elif suffix == 'svg':
        if b'<svg' not in body[:4096].lower() or b'<html' in body[:4096].lower():
            return f'{path}: static asset is not SVG content'
    elif b'<html' in body[:4096].lower() or b'<!doctype html' in body[:4096].lower():
        return f'{path}: static asset contains an HTML page'
    return None


class NoRedirect(HTTPRedirectHandler):
    """Expose route and asset redirects instead of following them."""

    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def response(base: str, path: str) -> tuple[int, dict[str, str], str]:
    request = Request(base.rstrip('/') + path, headers={'Accept': 'text/html'})
    try:
        with urlopen(request, timeout=10) as opened:
            return opened.status, dict(opened.headers), opened.read().decode('utf-8', 'replace')
    except HTTPError as error:
        return error.code, dict(error.headers), error.read().decode('utf-8', 'replace')


def static_response(base: str, path: str) -> tuple[int, dict[str, str], bytes]:
    if not path.startswith('/') or local_asset_path(path) != path:
        raise ValueError('Static asset request must use a local asset path')
    request = Request(base.rstrip('/') + path, headers={'Accept': '*/*'})
    opener = build_opener(NoRedirect)
    try:
        with opener.open(request, timeout=5) as opened:
            return opened.status, dict(opened.headers), opened.read(MAX_STATIC_ASSET_BYTES + 1)
    except HTTPError as error:
        return error.code, dict(error.headers), error.read(MAX_STATIC_ASSET_BYTES + 1)


def verify_static_assets(base: str, references: set[str]) -> list[str]:
    issues: list[str] = []
    pending = set(references)
    checked: set[str] = set()
    while pending:
        if len(checked) >= MAX_STATIC_ASSETS:
            issues.append(f'public pages reference more than {MAX_STATIC_ASSETS} static assets')
            break
        path = min(pending)
        pending.remove(path)
        if path in checked:
            continue
        checked.add(path)
        status, headers, body = static_response(base, path)
        problem = static_asset_issue(path, status, headers, body)
        if problem:
            issues.append(problem)
        elif urlsplit(path).path.endswith('.css'):
            pending.update(stylesheet_assets(path, body) - checked)
    return issues


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--catalog', type=Path, required=True)
    parser.add_argument('--site-root', type=Path)
    parser.add_argument('--base-url', help='Local HTTP origin such as http://localhost')
    args = parser.parse_args()
    manifest = json.loads((args.catalog / 'manifest.json').read_text(encoding='utf-8'))
    issues: list[str] = []
    expected_labels = {'v1.x': 'v1.x — Legacy', 'v2.x': 'v2.x — Current'}
    for version, label in expected_labels.items():
        if manifest['versions'].get(version, {}).get('label') != label:
            issues.append(f'{version}: incorrect version selector label')
    pages = {
        (version, page['slug']): page
        for version, record in manifest['versions'].items()
        for page in record['pages']
    }
    parsed: dict[tuple[str, str], ArticleParser] = {}
    for key, page in pages.items():
        fragment = (args.catalog / page['fragment']).read_text(encoding='utf-8')
        document = ArticleParser()
        document.feed(fragment)
        parsed[key] = document
        issues.extend(f'{key}: {problem}' for problem in document.problems)
        token_issue = unresolved_doc_token_issue(str(key), fragment)
        if token_issue:
            issues.append(token_issue)

    # Internal development records are deliberately omitted from the public
    # catalog. Guides that cite them must point to the curated public status.
    status_link = '/docs/v2.x/status'
    if ('v2.x', 'status') not in pages:
        issues.append('missing public v2 status guide')
    home = pages.get(('v2.x', 'index'))
    if home is None:
        issues.append('missing curated v2 home')
    else:
        home_html = (args.catalog / home['fragment']).read_text(encoding='utf-8')
        if 'Select and verify an exact v2 source ref or Composer version.' not in home_html \
                or 'not yet a published' in home_html:
            issues.append('curated v2 home has stale installation guidance')
    for slug in ('agent-and-ai', 'agent-mcp-setup', 'agent-mcp-tools',
                 'api-verification', 'setup'):
        document = parsed.get(('v2.x', slug))
        if document is None or status_link not in document.links:
            issues.append(f'{slug}: missing link to public v2 status')
    v2_index = parsed.get(('v2.x', 'index'))
    for path in ('/docs/v2.x/first-application', '/docs/v2.x/api-development',
                 '/docs/v2.x/agent-and-ai', '/docs/v2.x/agent-mcp-setup',
                 '/docs/v2.x/agent-mcp-tools', status_link):
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
            ('js/docs.js', Path(__file__).parent / 'PortalAssets/docs.js'),
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
            if landing.count('<h1 ') != 1 or '<h1 id="sq-hero-title">SqueHub — <span>The PHP Framework for Modern Web Builders</span></h1>' not in landing:
                issues.append('landing must have one current hero headline')
            if 'class="sq-hero-art" aria-hidden="true"' not in landing \
                    or not all(f'>{name}</span>' in landing for name in
                               ('QUEUE', 'SECURITY', 'DATA', 'VIEWS', 'ROUTING')):
                issues.append('landing hero 3D stack is incomplete or not decorative')
            if 'class="sq-agent-section"' not in landing \
                    or 'class="sq-agent-scene"' not in landing \
                    or 'href="/docs/v2.x/agent-and-ai"' not in landing \
                    or 'href="/docs/v2.x/agent-mcp-setup"' not in landing \
                    or 'Optional local MCP integration' not in landing:
                issues.append('landing Agent section is missing its optional local scope')
            if ('src="/assets/docs/js/docs.js?v=20261003e"' not in landing
                    or 'src="/assets/docs/js/search-index.js?v=20261003e"' not in landing):
                issues.append('landing search asset cache version is stale')
            if has_visible_v2(landing):
                issues.append('landing contains visible v2 text')
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
                for slug, term in (('agent-mcp-setup', 'codex'),
                                   ('agent-mcp-tools', 'create_plan'),
                                   ('agent-mcp-tools', 'changeplan')):
                    if not any(item['version'] == 'v2.x'
                               and item['url'] == f'docs/v2.x/{slug}'
                               and term in item['content'] for item in index):
                        issues.append(f'{slug}: missing from searchable public guides')
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
        asset_paths: set[str] = {
            '/assets/docs/images/squehub-icon.png',
            '/assets/images/og/squehub.png',
        }
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
                asset_paths.update(referenced_assets(body))
                token_issue = unresolved_doc_token_issue(path, body)
                if token_issue:
                    issues.append(token_issue)
                if status != 200 or '<title>' not in body or 'rel="canonical"' not in body:
                    issues.append(f'{path} status/metadata invalid: {status}')
                if ('src="/assets/docs/js/docs.js?v=20261003e"' not in body
                        or 'src="/assets/docs/js/search-index.js?v=20261003e"' not in body):
                    issues.append(f'{path}: search asset cache version is stale')
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
                # Article copy may legitimately describe a development environment.
                banner = re.search(
                    r'<div class="current-version-banner"[^>]*>(.*?)</div>',
                    body, re.DOTALL,
                )
                if banner is None \
                        or '<strong>v2.0.0</strong>' not in banner.group(1) \
                        or 'Current SqueHub version' not in banner.group(1) \
                        or 'In development' in banner.group(1):
                    issues.append(f'{path} has stale version copy')
                if header_value(headers, 'X-Content-Type-Options') != 'nosniff':
                    issues.append(f'{path} lacks nosniff')
                if version == 'v2.x' and not slug:
                    for guide in ('/docs/v2.x/agent-and-ai',
                                  '/docs/v2.x/agent-mcp-setup',
                                  '/docs/v2.x/agent-mcp-tools', status_link):
                        if f'href="{guide}"' not in body:
                            issues.append(f'{path} lacks learning path {guide}')
        for path in ('/docs/v1.x/no-such-page', '/docs/v2.x/no-such-page'):
            status, headers, body = response(base, path)
            asset_paths.update(referenced_assets(body))
            if status != 404 or 'class="docs-not-found"' not in body \
                    or 'content="noindex,follow"' not in body \
                    or header_value(headers, 'Cache-Control') != 'no-store':
                issues.append(f'{path}: docs 404 status, design, or cache policy invalid')
        status, _, body = response(base, '/__squehub_missing_page__')
        asset_paths.update(referenced_assets(body))
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
        asset_paths.update(referenced_assets(home_body))
        if home_status != 200 or '<h1 id="sq-hero-title">SqueHub — <span>The PHP Framework for Modern Web Builders</span></h1>' not in home_body \
                or '/docs/v2.x/installation' not in home_body \
                or 'class="sq-hero-art" aria-hidden="true"' not in home_body \
                or 'class="sq-agent-scene"' not in home_body \
                or 'href="/docs/v2.x/agent-mcp-setup"' not in home_body \
                or 'class="sq-platform-section"' not in home_body \
                or 'class="sq-studio-section"' not in home_body \
                or 'href="/docs/v2.x/studio"' not in home_body \
                or '/assets/docs/css/landing-studio.css' not in home_body \
                or '/assets/docs/images/partners/cybqu.svg' not in home_body \
                or 'href="/partners"' not in home_body:
            issues.append(f'Site home does not show the current docs landing: {home_status}')
        if has_visible_v2(home_body):
            issues.append('Site home contains visible v2 text')
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
        issues.extend(verify_static_assets(base, asset_paths))
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
