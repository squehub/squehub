"""Check local links, assets, and anchors in the static design prototype."""

from __future__ import annotations

import argparse
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit


class PageReferences(HTMLParser):
    """Collect only browser navigation targets and addressable anchors."""

    def __init__(self) -> None:
        super().__init__()
        self.references: list[str] = []
        self.anchors: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        for attribute in ('id', 'name'):
            if values.get(attribute):
                self.anchors.add(values[attribute])
        for attribute in ('href', 'src'):
            if values.get(attribute):
                self.references.append(values[attribute])


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, required=True)
    root = parser.parse_args().root.resolve()
    pages = sorted(root.rglob('*.html'))
    parsed: dict[Path, PageReferences] = {}

    def read(path: Path) -> PageReferences:
        if path not in parsed:
            result = PageReferences()
            result.feed(path.read_text(encoding='utf-8'))
            parsed[path] = result
        return parsed[path]

    issues: list[str] = []
    checked = 0
    for page in pages:
        for reference in read(page).references:
            target_url = urlsplit(reference)
            if target_url.scheme or target_url.netloc:
                continue
            relative = unquote(target_url.path)
            target = ((root / relative.lstrip('/')) if relative.startswith('/')
                      else (page.parent / relative)) if relative else page
            target = target.resolve()
            checked += 1
            label = page.relative_to(root)
            if not target.is_relative_to(root) or not target.is_file():
                issues.append(f'{label}: missing local target {reference}')
                continue
            if target_url.fragment and target.suffix.lower() == '.html' \
                    and unquote(target_url.fragment) not in read(target).anchors:
                issues.append(f'{label}: missing anchor {reference}')

    print(f'Prototype HTML pages: {len(pages)}; local references: {checked}; '
          f'issues: {len(issues)}')
    for issue in issues:
        print(issue)
    if issues:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
