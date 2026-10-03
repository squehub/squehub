"""Focused checks for public links replacing internal development records."""

from __future__ import annotations

import unittest

from BuildPublicPortal import inline, render_markdown, safe_link


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

    def test_code_inside_link_label_is_restored_without_placeholder_text(self) -> None:
        rendered = inline(
            "Read [`squehub`](https://github.com/squehub) and "
            "[`php squehub setup` and `APP_KEY`](Installation.md#configure-the-environment).",
            self.public_stems,
        )
        self.assertEqual(
            rendered,
            'Read <a href="https://github.com/squehub"><code>squehub</code></a> and '
            '<a href="/docs/v2.x/installation#configure-the-environment">'
            '<code>php squehub setup</code> and <code>APP_KEY</code></a>.',
        )
        self.assertNotIn("DOCSTOKEN", rendered)

    def test_code_inside_rejected_link_label_remains_visible_without_link(self) -> None:
        rendered = inline("Unsafe [`key`](file:///private/key).", self.public_stems)
        self.assertEqual(rendered, "Unsafe <code>key</code>.")
        self.assertNotIn("DOCSTOKEN", rendered)


class TableRenderingTest(unittest.TestCase):
    def test_wide_markdown_table_has_keyboard_scroll_region_and_semantic_table(self) -> None:
        source = (
            "# Reference\n\n"
            "| Capability | Risk | Mode | Default | Supported? | Scope | Purpose |\n"
            "| --- | --- | --- | --- | --- | --- | --- |\n"
            "| `create_plan` | review | read | Denied | Yes | operations | Review only |\n\n"
            "Next paragraph.\n"
        )
        fragment, _, _ = render_markdown(source, {})
        self.assertIn(
            '<div class="table-scroll" role="region" '
            'aria-label="Scrollable documentation table" tabindex="0">'
            '<table><thead><tr><th>Capability</th>', fragment,
        )
        self.assertIn('<td><code>create_plan</code></td>', fragment)
        self.assertIn('</tbody></table></div>\n<p>Next paragraph.</p>', fragment)
        self.assertEqual(fragment.count('<table>'), 1)


if __name__ == "__main__":
    unittest.main()
