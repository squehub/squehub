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

/** Proves compiled form Views read the active Session on every render. */
final class FormViewStateTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        RuntimeContext::select(new Application($this->project->path()));
        $this->project->write('Project/Views/Pages/FormState.squehub.php', <<<'TEMPLATE'
<value>{{ old('email', 'fallback') }}</value>
<zero>{{ old('zero', 'fallback') }}</zero>
<empty>{{ old('empty', 'fallback') === '' ? 'empty' : 'wrong' }}</empty>
<false>{{ old('flag', true) === false ? 'false' : 'wrong' }}</false>
<array>{{ in_array('editor', old('roles', []), true) ? 'editor' : 'none' }}</array>
<password>{{ old('password') === null ? 'filtered' : 'leaked' }}</password>
<checkbox {{ checked(old('active', false) === true) }}></checkbox>
<option {{ selected(old('country', 'us') === 'ng') }}></option>
@error('email')<field>{{ $message }}</field>@enderror
@if($errors->any())
<summary>
@foreach($errors->all() as $field => $messages)
    @foreach($messages as $item)
        <entry>{{ $field }}:{{ $item }}</entry>
    @endforeach
@endforeach
</summary>
@endif
TEMPLATE);
        View::initViewPaths();
    }

    protected function tearDown(): void
    {
        Session::setResolver(null);
        $this->project->remove();
    }

    public function testCompiledFormReadsCurrentFlashAndKeepsSessionsSeparate(): void
    {
        $first = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $first);
        $first->store()->flashInput([
            'email' => '<unsafe>', 'zero' => 0, 'empty' => '', 'flag' => false,
            'roles' => ['editor'], 'password' => 'PASSWORD_SECRET',
            'active' => true, 'country' => 'ng',
        ]);
        $first->store()->flash('_validation_errors', [
            'email' => ['<invalid>', 'same error'],
            'name' => ['same error'],
        ]);
        $first->store()->close();

        $flashed = $this->render();
        self::assertStringContainsString('<value>&lt;unsafe&gt;</value>', $flashed);
        self::assertStringContainsString('<zero>0</zero>', $flashed);
        self::assertStringContainsString('<empty>empty</empty>', $flashed);
        self::assertStringContainsString('<false>false</false>', $flashed);
        self::assertStringContainsString('<array>editor</array>', $flashed);
        self::assertStringContainsString('<password>filtered</password>', $flashed);
        self::assertStringContainsString('<checkbox checked></checkbox>', $flashed);
        self::assertStringContainsString('<option selected></option>', $flashed);
        self::assertStringContainsString('<field>&lt;invalid&gt;</field>', $flashed);
        self::assertStringNotContainsString('PASSWORD_SECRET', $flashed);
        self::assertStringNotContainsString('<unsafe>', $flashed);
        preg_match_all('~<entry>(.*?)</entry>~', $flashed, $entries);
        self::assertSame(['email:&lt;invalid&gt;', 'email:same error', 'name:same error'],
            $entries[1]);

        // A second top-level render in the same request uses the compiled
        // template and the same flash generation without consuming it.
        self::assertSame($flashed, $this->render());

        $second = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $second);
        $clean = $this->render();
        self::assertStringContainsString('<value>fallback</value>', $clean);
        self::assertStringContainsString('<checkbox ></checkbox>', $clean);
        self::assertStringContainsString('<option ></option>', $clean);
        self::assertStringNotContainsString('<field>', $clean);
        self::assertStringNotContainsString('<summary>', $clean);

        Session::setResolver(static fn (): SessionManager => $first);
        self::assertSame($flashed, $this->render());
        $first->store()->close();
        $expired = $this->render();
        self::assertStringContainsString('<value>fallback</value>', $expired);
        self::assertStringNotContainsString('<field>', $expired);
        self::assertStringNotContainsString('<summary>', $expired);
    }

    public function testFieldErrorUsesCurrentBagInComponentTemplateAndCallerSlot(): void
    {
        $this->project->write('Project/Views/Pages/ComponentForm.squehub.php', <<<'TEMPLATE'
@component('ErrorShell')
    @error('email')<slot-error>{{ $message }}</slot-error>@enderror
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/ErrorShell.squehub.php', <<<'TEMPLATE'
@props([])
<shell>@error('email')<component-error>{{ $message }}</component-error>@enderror{!! $slot->toHtml() !!}</shell>
TEMPLATE);
        $session = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $session);
        $session->store()->flash('_validation_errors', ['email' => ['<unsafe>']]);
        $session->store()->close();

        ob_start();
        try {
            View::render('Pages.ComponentForm');
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('<component-error>&lt;unsafe&gt;</component-error>',
            $output);
        self::assertStringContainsString('<slot-error>&lt;unsafe&gt;</slot-error>', $output);
        self::assertStringNotContainsString('<unsafe>', $output);
    }

    public function testDynamicFieldErrorsTrackTheActiveLoopWithoutLeakingMessage(): void
    {
        $this->project->write('Project/Views/Pages/RepeatedForm.squehub.php', <<<'TEMPLATE'
@foreach($items as $item)
    @error('items.' . $loop->index . '.name')
        <field>{{ $loop->iteration }}:{{ $message }}</field>
    @enderror
@endforeach
<after>{{ isset($message) ? 'leaked' : 'clean' }}</after>
TEMPLATE);
        $session = $this->arraySession();
        Session::setResolver(static fn (): SessionManager => $session);
        $session->store()->flash('_validation_errors', [
            'items.0.name' => ['first error'],
            'items.1.name' => ['second error'],
        ]);
        $session->store()->close();

        ob_start();
        try {
            View::render('Pages.RepeatedForm', ['items' => ['one', 'two']]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('<field>1:first error</field>', $output);
        self::assertStringContainsString('<field>2:second error</field>', $output);
        self::assertStringContainsString('<after>clean</after>', $output);
    }

    private function arraySession(): SessionManager
    {
        return new SessionManager(new Repository(['session' => ['driver' => 'array']]));
    }

    private function render(): string
    {
        ob_start();
        try {
            View::render('Pages.FormState');
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
