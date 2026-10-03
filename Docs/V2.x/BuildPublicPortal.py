#!/usr/bin/env python3
"""Build reviewed, inert article fragments for the versioned public docs portal.

This is a build-time tool. It reads only the two explicitly supplied source
directories and writes static HTML fragments plus a JSON catalog. Runtime
requests must resolve pages by manifest slug; they must never turn a URL into
an arbitrary source path. No PHP template or Markdown source is executed.

The small Markdown renderer intentionally accepts a bounded documentation
subset. Unsupported markup is shown as escaped text instead of raw HTML.
"""

from __future__ import annotations

import argparse
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sys
import unicodedata
from urllib.parse import urlsplit


INTERNAL_V2 = {
    "README", "CodeStandards", "FeatureStatus", "PreCommitManifest",
    "Phase27PublicDocs", "PublicApiAudit", "ReleaseReadiness", "RequestCases", "Roadmap",
    "Stabilization", "V1DocumentationComparison",
}
INTERNAL_STATUS_STEMS = {"FeatureStatus", "ReleaseReadiness", "Roadmap"}

V1_TITLES = {
    "backup": ("backup-and-upgrade", "Backup and upgrade", "Getting started"),
    "cli": ("cli", "CLI commands", "Getting started"),
    "configuration": ("configuration", "Configuration", "Getting started"),
    "controllers": ("controllers", "Controllers", "Core concepts"),
    "directory-structure": ("directory-structure", "Directory structure", "Getting started"),
    "dumper": ("dumper", "Dumper", "Core concepts"),
    "helper": ("helper", "Global helpers", "SqueHub utilities"),
    "installation": ("installation", "Installation", "Getting started"),
    "mail": ("mail", "Mail", "SqueHub utilities"),
    "middleware": ("middleware", "Middleware", "Core concepts"),
    "migrations": ("migrations", "Migrations", "Core concepts"),
    "models": ("models", "Models", "Core concepts"),
    "namespace": ("namespace", "Namespaces", "Getting started"),
    "notification": ("notification", "Notification", "SqueHub utilities"),
    "packages": ("packages", "Packages", "SqueHub utilities"),
    "routing": ("routing", "Routing", "Core concepts"),
    "task": ("task", "Scheduled tasks", "SqueHub utilities"),
    "views": ("views-and-blade", "Views and Blade syntax", "Core concepts"),
}
V1_LEGACY_ALIASES = {
    "routes": "routing",
    "views": "views-and-blade",
}

V2_GROUPS = {
    "Getting started": "Installation Setup Dev FirstApplication DirectoryStructure Configuration Generators FeatureBlueprints Helpers Testing",
    "Application and HTTP": "Application Container Plugins Routing Controllers Middleware Http Responses Errors Sessions Csrf Validation Forms SignedUrls",
    "Views and frontend": "Views ViewResponses PackageViews CompiledViews ViewDiagnosticsTesting Fragments TemplateUtilities Includes Components ViewContext TemplateCompiler Conditionals Loops Layouts Assets ViewSecurity FrontendAssets FrontendProfiles SpaRouting FrontendAuth",
    "Database and ORM": "Database Schema Migrations Models Relationships Collections Pagination Scopes SoftDeletes Seeders Factories ApplicationData",
    "Identity and security": "Security Authentication MFA OAuth Authorization RBAC AccountSecurity RateLimiting Cryptography BrowserSecurityPolicy ApiTokens",
    "API and integration": "ApiDevelopment ApplicationContract ApiVerification SdkGeneration ApiResources ApiResponses ApiVersioning Cors Webhooks AgentAndAI HttpClient",
    "Services and infrastructure": "Packages Kits ActivationRegistry LargeApplications Contributions ReviewableChanges Internationalization Cache InfrastructureAdapters Redis Events Storage ProviderStorage Mail ProviderMail Notifications Queue QueueComposition RedisQueue Broadcasting Scheduler Retries Locks CircuitBreaker Idempotency",
    "Operations and deployment": "Status Cli Setup Dev Studio Logging Diagnostics Observability Profiler Correlation PerformanceCaching Health Performance Deployment DeploymentProof ProjectBundles UpgradePreflight Recovery BackupAndPortability Troubleshooting",
    "Upgrade": "UpgradeFromV1",
}
V2_CATEGORY: dict[str, str] = {}
for category, stems in V2_GROUPS.items():
    for stem in stems.split():
        V2_CATEGORY.setdefault(stem, category)
