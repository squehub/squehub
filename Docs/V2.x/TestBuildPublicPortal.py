"""Focused checks for public links replacing internal development records."""

from __future__ import annotations

import unittest

from BuildPublicPortal import inline, safe_link


class PublicStatusLinkTest(unittest.TestCase):
    def setUp(self) -> None:
        self.public_stems = {"Status": "status", "Installation": "installation"}

    def test_internal_status_records_share_the_public_guide(self) -> None:
        for name in ("FeatureStatus", "ReleaseReadiness", "Roadmap"):
            with self.subTest(name=name):
                self.assertEqual(
                    safe_link(f"{name}.md#an-internal-heading", "v2.x", self.public_stems),
                    "/docs/v2.x/status",
                )
                self.assertEqual(
                    inline(f"See [exact results]({name}.md#an-internal-heading).",
                           self.public_stems),
                    'See <a href="/docs/v2.x/status">v2 status</a>.',
                )

    def test_other_internal_records_stay_unpublished(self) -> None:
        for name in ("CodeStandards", "RequestCases"):
            with self.subTest(name=name):
                self.assertIsNone(safe_link(f"{name}.md", "v2.x", self.public_stems))

    def test_normal_public_links_keep_their_labels_and_sections(self) -> None:
        self.assertEqual(
            inline("Read [installation](Installation.md#requirements).", self.public_stems),
            'Read <a href="/docs/v2.x/installation#requirements">installation</a>.',
        )


if __name__ == "__main__":
    unittest.main()
