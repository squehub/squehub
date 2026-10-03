#!/usr/bin/env python3
"""Check the local public ecosystem routes without following external links.

This verifier checks the application route contract, example publication labels,
and private-source containment after a staged portal deployment.
"""

from __future__ import annotations

import argparse
from urllib.error import HTTPError
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    """Expose canonical redirects so their status and target can be checked."""

    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def fetch(base: str, path: str) -> tuple[int, dict[str, str], str]:
    request = Request(base.rstrip('/') + path, headers={'Accept': 'text/html'})
    try:
        with build_opener(NoRedirect).open(request, timeout=10) as response:
            return (response.status, dict(response.headers),
                    response.read().decode('utf-8', 'replace'))
    except HTTPError as error:
        return (error.code, dict(error.headers),
                error.read().decode('utf-8', 'replace'))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--base-url', required=True)
    args = parser.parse_args()
    issues: list[str] = []
    checks = 0

    for path, expected in (
        ('/', 'SqueHub'),
        ('/packages', 'Packages'),
        ('/kits', 'Kits'),
        ('/community', 'Community'),
        ('/partners', 'Partnerships'),
        ('/changelogs', 'Changelog'),
        ('/contact', 'Contact'),
    ):
        status, headers, body = fetch(args.base_url, path)
        checks += 1
        if status != 200 or '<!doctype html>' not in body.lower() or expected not in body:
            issues.append(f'{path}: expected a rendered {expected} page, received {status}')
        if 'class="current-version-banner"' not in body \
                or '<strong>v2.0.0</strong>' not in body \
                or 'In development' not in body:
            issues.append(f'{path}: current development version is missing')
        if headers.get('X-Content-Type-Options') != 'nosniff' \
                or 'default-src' not in headers.get('Content-Security-Policy', ''):
            issues.append(f'{path}: response security headers are missing')

    for path, target in (('/Packages', '/packages'), ('/Kits', '/kits'),
                         ('/partner', '/partners'),
                         ('/packages/media/overview', '/packages/media'),
                         ('/kits/app-starter/overview', '/kits/app-starter')):
        status, headers, _ = fetch(args.base_url, path)
        location = headers.get('Location')
        checks += 1
        if status != 301 or location != target:
            issues.append(f'{path}: expected 301 to {target}, received {status} to {location!r}')

    for category, slug, identifier in (('packages', 'media', 'squehub/media'),
                                       ('kits', 'app-starter', 'squehub/app-starter')):
        for section in ('', '/details', '/docs'):
            path = f'/{category}/{slug}{section}'
            status, headers, body = fetch(args.base_url, path)
            checks += 1
            if status != 200 or identifier not in body or 'not released' not in body.lower():
                issues.append(f'{path}: expected a clearly unpublished plan, received {status}')
            if headers.get('X-Content-Type-Options') != 'nosniff':
                issues.append(f'{path}: missing nosniff header')
            if 'name="robots" content="noindex,follow"' not in body:
                issues.append(f'{path}: planned page should not be indexed as a release')
        for section in ('/installation', '/changelog', '/missing'):
            path = f'/{category}/{slug}{section}'
            status, headers, body = fetch(args.base_url, path)
            checks += 1
            if status != 404 or headers.get('Cache-Control') != 'no-store' \
                    or 'noindex' not in body:
                issues.append(f'{path}: expected uncached, unindexed 404, received {status}')

    for path in ('/packages/unknown', '/kits/unknown', '/index.html',
                 '/packages/sample-package', '/kits/sample-kit'):
        status, _, _ = fetch(args.base_url, path)
        checks += 1
        if status != 404:
            issues.append(f'{path}: expected 404, received {status}')
    for path in ('/Project/Ecosystem/catalog.json',
                 '/Project/Controllers/EcosystemController.php'):
        status, _, _ = fetch(args.base_url, path)
        checks += 1
        if status != 403:
            issues.append(f'{path}: expected static-file denial, received {status}')

    status, headers, body = fetch(args.base_url, '/assets/docs/css/ecosystem.css')
    checks += 1
    if status != 200 or '.ecosystem-page' not in body \
            or 'text/css' not in headers.get('Content-Type', ''):
        issues.append('Ecosystem stylesheet is not served')

    status, headers, partner = fetch(args.base_url, '/partners')
    checks += 1
    if status != 200 or 'name="robots" content="index,follow"' not in partner \
            or 'rel="canonical" href="https://www.squehub.com/partners"' not in partner:
        issues.append('/partners: expected an indexable page with its canonical URL')
    for marker in ('For individuals', 'For companies', 'partner@squehub.com',
                   'href="mailto:partner@squehub.com"', 'href="/docs/v2.x"',
                   'aria-label="Ways to partner"', 'SqueHub sponsor',
                   'https://www.cybqu.com/', 'Showcase placeholder',
                   'Your organization here', 'Your project here',
                   '/assets/docs/images/partners/cybqu.svg'):
        if marker not in partner:
            issues.append(f'/partners: missing {marker!r}')
    if '<form' in partner.lower():
        issues.append('/partners: unexpected application form')
    if 'href="/partners" aria-current="page"' not in partner:
        issues.append('/partners: primary navigation does not mark the current page')

    status, headers, logo = fetch(args.base_url, '/assets/docs/images/partners/cybqu.svg')
    checks += 1
    if status != 200 or '<svg' not in logo \
            or 'image/svg+xml' not in headers.get('Content-Type', ''):
        issues.append('CybQu sponsor logo is not served as SVG')

    _, _, home = fetch(args.base_url, '/')
    checks += 1
    for path in ('/packages', '/kits', '/packages/media',
                 '/kits/app-starter', '/community', '/partners', '/changelogs', '/contact'):
        if f'href="{path}"' not in home:
            issues.append(f'Home has no link to {path}')
    if '/index.html' in home:
        issues.append('Home links to the discontinued /index.html path')

    print(f'Ecosystem HTTP checks: {checks}; issues: {len(issues)}')
    for issue in issues:
        print(issue)
    if issues:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
