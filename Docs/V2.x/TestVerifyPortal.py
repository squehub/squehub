"""Focused checks for live public documentation asset verification."""

from __future__ import annotations

import struct
import unittest
from unittest.mock import patch

from VerifyPortal import (MAX_STATIC_ASSETS, header_value, referenced_assets,
                          static_asset_issue, static_response, verify_static_assets)


def social_png() -> bytes:
    return (b'\x89PNG\r\n\x1a\n' + b'\x00\x00\x00\x0dIHDR'
            + struct.pack('>II', 1200, 630))


class PublicAssetVerificationTest(unittest.TestCase):
    def test_local_asset_404_is_reported_without_fetching_external_urls(self) -> None:
        html = '''
<link rel="stylesheet" href="/assets/docs/css/site.css?v=1">
<meta property="og:image"
      content="https://www.squehub.com/assets/images/og/squehub.png">
<img src="https://elsewhere.example/assets/docs/images/tracker.png">
'''
        references = referenced_assets(html)
        self.assertEqual(references, {
            '/assets/docs/css/site.css?v=1',
            '/assets/images/og/squehub.png',
        })

        def fake_response(base: str, path: str):
            self.assertEqual(base, 'http://localhost')
            if path == '/assets/docs/css/site.css?v=1':
                return 404, {'Content-Type': 'text/html'}, b'<html>Not found</html>'
            return 200, {'Content-Type': 'image/png'}, social_png()

        with patch('VerifyPortal.static_response', side_effect=fake_response) as fetch:
            issues = verify_static_assets('http://localhost', references)
        self.assertEqual(issues, ['/assets/docs/css/site.css?v=1: static asset returned 404'])
        self.assertEqual(fetch.call_count, 2)
        self.assertNotIn('elsewhere.example', str(fetch.call_args_list))

    def test_mime_and_content_are_checked(self) -> None:
        self.assertIn('unexpected Content-Type', static_asset_issue(
            '/assets/docs/css/site.css', 200, {'Content-Type': 'text/html'},
            b'<html>wrong</html>'))
        self.assertIn('HTML page', static_asset_issue(
            '/assets/docs/css/site.css', 200, {'Content-Type': 'text/css'},
            b'<!doctype html><html>wrong</html>'))
        self.assertIn('not a PNG', static_asset_issue(
            '/assets/images/og/squehub.png', 200, {'Content-Type': 'image/png'},
            b'<html>wrong</html>'))
        self.assertIn('not 1200 × 630', static_asset_issue(
            '/assets/images/og/squehub.png', 200, {'Content-Type': 'image/png'},
            social_png()[:-8] + struct.pack('>II', 640, 320)))
        self.assertIsNone(static_asset_issue(
            '/assets/images/og/squehub.png', 200, {'Content-Type': 'image/png'},
            social_png()))

    def test_http_header_names_are_case_insensitive(self) -> None:
        headers = {
            'content-type': 'text/css; charset=utf-8',
            'x-content-type-options': 'nosniff',
            'cache-control': 'no-store',
        }
        self.assertIsNone(static_asset_issue(
            '/assets/docs/css/site.css', 200, headers, b'body{color:navy}'))
        self.assertEqual(header_value(headers, 'X-Content-Type-Options'), 'nosniff')
        self.assertEqual(header_value(headers, 'Cache-Control'), 'no-store')

    def test_static_fetch_rejects_external_url_before_opening(self) -> None:
        with patch('VerifyPortal.build_opener') as opener:
            with self.assertRaises(ValueError):
                static_response('http://localhost',
                                'https://elsewhere.example/assets/docs/css/site.css')
        opener.assert_not_called()

    def test_css_imports_are_checked_and_request_count_is_capped(self) -> None:
        def fake_response(_base: str, path: str):
            if path == '/assets/docs/css/site.css':
                return 200, {'Content-Type': 'text/css'}, b"@import url('variables.css');"
            return 200, {'Content-Type': 'text/css'}, b':root{color:navy}'

        with patch('VerifyPortal.static_response', side_effect=fake_response) as fetch:
            self.assertEqual(verify_static_assets(
                'http://localhost', {'/assets/docs/css/site.css'}), [])
        self.assertEqual({call.args[1] for call in fetch.call_args_list}, {
            '/assets/docs/css/site.css', '/assets/docs/css/variables.css',
        })

        references = {f'/assets/docs/css/example-{number}.css'
                      for number in range(MAX_STATIC_ASSETS + 1)}
        with patch('VerifyPortal.static_response', side_effect=fake_response) as fetch:
            issues = verify_static_assets('http://localhost', references)
        self.assertEqual(fetch.call_count, MAX_STATIC_ASSETS)
        self.assertEqual(issues, [
            f'public pages reference more than {MAX_STATIC_ASSETS} static assets',
        ])


if __name__ == '__main__':
    unittest.main()
