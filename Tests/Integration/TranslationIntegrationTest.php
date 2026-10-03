<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\TestCase;
use App\Plugins\Translation;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use App\Translation\LocaleId;
use App\Translation\TranslationCapabilityException;
use App\Translation\TranslationException;
use App\Translation\TranslationManager;
use DateTimeImmutable;
use DateTimeZone;

/** Catalog, scope, Package, View, and optional ICU behavior on a real Application. */
final class TranslationIntegrationTest extends TestCase
{
    protected function testingConfig(): array
    {
        return ['translation' => [
            'default' => 'en', 'fallback' => 'en',
            'supported' => ['en', 'fr', 'fr-FR', 'pt', 'pt-BR', 'de-DE', 'ru'],
        ]];
    }

    public function testDefaultSelectionFallbackAndSafeParameters(): void
    {
        $this->testApplication()->write('Project/Translations/en/auth.json',
            '{"welcome":"Hello, :name", "missing":"English", "html":"<b>:name</b>"}');
        $this->testApplication()->write('Project/Translations/fr/auth.json',
            '{"welcome":"Bonjour, :name"}');
        $this->testApplication()->write('Project/Translations/pt/auth.json',
            '{"welcome":"Olá, :name"}');
        $manager = $this->app()->container()->make(TranslationManager::class);
        RuntimeContext::select($this->app());

        self::assertSame('en', Translation::locale());
        self::assertSame('en', $manager->defaultLocale());
        self::assertSame('en', $manager->fallbackLocale());
        self::assertSame('Hello, Ada', Translation::get('auth.welcome',
            ['name' => 'Ada', 'unused' => 'ignored']));
        self::assertSame('Hello, :name', Translation::get('auth.welcome'));
        self::assertSame('auth.unknown', Translation::get('auth.unknown'));
        self::assertSame('<b><Ada></b>', Translation::get('auth.html', ['name' => '<Ada>']));
        self::assertSame('Olá, Ada', Translation::get('auth.welcome', ['name' => 'Ada'], 'pt-BR'));
        self::assertSame('English', Translation::get('auth.missing', locale: 'fr-FR'));
        self::assertSame('Bonjour, Ada', Translation::get('auth.welcome', ['name' => 'Ada'], 'fr-FR'));
        self::assertSame('en', Translation::locale(), 'An explicit lookup locale must not alter current state.');
    }

    public function testApplicationAndNestedOperationScopesDoNotLeak(): void
    {
        $manager = $this->app()->container()->make(TranslationManager::class);
        $manager->setLocale('fr');
        $manager->beginScope();
        self::assertSame('fr', $manager->locale());
        $manager->setLocale('pt-BR');
        $manager->beginScope('de-DE');
        self::assertSame('de-DE', $manager->locale());
        $manager->endScope();
        self::assertSame('pt-BR', $manager->locale());
        $manager->endScope();
        self::assertSame('fr', $manager->locale());
        $manager->beginScope();
        self::assertSame('fr', $manager->locale());
        $manager->endScope();

        $other = TestApplication::temporary(['translation' => [
            'default' => 'en', 'fallback' => 'en', 'supported' => ['en', 'yo'],
        ]]);
        try {
            $otherApp = $other->application();
            RuntimeContext::select($otherApp);
            self::assertSame('en', Translation::locale());
            Translation::setLocale('yo');
            self::assertSame('yo', Translation::locale());
            RuntimeContext::select($this->app());
            self::assertSame('fr', Translation::locale());
        } finally {
            RuntimeContext::select($this->app());
            $other->cleanup();
        }
    }

