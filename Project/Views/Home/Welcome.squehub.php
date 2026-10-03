<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A SqueHub application is ready to build. Start with a route, a View, and the parts your project needs.">
    <meta name="theme-color" content="#0b1927">
    <title>{{ $title ?? 'Welcome to SqueHub' }}</title>
    <link rel="icon" href="{{ asset('/assets/default/favicon/squehub-icon.png') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('/assets/css/welcome.css') }}">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="" aria-label="SqueHub application home">
            <img src="{{ asset('/assets/default/img/squehub-icon.png') }}" alt="SqueHub logo" width="180" height="203">
            <span>SqueHub <small>APPLICATION STARTER</small></span>
        </a>
        <nav aria-label="Page navigation">
            <a href="#next-steps">Next steps</a>
            <a href="https://www.squehub.com/" rel="noopener noreferrer">SqueHub website <span aria-hidden="true">↗</span></a>
        </nav>
    </header>

    <main id="main">
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow"><span aria-hidden="true"></span> YOUR SQUEHUB APPLICATION IS RUNNING</p>
                <h1 id="hero-title">A good beginning for <em>what you build next.</em></h1>
                <p class="hero-lead">The framework is ready. Define a route, shape a View, and add the data and services your application actually needs. Your application code has a clear home in <code>Project/</code>.</p>
                <div class="hero-actions">
                    <a class="button button-primary" href="#next-steps">Start building <span aria-hidden="true">↘</span></a>
                    <a class="button button-quiet" href="https://www.squehub.com/" rel="noopener noreferrer">Explore SqueHub <span aria-hidden="true">↗</span></a>
                </div>
                <div class="hero-meta"><span class="meta-light" aria-hidden="true"></span><span>A starter page you can replace as your application grows.</span></div>
            </div>

            <!-- The decorative diagram repeats no essential instructions. -->
            <div class="hero-art" aria-hidden="true">
                <div class="art-grid"></div>
                <div class="art-orbit art-orbit-outer"></div>
                <div class="art-orbit art-orbit-inner"></div>
                <div class="art-scene">
                    <div class="art-panel art-panel-back"><span>APPLICATION STRUCTURE</span><strong>Project/</strong><i></i><i></i><i></i></div>
                    <div class="art-panel art-panel-routes"><span>01 / ROUTES</span><strong>Route::path('/')</strong><small>Choose the request path</small></div>
                    <div class="art-panel art-panel-views"><span>02 / VIEWS</span><strong>Home/Welcome</strong><small>Design the response</small></div>
                    <div class="art-panel art-panel-data"><span>03 / DATA</span><strong>Models &amp; migrations</strong><small>Add them when needed</small></div>
                    <div class="art-node art-node-one"></div><div class="art-node art-node-two"></div>
                </div>
                <span class="art-caption">YOUR APPLICATION / YOUR DIRECTION</span>
            </div>
        </section>

        <section class="next-steps" id="next-steps" aria-labelledby="next-steps-title">
            <div class="section-heading"><p>THE FIRST MOVES <span>01—03</span></p><h2 id="next-steps-title">Find your starting point.</h2><span>These are ordinary files in your application. Change them to make this page your own.</span></div>
            <div class="step-grid">
                <article class="step-card"><span class="step-number">01 / REQUESTS</span><div class="step-icon" aria-hidden="true">↗</div><h3>Define a route</h3><p>Choose a path and a handler in the project route file. The current home route is named <code>welcome.page</code>.</p><code class="file-path">Project/Routes/Web.php</code></article>
                <article class="step-card"><span class="step-number">02 / INTERFACE</span><div class="step-icon" aria-hidden="true">▣</div><h3>Make the page yours</h3><p>Edit this View to present your own application. The View can use layouts, components, and SqueHub's template tools.</p><code class="file-path">Project/Views/Home/Welcome.squehub.php</code></article>
                <article class="step-card"><span class="step-number">03 / GROWTH</span><div class="step-icon" aria-hidden="true">◇</div><h3>Add what you need</h3><p>Bring in Models, migrations, authentication, jobs, or Packages as your project takes shape.</p><code class="file-path">Project/</code></article>
            </div>
        </section>
    </main>

    <footer class="site-footer"><span>Built with <strong>SqueHub</strong></span><a href="https://www.squehub.com/" rel="noopener noreferrer">Visit squehub.com <span aria-hidden="true">↗</span></a></footer>
</div>
</body>
</html>
