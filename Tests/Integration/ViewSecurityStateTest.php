<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Core\View;
use App\Foundation\Application;
use App\Session\Session;
use App\Session\SessionManager;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises session presentation through compiled Views and real flash generations. */
final class ViewSecurityStateTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        RuntimeContext::select(new Application($this->project->path()));
        View::initViewPaths();
    }

    protected function tearDown(): void
    {
        Session::setResolver(null);
        $this->project->remove();
        parent::tearDown();
    }

    public function testSessionTestsPresenceRatherThanTruthinessAndRestoresValue(): void
    {
        $this->project->write('Project/Views/Pages/Values.squehub.php', <<<'TEMPLATE'
@session('null')<null>{{ $value === null ? 'present' : 'wrong' }}</null>@endsession
@session('false')<false>{{ $value === false ? 'present' : 'wrong' }}</false>@endsession
@session('zero')<zero>{{ $value === 0 ? 'present' : 'wrong' }}</zero>@endsession
@session('empty')<empty>{{ $value === '' ? 'present' : 'wrong' }}</empty>@endsession
@session('array')<array>{{ is_array($value) && $value === [] ? 'present' : 'wrong' }}</array>@endsession
@session('missing')<missing>wrong</missing>@else<absent>{{ $value }}</absent>@endsession
<after>{{ $value }}</after>
TEMPLATE);
        $manager = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $manager);
        foreach (['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'array' => []] as $key => $value) {
            $manager->store()->put($key, $value);
        }

        $output = $this->render('Pages.Values', ['value' => 'caller']);
        foreach (['null', 'false', 'zero', 'empty', 'array'] as $tag) {
            self::assertStringContainsString('<' . $tag . '>present</' . $tag . '>', $output);
        }
        self::assertStringContainsString('<absent>caller</absent>', $output);
        self::assertStringContainsString('<after>caller</after>', $output);
        self::assertStringNotContainsString('<missing>', $output);
    }

    public function testNestedSessionBindingsRestoreTheOuterValue(): void
    {
        $this->project->write('Project/Views/Pages/Nested.squehub.php', <<<'TEMPLATE'
@session('outer')<outer>{{ $value }}</outer>
    @session('inner')<inner>{{ $value }}</inner>@endsession
    <outer-again>{{ $value }}</outer-again>
@endsession
<caller>{{ $value }}</caller>
TEMPLATE);
        $manager = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $manager);
        $manager->store()->put('outer', 'first');
        $manager->store()->put('inner', 'second');

        $output = $this->render('Pages.Nested', ['value' => 'original']);
        self::assertStringContainsString('<outer>first</outer>', $output);
        self::assertStringContainsString('<inner>second</inner>', $output);
        self::assertStringContainsString('<outer-again>first</outer-again>', $output);
        self::assertStringContainsString('<caller>original</caller>', $output);
    }

    public function testFlashPresentationDoesNotConsumeOrExtendItsLifetime(): void
    {
        $this->project->write('Project/Views/Pages/Flash.squehub.php',
            "@session('status')<notice>{{ \$value }}</notice>@else<none>missing</none>@endsession");
        $manager = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $manager);
        $manager->store()->flash('status', 'saved');
        $manager->store()->close();

        self::assertStringContainsString('<notice>saved</notice>', $this->render('Pages.Flash'));
        self::assertStringContainsString('<notice>saved</notice>', $this->render('Pages.Flash'));
        self::assertTrue($manager->store()->has('status'));
        $manager->store()->close();
        self::assertStringContainsString('<none>missing</none>', $this->render('Pages.Flash'));
        self::assertFalse($manager->store()->has('status'));
    }

    public function testCompiledViewUsesCurrentSessionAndHasSafeAbsentBranchWithoutSession(): void
    {
        $this->project->write('Project/Views/Pages/Current.squehub.php',
            "@session('status')<status>{{ \$value }}</status>@else<none>missing</none>@endsession");
        $first = $this->arraySession();
        $first->store()->put('status', 'one');
        Session::setResolver(static fn (): SessionManager => $first);
        self::assertStringContainsString('<status>one</status>', $this->render('Pages.Current'));

        $second = $this->arraySession();
        $second->store()->put('status', 'two');
        Session::setResolver(static fn (): SessionManager => $second);
        self::assertStringContainsString('<status>two</status>', $this->render('Pages.Current'));
        Session::setResolver(null);
        self::assertStringContainsString('<none>missing</none>', $this->render('Pages.Current'));
        Session::setResolver(static fn (): SessionManager => $first);
        self::assertStringContainsString('<status>one</status>', $this->render('Pages.Current'));
    }

    public function testSessionBranchControlsComponentAndAssetCollection(): void
    {
        $this->project->write('Project/Views/Pages/ConditionalAssets.squehub.php', <<<'TEMPLATE'
@stack('styles')@stack('scripts')
@session('feature')
    @script('/assets/feature.js')
    @component('FeatureBadge')@endcomponent
@endsession
TEMPLATE);
        $this->project->write('Project/Views/Components/FeatureBadge.squehub.php',
            "@props([])@style('/assets/feature.css')<badge>enabled</badge>");
        $manager = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $manager);

        $absent = $this->render('Pages.ConditionalAssets');
        self::assertStringNotContainsString('/assets/feature.js', $absent);
        self::assertStringNotContainsString('/assets/feature.css', $absent);
        self::assertStringNotContainsString('<badge>', $absent);

        $manager->store()->put('feature', false);
        $present = $this->render('Pages.ConditionalAssets');
        self::assertStringContainsString('/assets/feature.js', $present);
        self::assertStringContainsString('/assets/feature.css', $present);
        self::assertStringContainsString('<badge>enabled</badge>', $present);
    }

    public function testSessionPresentationComposesAcrossLayoutsPartialsComponentsSlotsAndLoops(): void
    {
        $this->project->write('Project/Views/Layouts/Security.squehub.php', <<<'TEMPLATE'
<layout>@session('status')<layout-status>{{ $value }}</layout-status>@endsession
@yield('content')</layout>
TEMPLATE);
        $this->project->write('Project/Views/Partials/Security.squehub.php',
            "@session('status')<partial-status>{{ \$value }}</partial-status>@endsession");
        $this->project->write('Project/Views/Components/SecurityShell.squehub.php', <<<'TEMPLATE'
@props([])
<shell>@session('status')<component-status>{{ $value }}</component-status>@endsession
{!! $slots->get('header')->toHtml() !!}{!! $slot->toHtml() !!}</shell>
TEMPLATE);
        $this->project->write('Project/Views/Pages/Composite.squehub.php', <<<'TEMPLATE'
@extends('Layouts.Security')
@section('content')
@include('Partials.Security')
@component('SecurityShell')
    @slot('header')
        @session('status')<slot-status>{{ $value }}</slot-status>@endsession
    @endslot
    @foreach($items as $item)
        @session('status')<loop-status>{{ $loop->iteration }}:{{ $value }}</loop-status>@endsession
    @endforeach
@endcomponent
@endsection
TEMPLATE);
        $manager = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $manager);
        $manager->store()->put('status', 'ready');

        $present = $this->render('Pages.Composite', ['items' => [1, 2]]);
        foreach (['layout', 'partial', 'component', 'slot'] as $owner) {
            self::assertStringContainsString('<' . $owner . '-status>ready</' . $owner . '-status>', $present);
        }
        self::assertStringContainsString('<loop-status>1:ready</loop-status>', $present);
        self::assertStringContainsString('<loop-status>2:ready</loop-status>', $present);

        $manager->store()->forget('status');
        $absent = $this->render('Pages.Composite', ['items' => [1, 2]]);
        self::assertStringNotContainsString('-status>', $absent);
        self::assertStringContainsString('<shell>', $absent);
    }

    private function arraySession(): SessionManager
    {
        return new SessionManager(new Repository(['session' => ['driver' => 'array']]));
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = []): string
    {
        ob_start();
        try {
            View::render($view, $data);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
