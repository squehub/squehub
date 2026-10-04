<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Source diagnostics must describe the original template, not generated PHP. */
final class ViewDiagnosticCompilerTest extends TestCase
{
    /** @dataProvider invalidSources */
    public function testRepresentativeDirectiveFailuresExposeLogicalSourceMetadata(
        string $source, int $line, string $reason
    ): void {
        foreach (["\n", "\r\n"] as $ending) {
            $source = str_replace("\n", $ending, $source);
            try {
                (new TemplateCompiler())->compile($source, 'Pages.Diagnostics');
                self::fail('The invalid template should fail before execution.');
            } catch (CompilerException $error) {
                self::assertSame('Pages.Diagnostics', $error->view());
                self::assertSame($line, $error->sourceLine());
                self::assertStringContainsString($reason, $error->reason());
                self::assertStringNotContainsString('Storage/Cache', $error->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidSources(): iterable
    {
        yield 'condition' => ["first\n@if(true)", 2, '@if'];
        yield 'layout' => ["first\n@extends(\$dynamic)", 2, '@extends'];
        yield 'include' => ["first\n@include()", 2, '@include'];
        yield 'component' => ["first\n@component()", 2, '@component'];
        yield 'form' => ["first\n@method()", 2, '@method'];
        yield 'security multiline' => ["first\n@can(\n    'orders.update',\n    \$order", 2, '@can'];
        yield 'fragment' => ["first\n@fragment('body')", 2, '@fragment'];
    }

    public function testCompilerReasonAndOptionalFieldsAreStructured(): void
    {
        $error = new CompilerException('Pages.Example', 7, '@include target is invalid.',
            '@include', ['View Pages.Example', 'Include Partials.Icon']);

        self::assertSame('Pages.Example', $error->view());
        self::assertSame(7, $error->sourceLine());
        self::assertSame('@include target is invalid.', $error->reason());
        self::assertSame('@include', $error->directive());
        self::assertSame(['View Pages.Example', 'Include Partials.Icon'],
            $error->dependencyChain());
    }

    public function testApplicationFacingDiagnosticAliasesResolveCanonicalClasses(): void
    {
        foreach ([
            \App\Plugins\CompilerException::class => CompilerException::class,
            \App\Plugins\ViewNotFoundException::class => \App\View\ViewNotFoundException::class,
            \App\Plugins\FragmentNotFoundException::class => \App\View\FragmentNotFoundException::class,
            \App\Plugins\InvalidFragmentNameException::class => \App\View\InvalidFragmentNameException::class,
            \App\Plugins\ViewRenderException::class => \App\View\ViewRenderException::class,
        ] as $alias => $canonical) {
            self::assertTrue(class_exists($alias));
            self::assertSame($canonical, (new \ReflectionClass($alias))->getName());
        }
    }
}