V2_ORDER = {
    stem: (group_index, position)
    for group_index, stems in enumerate(V2_GROUPS.values())
    for position, stem in enumerate(stems.split())
    if stem not in {earlier for previous in list(V2_GROUPS.values())[:group_index]
                    for earlier in previous.split()}
}
V1_ORDER = {slug: position for position, slug in enumerate([
    "installation", "directory-structure", "configuration", "cli", "namespace",
    "backup-and-upgrade", "routing", "controllers", "middleware", "views-and-blade",
    "models", "migrations", "dumper", "helper", "packages", "mail",
    "notification", "task",
])}

SAFE_TAGS = {
    "a", "blockquote", "br", "code", "dd", "div", "dl", "dt", "em",
    "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "kbd", "li",
    "main", "ol", "p", "pre", "s", "section", "small", "span", "strong",
    "sub", "sup", "table", "tbody", "td", "th", "thead", "tr", "u", "ul",
}
VOID_TAGS = {"br", "hr"}
DROP_CONTENT = {"button", "form", "iframe", "object", "script", "style", "svg"}
DROP_VOID = {"input", "link", "meta"}
SLUG_PATTERN = re.compile(r"\A[a-z0-9]+(?:-[a-z0-9]+)*\Z")


def slug(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    normalized = normalized.encode("ascii", "ignore").decode("ascii").lower()
    return re.sub(r"[^a-z0-9]+", "-", normalized).strip("-") or "section"


def file_slug(stem: str) -> str:
    if stem == "UpgradeFromV1":
        return "upgrade-from-v1"
    words = re.sub(r"([a-z0-9])([A-Z])", r"\1-\2", stem)
    words = re.sub(r"([A-Z])([A-Z][a-z])", r"\1-\2", words)
    return slug(words)


def plain(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]*>", " ", value))).strip()


def prose_summary(source: str) -> str:
    """Use the first real paragraph rather than a heading or code fence."""
    paragraphs = re.split(r"\n\s*\n", source.replace("\r\n", "\n"))
    for block in paragraphs:
        block = block.strip()
        if not block or block.startswith(("#", "```", "~~~", "|", "- ", "* ")):
            continue
        block = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", block)
        block = block.replace("`", "").replace("**", "").replace("*", "")
        return article_summary(plain(block))
    return ""


def searchable_markdown(source: str) -> str:
    """Index visible prose and code without Markdown punctuation noise."""
    source = re.sub(r"(?m)^\s*#{1,6}\s+", "", source)
    source = re.sub(r"(?m)^\s*(?:```|~~~)[^\n]*$", "", source)
    source = re.sub(r"(?m)^\s*\|?\s*:?-{3,}:?(?:\s*\|\s*:?-{3,}:?)+\s*\|?\s*$", "", source)
    source = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", source)
    source = re.sub(r"(?m)^\s*(?:[-*+] |\d+\. )", "", source)
    source = source.replace("`", "").replace("*", "").replace("|", " ")
    return plain(source)


def safe_link(value: str, version: str, public_stems: dict[str, str]) -> str | None:
    value = html.unescape(value.strip())
    if not value or any(ord(character) < 32 for character in value):
        return None
    if value.startswith("#"):
        return value if re.fullmatch(r"#[A-Za-z0-9_-]{1,100}", value) else None
    parsed = urlsplit(value)
    if version == "v1.x":
        legacy_path = parsed.path.rstrip("/")
        if parsed.scheme in {"", "http", "https"} \
                and parsed.netloc.lower() in {"squehub.com", "www.squehub.com", ""} \
                and legacy_path.startswith("/docs/") \
                and not re.match(r"^/docs/v(?:1|2)\.x(?:/|$)", legacy_path):
            legacy_slug = legacy_path.removeprefix("/docs/")
            legacy_slug = V1_LEGACY_ALIASES.get(legacy_slug, legacy_slug)
            if legacy_slug in {record[0] for record in V1_TITLES.values()}:
                return "/docs/v1.x/" + legacy_slug
    if parsed.scheme in {"https", "http"} and parsed.netloc:
        return value
    if parsed.scheme or parsed.netloc:
        return None
    if value.startswith("/docs/v1.x") or value.startswith("/docs/v2.x"):
        return value if not re.search(r"[\\\x00-\x1f]", value) else None
    if version == "v2.x" and parsed.path.endswith(".md"):
        target = Path(parsed.path).stem
        if target in INTERNAL_STATUS_STEMS:
            return "/docs/v2.x/status"
        mapped = public_stems.get(target)
        if mapped is None:
            return None
        fragment = f"#{parsed.fragment}" if re.fullmatch(r"[A-Za-z0-9_-]{1,100}", parsed.fragment) else ""
        return f"/docs/v2.x/{mapped}{fragment}"
    return None


def inline(source: str, public_stems: dict[str, str]) -> str:
    tokens: list[str] = []

    def hold(markup: str) -> str:
        marker = f"DOCSTOKEN{len(tokens)}END"
        tokens.append(markup)
        return marker

    source = re.sub(
        r"`([^`\n]+)`",
        lambda match: hold("<code>" + html.escape(match.group(1)) + "</code>"),
        source,
    )

    def replace_link(match: re.Match[str]) -> str:
        label, target = match.group(1), match.group(2)
        destination = safe_link(target, "v2.x", public_stems)
        if destination is None:
            return label
        if Path(urlsplit(target).path).stem in INTERNAL_STATUS_STEMS:
            label = "v2 status"
        return hold(
            '<a href="' + html.escape(destination, quote=True) + '">'
            + html.escape(label) + "</a>"
        )

    source = re.sub(r"(?<!!)\[([^\]]+)\]\(([^)]+)\)", replace_link, source)
    output = html.escape(source)
    output = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", output)
    output = re.sub(r"(?<!\*)\*([^*]+)\*(?!\*)", r"<em>\1</em>", output)
    for index, markup in enumerate(tokens):
        output = output.replace(f"DOCSTOKEN{index}END", markup)
    return output


def code_block(lines: list[str], language: str) -> str:
    label = re.sub(r"[^A-Za-z0-9_+.-]", "", language)[:24] or "Text"
    escaped = html.escape("\n".join(lines))
    return (
        '<div class="code-block"><div class="code-bar"><span>'
        + html.escape(label.upper())
        + '</span><button class="copy-button" type="button" aria-label="Copy code">Copy</button>'
        + '</div><pre><code class="language-'
        + html.escape(slug(label), quote=True)
        + '">' + escaped + '</code></pre></div>'
    )


def table_cells(line: str) -> list[str]:
    """Split Markdown table columns while retaining escaped command pipes."""
    return [cell.strip().replace(r"\|", "|")
            for cell in re.split(r"(?<!\\)\|", line.strip().strip("|"))]


def render_markdown(source: str, public_stems: dict[str, str]) -> tuple[str, list[dict[str, object]], str]:
    lines = source.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    output: list[str] = []
    headings: list[dict[str, object]] = []
    used_ids: set[str] = set()
    paragraphs: list[str] = []
    list_kind: str | None = None
    index = 0
    first_title_rendered = False

    def flush_paragraph() -> None:
        if paragraphs:
            output.append("<p>" + inline(" ".join(part.strip() for part in paragraphs), public_stems) + "</p>")
            paragraphs.clear()

    def close_list() -> None:
        nonlocal list_kind
        if list_kind is not None:
            output.append(f"</{list_kind}>")
            list_kind = None

    while index < len(lines):
        line = lines[index]
        stripped = line.strip()
        fence = re.match(r"^\s*(```|~~~)([^`]*)$", line)
        if fence:
            flush_paragraph()
            close_list()
            marker = fence.group(1)
            language = fence.group(2).strip().split(" ", 1)[0]
            block: list[str] = []
            index += 1
            while index < len(lines) and not lines[index].lstrip().startswith(marker):
                block.append(lines[index])
                index += 1
            output.append(code_block(block, language))
            index += 1
            continue
        if not stripped:
            flush_paragraph()
            close_list()
            index += 1
            continue
        heading = re.match(r"^(#{1,6})\s+(.+?)\s*#*\s*$", line)
        if heading:
            flush_paragraph()
            close_list()
            level = len(heading.group(1))
            title = plain(heading.group(2).replace("`", "").replace("**", "").replace("*", ""))
            anchor = slug(title)
            base = anchor
            suffix = 2
            while anchor in used_ids:
                anchor = f"{base}-{suffix}"
                suffix += 1
            used_ids.add(anchor)
            # The portal frame owns the page H1; omit the source's first H1.
            if not (level == 1 and not first_title_rendered):
                output.append(f'<h{level} id="{anchor}">' + inline(heading.group(2), public_stems) + f"</h{level}>")
            first_title_rendered = True
            if level in (2, 3):
                headings.append({"level": level, "id": anchor, "title": title})
            index += 1
            continue
        if re.match(r"^\s*(?:---+|\*\*\*+)\s*$", line):
            flush_paragraph()
            close_list()
            output.append("<hr>")
            index += 1
            continue
        if stripped.startswith("|") and index + 1 < len(lines) and re.fullmatch(
            r"\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*", lines[index + 1]
        ):
            flush_paragraph()
            close_list()
            cells = table_cells(stripped)
            output.append("<table><thead><tr>" + "".join("<th>" + inline(cell, public_stems) + "</th>" for cell in cells) + "</tr></thead><tbody>")
            index += 2
            while index < len(lines) and lines[index].lstrip().startswith("|"):
                row = table_cells(lines[index])
                output.append("<tr>" + "".join("<td>" + inline(cell, public_stems) + "</td>" for cell in row) + "</tr>")
                index += 1
            output.append("</tbody></table>")
            continue
        item = re.match(r"^\s*([-*+] |\d+\. )(.+)$", line)
        if item:
            flush_paragraph()
            kind = "ol" if item.group(1)[0].isdigit() else "ul"
            if list_kind != kind:
                close_list()
                output.append(f"<{kind}>")
                list_kind = kind
            output.append("<li>" + inline(item.group(2), public_stems) + "</li>")
            index += 1
            continue
        if stripped.startswith(">"):
            flush_paragraph()
            close_list()
            output.append("<blockquote><p>" + inline(stripped[1:].strip(), public_stems) + "</p></blockquote>")
            index += 1
            continue
        close_list()
        paragraphs.append(line)
        index += 1
    flush_paragraph()
    close_list()
    result = "\n".join(output) + "\n"
    return result, headings, searchable_markdown(source)


class HistoricalSanitizer(HTMLParser):
    """Keep editorial v1 markup while discarding executable template controls."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self.parts: list[str] = []
        self.dropped = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if self.dropped:
            if tag in DROP_CONTENT:
                self.dropped += 1
            return
        if tag in DROP_VOID:
            return
        if tag in DROP_CONTENT:
            self.dropped = 1
            return
        if tag not in SAFE_TAGS:
            return
        attributes: list[str] = []
        for name, value in attrs:
            if value is None:
                continue
            if tag == "a" and name == "href":
                destination = safe_link(value, "v1.x", {})
                if destination is not None:
                    attributes.append('href="' + html.escape(destination, quote=True) + '"')
            if tag in {"h1", "h2", "h3", "h4", "h5", "h6"} and name == "id" \
                    and re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,99}", value):
                attributes.append('id="' + html.escape(value, quote=True) + '"')
        self.parts.append("<" + tag + (" " + " ".join(attributes) if attributes else "") + ">")

    def handle_endtag(self, tag: str) -> None:
        if self.dropped:
            if tag in DROP_CONTENT:
                self.dropped -= 1
            return
        if tag in SAFE_TAGS and tag not in VOID_TAGS:
            self.parts.append(f"</{tag}>")

    def handle_data(self, data: str) -> None:
        if not self.dropped:
            self.parts.append(html.escape(data))

    def handle_entityref(self, name: str) -> None:
        if not self.dropped:
            self.parts.append(f"&{name};")

    def handle_charref(self, name: str) -> None:
        if not self.dropped:
            self.parts.append(f"&#{name};")


def render_v1(source: str) -> tuple[str, list[dict[str, object]], str]:
    start = source.find("@section('content')")
    if start < 0:
        raise ValueError("Historical template lacks a content section")
    body = source[start + len("@section('content')"):]
    body = body.split("@include('docs.v1x.parts.", 1)[0]
    body = re.sub(r"\{\{\s*'v'\s*\.\s*\$fullCurrentVersion\s*\}\}", "v1.x (archive)", body)
    body = re.sub(r"</?main(?:\s[^>]*)?>", "", body, flags=re.IGNORECASE)
    sanitizer = HistoricalSanitizer()
    sanitizer.feed(body)
    result = "".join(sanitizer.parts)
    headings: list[dict[str, object]] = []
    used_ids: set[str] = set()

    def add_heading(match: re.Match[str]) -> str:
        level = int(match.group(1))
        # Older pages often jump from H2 straight to H4. Flatten those H4s
        # into H3 so the public reader's H2/H3 table of contents can show them.
        if level == 4:
            level = 3
        attrs = match.group(2)
        title = plain(match.group(3))
        old = re.search(r'\bid="([A-Za-z][A-Za-z0-9_-]{0,99})"', attrs)
        anchor = old.group(1) if old else slug(title)
        base = anchor
        suffix = 2
        while anchor in used_ids:
            anchor = f"{base}-{suffix}"
            suffix += 1
        used_ids.add(anchor)
        if level in (2, 3):
            headings.append({"level": level, "id": anchor, "title": title})
        return f'<h{level} id="{anchor}">{match.group(3)}</h{level}>'

    result = re.sub(r"<h([1-6])([^>]*)>(.*?)</h\1>", add_heading, result, flags=re.DOTALL)
    result = re.sub(
        r"<pre>\s*<code>(.*?)</code>\s*</pre>",
        lambda match: '<div class="code-block"><div class="code-bar"><span>CODE</span>'
        '<button class="copy-button" type="button" aria-label="Copy code">Copy</button>'
        '</div><pre><code>' + match.group(1) + '</code></pre></div>',
        result, flags=re.DOTALL,
    )
    search_html = re.sub(r'<div class="code-bar">.*?</div>', "", result, flags=re.DOTALL)
    return result.strip() + "\n", headings, plain(search_html)


def article_summary(search: str, limit: int = 240) -> str:
    summary = search.strip()
    if len(summary) <= limit:
        return summary
    return summary[:limit].rsplit(" ", 1)[0].rstrip(".,;:") + "…"


def build(core_root: Path, historical_root: Path, output_root: Path) -> dict[str, object]:
    if not core_root.is_dir() or not historical_root.is_dir():
        raise ValueError("Both documentation source directories must exist")
    public_files = [path for path in core_root.glob("*.md") if path.stem not in INTERNAL_V2 and not path.is_symlink()]
    public_stems = {path.stem: file_slug(path.stem) for path in public_files}
    if len(set(public_stems.values())) != len(public_stems):
        raise ValueError("Two public v2 guides resolve to the same slug")
    v2_pages: list[dict[str, object]] = []
    v1_pages: list[dict[str, object]] = []
    for path in sorted(public_files, key=lambda item: (*V2_ORDER.get(item.stem, (99, 99)), item.name)):
        text = path.read_text(encoding="utf-8-sig")
        first = re.search(r"^#\s+(.+)$", text, re.MULTILINE)
        if first is None:
            raise ValueError(f"V2 guide lacks a title: {path.name}")
        title = plain(first.group(1).replace("`", ""))
        fragment, headings, search = render_markdown(text, public_stems)
        page_slug = public_stems[path.stem]
        target = output_root / "Pages" / "v2.x" / f"{page_slug}.html"
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(fragment, encoding="utf-8")
        v2_pages.append({
            "slug": page_slug, "title": title,
            "category": V2_CATEGORY.get(path.stem, "Reference"),
            "summary": prose_summary(text), "headings": headings,
            "search": search, "public": True,
            "fragment": f"Pages/v2.x/{page_slug}.html",
        })
    # README is an internal working-tree index. A short curated public landing
    # page avoids publishing its untracked/release-status notes as user guidance.
    v2_home = (
        '<h2 id="start-here">Start here</h2><p>These guides describe the current '
        'SqueHub v2.0.0 development source. Begin with '
        '<a href="/docs/v2.x/installation">installation</a>, then build a '
        '<a href="/docs/v2.x/first-application">first application</a>. '
        'Version 2.0.0 is not yet a published Composer release.</p>'
        '<h2 id="learning-paths">Choose a path</h2>'
        '<div class="portal-grid">'
        '<a class="portal-card" href="/docs/v2.x/first-application"><span>Application foundations</span>'
        '<strong>Build a page</strong><small>Routes, controllers, Views, forms, and data.</small></a>'
        '<a class="portal-card" href="/docs/v2.x/api-development"><span>APIs</span>'
        '<strong>Design an endpoint</strong><small>Requests, responses, contracts, and verification.</small></a>'
        '<a class="portal-card" href="/docs/v2.x/agent-and-ai"><span>Agent and AI</span>'
        '<strong>Connect a local AI host</strong><small>Optional MCP STDIO inspection and review-only plans under explicit capabilities.</small></a>'
        '<a class="portal-card" href="/docs/v2.x/status"><span>Operations</span>'
        '<strong>Check v2 status</strong><small>Release boundaries, qualified environments, and Package/Kit availability.</small></a>'
        '</div>'
        '<p>Continue with <a href="/docs/v2.x/deployment">deployment</a>, '
        '<a href="/docs/v2.x/health">Health and Doctor</a>, or the '
        '<a href="/docs/v2.x/upgrade-from-v1">v1 upgrade guide</a>.</p>\n'
    )
    home_path = output_root / "Pages" / "v2.x" / "index.html"
    home_path.parent.mkdir(parents=True, exist_ok=True)
    home_path.write_text(v2_home, encoding="utf-8")
    v2_pages.insert(0, {
        "slug": "index", "title": "SqueHub v2.x documentation",
        "category": "Getting started",
        "summary": "Start with installation, your first application, and the current SqueHub v2 guides.",
        "headings": [{"level": 2, "id": "start-here", "title": "Start here"},
                     {"level": 2, "id": "learning-paths", "title": "Choose a path"}],
        "search": plain(v2_home), "public": True, "fragment": "Pages/v2.x/index.html",
    })
    if len(set(V1_TITLES) - {path.name.removesuffix(".squehub.php") for path in historical_root.glob("*.squehub.php")}) != 0:
        raise ValueError("A historical v1 article is missing")
    for path in sorted(historical_root.glob("*.squehub.php")):
        if path.is_symlink() or path.name.removesuffix(".squehub.php") not in V1_TITLES:
            continue
        old_slug, title, category = V1_TITLES[path.name.removesuffix(".squehub.php")]
        fragment, headings, search = render_v1(path.read_text(encoding="utf-8-sig"))
        target = output_root / "Pages" / "v1.x" / f"{old_slug}.html"
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(fragment, encoding="utf-8")
        v1_pages.append({
            "slug": old_slug, "title": title, "category": category,
            "summary": article_summary(search), "headings": headings,
            "search": search, "public": True,
            "fragment": f"Pages/v1.x/{old_slug}.html",
        })
    v1_pages.sort(key=lambda page: V1_ORDER[page["slug"]])
    all_pages = {("v1.x", page["slug"]): page for page in v1_pages}
    all_pages.update({("v2.x", page["slug"]): page for page in v2_pages})
    anchors = {
        key: set(re.findall(r'\bid="([A-Za-z0-9_-]+)"',
                            (output_root / page["fragment"]).read_text(encoding="utf-8")))
        for key, page in all_pages.items()
    }
    # Source documents can retain a link to a renamed heading. Keep its valid
    # article destination but remove a fragment that cannot resolve in this
    # build, so the public portal never publishes a dead section jump.
    for page in all_pages.values():
        fragment_path = output_root / page["fragment"]
        rendered = fragment_path.read_text(encoding="utf-8")

        def known_section(match: re.Match[str]) -> str:
            version, target_slug, anchor = match.groups()
            if anchor in anchors.get((version, target_slug), set()):
                return match.group(0)
            return f'href="/docs/{version}/{target_slug}"'

        rendered = re.sub(
            r'href="/docs/(v[12]\.x)/([a-z0-9-]+)#([A-Za-z0-9_-]+)"',
            known_section, rendered,
        )
        fragment_path.write_text(rendered, encoding="utf-8")
    manifest: dict[str, object] = {
        "schema_version": 1,
        "versions": {
            "v1.x": {"label": "v1.x (archive)", "home": "installation", "pages": v1_pages},
            "v2.x": {"label": "v2.x (development)", "home": "index", "pages": v2_pages},
        },
    }
    output_root.mkdir(parents=True, exist_ok=True)
    (output_root / "manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--core-docs-root", type=Path, required=True)
    parser.add_argument("--v1-templates-root", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    arguments = parser.parse_args()
    try:
        manifest = build(arguments.core_docs_root, arguments.v1_templates_root, arguments.output_dir)
    except (OSError, ValueError) as error:
        print(f"Documentation build failed: {error}", file=sys.stderr)
        return 1
    for version, section in manifest["versions"].items():
        print(f"{version}: {len(section['pages'])} public pages")
    print(f"Output: {arguments.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
