<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $title }} · SqueHub Studio</title>
    <link rel="icon" href="{{ $iconUrl }}" type="image/png">
    <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Studio navigation">
        <a class="brand" href="{{ $homeUrl }}"><span class="brand-mark"><img src="{{ $iconUrl }}" alt="" width="180" height="203"></span><span>SqueHub <strong>Studio</strong></span></a>
        <p class="sidebar-caption">Inspect · Plan · Prove</p>
        <nav>
            @foreach($navigation as $link)
                <a class="nav-link" href="{{ $link['url'] }}" @if($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach
        </nav>
        <p class="sidebar-note">Local development inspection. Pages are read-only.</p>
    </aside>
    <main class="content" id="main">
        <header class="page-header">
            <p class="eyebrow">SqueHub Studio / {{ $state }}</p>
            <h1>{{ $title }}</h1>
            <p>Framework metadata and bounded observations from this application.</p>
        </header>
        @if(count($cards) === 0)
            <section class="empty"><h2>No records available</h2><p>This section is empty or not available from the current application state.</p></section>
        @else
            <div class="cards">
                @foreach($cards as $card)
                    <section class="card">
                        <h2>{{ $card['heading'] }}</h2>
                        <dl>
                            @foreach($card['fields'] as $field)
                                <div class="field"><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                            @endforeach
                        </dl>
                        @if($card['url'] !== null)
                            <a class="details" href="{{ $card['url'] }}">Open profile <span aria-hidden="true">→</span></a>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </main>
</div>
</body>
</html>
