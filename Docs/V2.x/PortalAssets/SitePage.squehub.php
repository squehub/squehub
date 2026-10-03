<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="{{ $summary }}">
    <meta name="robots" content="{{ $robots }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }} · SqueHub">
    <meta property="og:description" content="{{ $summary }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="https://www.squehub.com/assets/images/og/squehub.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }} · SqueHub">
    <meta name="twitter:description" content="{{ $summary }}">
    <meta name="twitter:image" content="https://www.squehub.com/assets/images/og/squehub.png">
    <link rel="canonical" href="{{ $canonical }}">
    <link rel="icon" href="/assets/docs/images/squehub-icon.png" type="image/png">
    <link rel="stylesheet" href="/assets/docs/css/site.css">
    <link rel="stylesheet" href="/assets/docs/css/portal.css?v=20261003c">
    <link rel="stylesheet" href="/assets/docs/css/ecosystem.css?v=20261003c">
    <link rel="stylesheet" href="/assets/docs/css/preloader.css?v=20261003b">
    <script src="/assets/docs/js/preloader.js?v=20261003b"></script>
    <script defer src="/assets/docs/js/search-index.js?v=20261003e"></script>
    <script defer src="/assets/docs/js/docs.js?v=20261003e"></script>
    <title>{{ $title }} · SqueHub</title>
</head>
<body class="ecosystem-page" data-version="v2.x" data-site-root="/">
<div id="squehub-preloader" aria-hidden="true"><div class="squehub-preloader-mark"><img class="squehub-preloader-piece squehub-preloader-left" src="/assets/docs/images/sq-l.png" alt="" width="230" height="267"><img class="squehub-preloader-piece squehub-preloader-right" src="/assets/docs/images/sq-r.png" alt="" width="202" height="231"></div></div>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="header-inner">
        <button class="icon-button mobile-menu-button" type="button" data-menu-open
            aria-label="Open site menu" aria-expanded="false"><span class="hamburger"></span></button>
        <a class="brand" href="/" aria-label="SqueHub home">
            <span class="brand-mark" aria-hidden="true"><img src="/assets/docs/images/squehub-icon.png" alt="" width="180" height="203"></span><span>SqueHub</span>
        </a>
        <nav class="top-nav" aria-label="Primary navigation">
            <a href="/">Home</a>
            <a href="/docs/v2.x">Docs</a>
            <a href="/packages" aria-current="{{ $active === 'packages' ? 'page' : 'false' }}">Packages</a>
            <a href="/kits" aria-current="{{ $active === 'kits' ? 'page' : 'false' }}">Kits</a>
            <a href="/community" aria-current="{{ $active === 'community' ? 'page' : 'false' }}">Community</a>
            <a href="/partners" aria-current="{{ $active === 'partners' ? 'page' : 'false' }}">Partners</a>
        </nav>
        <div class="header-actions">
            <button class="search-trigger" type="button" data-search-open aria-label="Search documentation">
                <span class="search-icon" aria-hidden="true"></span><span class="search-label">Search docs</span><kbd>Ctrl K</kbd>
            </button>
            <button class="theme-button icon-button" type="button" aria-label="Appearance: system" title="Appearance: system"><span aria-hidden="true">◐</span></button>
        </div>
    </div>
</header>
<nav class="mobile-site-nav" aria-label="Mobile primary navigation" hidden>
    <a href="/">Home</a><a href="/docs/v2.x">Docs</a><a href="/packages">Packages</a>
    <a href="/kits">Kits</a><a href="/community">Community</a><a href="/partners">Partners</a>
    <a href="/changelogs">Changelogs</a><a href="/contact">Contact</a>
</nav>
<div class="current-version-banner" role="note"><span>Current SqueHub version</span><strong>v2.0.0</strong></div>
<main id="main" class="ecosystem-main">
    <div class="ecosystem-shell">
        <nav class="ecosystem-breadcrumb" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span aria-current="page">{{ $title }}</span></nav>
        <header class="ecosystem-hero">
            <span class="eyebrow">{{ $eyebrow }}</span>
            <h1>{{ $title }}</h1>
            <p>{{ $summary }}</p>
        </header>
        <div class="ecosystem-content">{!! $bodyHtml !!}</div>
    </div>
</main>
<footer class="site-footer">
    <div><strong>SqueHub</strong><p>A modern PHP framework built for clarity and portability.</p></div>
    <nav aria-label="Footer navigation">
        <a href="/">Home</a><a href="/docs/v2.x">Documentation</a>
        <a href="/packages">Packages</a><a href="/kits">Kits</a>
        <a href="/community">Community</a><a href="/partners">Partners</a><a href="/changelogs">Changelogs</a>
        <a href="/contact">Contact</a>
    </nav>
    <small>SqueHub v2.0.0</small>
</footer>
<div class="search-overlay" hidden>
    <div class="search-backdrop" data-search-close></div>
    <section class="search-dialog" role="dialog" aria-modal="true" aria-labelledby="search-title">
        <div class="search-dialog-head"><span class="search-icon" aria-hidden="true"></span>
            <h2 id="search-title" class="sr-only">Search documentation</h2>
            <input id="search-input" type="search" placeholder="Search documentation…" autocomplete="off"
                role="combobox" aria-autocomplete="list" aria-controls="search-results"
                aria-expanded="false" aria-label="Search documentation">
            <button type="button" data-search-close aria-label="Close search">Esc</button>
        </div>
        <div class="search-results" id="search-results" role="listbox" aria-label="Documentation search results" aria-live="polite"></div>
        <div class="search-dialog-foot"><span>↑ ↓ to navigate</span><span>↵ to open</span><span>Esc to close</span></div>
    </section>
</div>
</body>
</html>