    public function testInvalidLocaleKeyAndCatalogAreRejected(): void
    {
        self::assertSame('pt-BR', LocaleId::normalize('PT-br'));
        self::assertSame('ko-KR', LocaleId::normalize('ko-kr'));
        self::assertSame('zh-Hant-TW', LocaleId::normalize('ZH-hANT-tw'));
        $manager = $this->app()->container()->make(TranslationManager::class);
        foreach (['../en', 'en/US', "en\n", str_repeat('e', 100), 'en_US'] as $invalid) {
            try {
                $manager->setLocale($invalid);
                self::fail('Unsafe locale was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame('en', $manager->locale());
            }
        }
        try {
            $manager->setLocale('ar');
            self::fail('Unsupported locale was accepted.');
        } catch (TranslationException) {
            self::assertSame('en', $manager->locale());
        }
        foreach (['../auth.login', 'auth..login', 'Bad-Name::auth.login',
            'auth.login/../../secret'] as $invalid) {
            try {
                $manager->get($invalid);
                self::fail('Unsafe translation key was accepted.');
            } catch (TranslationException) {
            }
        }
        $this->testApplication()->write('Project/Translations/en/broken.json', '{not json');
        $this->expectException(TranslationException::class);
        $manager->get('broken.key');
    }

    public function testPackageNamespaceOverrideAndDisabledPackageIsolation(): void
    {
        // PHP cannot unload a class from an earlier disposable Application.
        // Unique fixture entry names keep this catalog test independent of
        // Package suites that use the same conventional example names.
        $commerce = 'TranslationCommerce' . bin2hex(random_bytes(8));
        $dormant = 'TranslationDormant' . bin2hex(random_bytes(8));
        foreach ([$commerce, $dormant] as $name) {
            $this->testApplication()->write("Project/Packages/{$name}/{$name}.php",
                '<?php namespace Packages\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\ServiceProvider {}');
            $this->testApplication()->write("Project/Packages/{$name}/Translations/en/orders.json",
                '{"paid":"Package paid"}');
        }
        $this->testApplication()->write("Project/Packages/{$commerce}/Translations/fr/orders.json",
            '{"paid":"Package payé"}');
        $this->testApplication()->write("Project/Translations/Packages/{$dormant}/en/orders.json",
            '{"paid":"Disabled override"}');
        $lifecycle = new PackageManager(new Application($this->testApplication()->root()));
        self::assertTrue($lifecycle->apply($lifecycle->planEnable($commerce))->complete());
        RuntimeContext::select($this->app());
        self::assertSame('Package paid', Translation::get($commerce . '::orders.paid'));
        self::assertSame($dormant . '::orders.paid', Translation::get($dormant . '::orders.paid'));
        $this->testApplication()->write("Project/Translations/Packages/{$commerce}/en/orders.json",
            '{"paid":"Project paid"}');
        self::assertSame('Project paid', Translation::get($commerce . '::orders.paid'));
        self::assertSame('Package payé', Translation::get($commerce . '::orders.paid', locale: 'fr'),
            'Requested-locale Package text outranks fallback-locale Project override.');
        $this->testApplication()->write("Project/Translations/Packages/{$commerce}/fr/orders.json",
            '{"paid":"Projet payé"}');
        self::assertSame('Projet payé', Translation::get($commerce . '::orders.paid', locale: 'fr'));
        self::assertSame($dormant . '::orders.paid', Translation::get($dormant . '::orders.paid'));
        self::assertSame('orders.paid', Translation::get('orders.paid'));
    }

    public function testDirectiveEscapesPlainTranslationsAtRenderTime(): void
    {
        $this->testApplication()->write('Project/Translations/en/page.json',
            '{"hello":"Hello <b>:name</b>"}');
        $this->testApplication()->write('Project/Translations/fr/page.json',
            '{"hello":"Salut <b>:name</b>"}');
        $this->testApplication()->write('Project/Views/Localized/Page.squehub.php',
            "@translate('page.hello', ['name' => '<Ada>'])");
        self::assertSame('Hello &lt;b&gt;&lt;Ada&gt;&lt;/b&gt;',
            $this->view('Localized.Page')->html());
        Translation::setLocale('fr');
        self::assertSame('Salut &lt;b&gt;&lt;Ada&gt;&lt;/b&gt;',
            $this->view('Localized.Page')->html());
    }

    public function testPluralAndFormattingUseRealIntlOrFailClearlyWhenUnavailable(): void
    {
        $this->testApplication()->write('Project/Translations/en/counts.json',
            '{"items":"{count, plural, one{# item} other{# items}}"}');
        $this->testApplication()->write('Project/Translations/ru/counts.json',
            '{"items":"{count, plural, one{# товар} few{# товара} many{# товаров} other{# товара}}"}');
        RuntimeContext::select($this->app());
        $date = new DateTimeImmutable('2026-09-30 15:30:00', new DateTimeZone('UTC'));
        if (!extension_loaded('intl')) {
            foreach ([
                static fn () => Translation::plural('counts.items', 2),
                static fn () => Translation::number(1234567.5),
                static fn () => Translation::currency(12.5, 'NGN'),
                static fn () => Translation::dateTime($date),
            ] as $format) {
                try {
                    $format();
                    self::fail('Formatting should report the missing optional capability.');
                } catch (TranslationCapabilityException) {
                }
            }
            self::assertSame('counts.missing', Translation::plural('counts.missing', 2));
            return;
        }
        self::assertSame('1 item', Translation::plural('counts.items', 1));
        self::assertSame('2 items', Translation::plural('counts.items', 2));
        self::assertSame('1 товар', Translation::plural('counts.items', 1, locale: 'ru'));
        self::assertSame('2 товара', Translation::plural('counts.items', 2, locale: 'ru'));
        self::assertSame('5 товаров', Translation::plural('counts.items', 5, locale: 'ru'));
        self::assertSame('1,234,567.5', Translation::number(1234567.5, 'en'));
        self::assertSame('1.234.567,5', Translation::number(1234567.5, 'de-DE'));
        self::assertNotSame('', Translation::currency(12.5, 'NGN'));
        self::assertNotSame('', Translation::dateTime($date));
    }
}
