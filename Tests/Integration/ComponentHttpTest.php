<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Covers component output and safe failures at the HTTP response boundary. */
final class ComponentHttpTest extends TestCase
{
    public function testComponentSlotAttributesAndAssetsRenderInHttpResponse(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Show.squehub.php', <<<'TEMPLATE'
<head>@stack('styles')</head>
@component('Card', ['title' => $title], ['data-note' => $attribute])
    <p>{{ $body }}</p>
    @slot('footer')Footer@endslot
@endcomponent
TEMPLATE);
        $this->testApplication()->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
@props(['title'])
@style('/assets/card.css')
<card {!! $attributes->toHtml() !!}>
<h2>{{ $title }}</h2>{!! $slot->toHtml() !!}
<footer>{!! $slots->get('footer')->toHtml() !!}</footer>
</card>
TEMPLATE);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/components/show')->get(static function (): void {
    \App\Plugins\View::render('Pages.Show', [
        'title' => '<Title>',
        'body' => '<script>SLOT_SECRET</script>',
        'attribute' => 'note" onclick="ATTRIBUTE_SECRET',
    ]);
});
PHP);

        $body = $this->get('/components/show')->assertOk()->content();
        self::assertStringContainsString('/assets/card.css', $body);
        self::assertStringContainsString('<h2>&lt;Title&gt;</h2>', $body);
        self::assertStringContainsString('<p>&lt;script&gt;SLOT_SECRET&lt;/script&gt;</p>', $body);
        self::assertStringContainsString('<footer>Footer</footer>', $body);
        self::assertStringContainsString(
            'data-note="note&quot; onclick=&quot;ATTRIBUTE_SECRET"', $body);
        self::assertStringNotContainsString('onclick="ATTRIBUTE_SECRET"', $body);
    }

    public function testDebugMissingRequiredPropNamesLogicalComponent(): void
    {
        $this->testApplication()->write('Project/Views/Pages/MissingProp.squehub.php',
            "@component('Card')@endcomponent");
        $this->testApplication()->write('Project/Views/Components/Card.squehub.php',
            "@props(['title'])<h2>{{ \$title }}</h2>");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/components/missing-prop')->get(static function (): void {
    \App\Plugins\View::render('Pages.MissingProp');
});
PHP);
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/components/missing-prop')->assertStatus(500)->content();
        self::assertStringContainsString('Card', $body);
        self::assertStringContainsString('title', $body);
    }

    public function testProductionComponentFailuresHideSecretsPathsAndPartialOutput(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Missing.squehub.php',
            "PARTIAL_COMPONENT_OUTPUT\n@component('Absent')@endcomponent");
        $this->testApplication()->write('Project/Views/Pages/Required.squehub.php',
            "@component('Required')COMPONENT_SLOT_SECRET@endcomponent");
        $this->testApplication()->write('Project/Views/Components/Required.squehub.php',
            "@props(['title'])<h2>{{ \$title }}</h2>");
        $this->testApplication()->write('Project/Views/Pages/Unknown.squehub.php',
            "@component('Required', ['title' => 'ok', "
            . "'extra' => 'COMPONENT_PROP_SECRET'])@endcomponent");
        $this->testApplication()->write('Project/Views/Pages/InvalidAttribute.squehub.php',
            "@component('Plain', [], ['data-id' => ['ATTRIBUTE_SECRET']])@endcomponent");
        $this->testApplication()->write('Project/Views/Components/Plain.squehub.php',
            '<span>plain</span>');
        $this->testApplication()->write('Project/Views/Pages/Cycle.squehub.php',
            "@component('Cycle')@endcomponent");
        $this->testApplication()->write('Project/Views/Components/Cycle.squehub.php',
            "@component('Cycle')@endcomponent");
        $this->testApplication()->write('Project/Views/Pages/Good.squehub.php',
            '<main>Healthy</main>');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
foreach (['missing' => 'Missing', 'required' => 'Required',
    'unknown' => 'Unknown', 'invalid-attribute' => 'InvalidAttribute',
    'cycle' => 'Cycle', 'good' => 'Good'] as $path => $view) {
    \App\Routing\Route::path('/components/' . $path)->get(
        static function () use ($view): void {
            \App\Plugins\View::render('Pages.' . $view, [
                'sessionSecret' => 'SESSION_SECRET',
            ]);
        }
    );
}
PHP);
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['missing', 'required', 'unknown', 'invalid-attribute', 'cycle'] as $case) {
            $body = $this->get('/components/' . $case)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['PARTIAL_COMPONENT_OUTPUT', 'COMPONENT_SLOT_SECRET',
                'COMPONENT_PROP_SECRET', 'ATTRIBUTE_SECRET', 'SESSION_SECRET',
                'Pages.Missing', 'Components.Cycle', 'Storage/Cache'] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertSame('<main>Healthy</main>',
                $this->get('/components/good')->assertOk()->content());
        }
    }

    public function testOutsideRootLinkedComponentFailsSafelyOverHttp(): void
    {
        $outside = new TemporaryProject();
        $link = $this->testApplication()->root()
            . '/Project/Views/Components/Unsafe.squehub.php';
        try {
            $outside->write('Outside.squehub.php', 'OUTSIDE_COMPONENT_SECRET');
            $this->testApplication()->write('Project/Views/Pages/Unsafe.squehub.php',
                "@component('Unsafe')@endcomponent");
            $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/components/unsafe')->get(static function (): void {
    \App\Plugins\View::render('Pages.Unsafe');
});
PHP);
            if (!is_dir(dirname($link))) {
                mkdir(dirname($link), 0777, true);
            }
            if (!@symlink($outside->path('Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->app()->config()->set('app.env', 'production');
            $this->app()->config()->set('app.debug', false);

            $body = $this->get('/components/unsafe')->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            self::assertStringNotContainsString('OUTSIDE_COMPONENT_SECRET', $body);
            self::assertStringNotContainsString($outside->path(), $body);
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $outside->remove();
        }
    }
}
