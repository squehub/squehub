<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Http\Request;
use App\Plugins\ViewContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use UnexpectedValueException;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Verifies context layering without relying on the static template gateway. */
final class ViewContextManagerTest extends TestCase
{
    public function testSharedReplacementAndRenderSnapshot(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $views->share('brand', 'Alpha');
            $views->share('brand', 'Beta');
            $views->beginRender();
            try {
                $first = $views->contextFor('Home');
                $views->share('brand', 'Gamma');
                self::assertSame('Beta', $first['brand']);
                self::assertSame('Beta', $views->contextFor('Home')['brand']);
            } finally {
                $views->endRender();
            }
            self::assertSame('Gamma', $views->contextFor('Home')['brand']);
        } finally {
            $project->remove();
        }
    }

    public function testProviderRunsOncePerRequestAndNeverKeepsTheOldRequest(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $calls = 0;
            $views->provide(static function (ViewContext $context) use (&$calls): array {
                ++$calls;
                return ['requestPath' => $context->request()?->path() ?? 'CLI'];
            });

            $views->beginRequest(new Request('GET', '/first'));
            try {
                self::assertSame('/first', $views->contextFor('First')['requestPath']);
                self::assertSame('/first', $views->contextFor('Second')['requestPath']);
                self::assertSame(1, $calls);
            } finally {
                $views->endRequest();
            }

            $views->beginRequest(new Request('GET', '/second'));
            try {
                self::assertSame('/second', $views->contextFor('First')['requestPath']);
                self::assertSame(2, $calls);
            } finally {
                $views->endRequest();
            }
            self::assertSame('CLI', $views->contextFor('First')['requestPath']);
            self::assertSame(3, $calls);
        } finally {
            $project->remove();
        }
    }

    public function testExactComposerAndLayerPrecedence(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $views->share('title', 'Shared');
            $views->share('brand', 'SqueHub');
            $views->provide(static fn (ViewContext $context): array => ['title' => 'Provided']);
            $calls = 0;
            $views->compose('Pages.Dashboard', static function (ViewContext $context) use (&$calls): array {
                ++$calls;
                return ['title' => 'Composed', 'viewName' => $context->view()];
            });

            self::assertSame('Provided', $views->contextFor('Pages.Other')['title']);
            self::assertSame('Composed', $views->contextFor('Pages.Dashboard')['title']);
            self::assertSame('Explicit', $views->contextFor('Pages.Dashboard', ['title' => 'Explicit'])['title']);
            self::assertSame('SqueHub', $views->contextFor('Pages.Dashboard')['brand']);
            self::assertSame('Pages.Dashboard', $views->contextFor('Pages.Dashboard')['viewName']);
            self::assertSame(4, $calls, 'A selected composer runs for each selected render.');
        } finally {
            $project->remove();
        }
    }

    public function testReservedAndInvalidRegistrationNamesAreRejected(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            foreach (['errors', 'GLOBALS', 'this', '__squehub_file', '0', 'path/name', 'has space'] as $name) {
                try {
                    $views->share($name, 'unsafe');
                    self::fail("Unsafe View context name '{$name}' was accepted.");
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
            // Existing explicit render data named errors remains ignored by the
            // framework, which supplies its request-scoped ErrorBag separately.
            self::assertArrayNotHasKey('errors', $views->contextFor('Home', ['errors' => 'fake']));
        } finally {
            $project->remove();
        }
    }

    public function testInvalidProviderResultAndSameLayerCollisionsFail(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $views->provide(static fn (ViewContext $context): array => ['navigation' => 'First']);
            $views->provide(static fn (ViewContext $context): array => ['navigation' => 'Second']);
            $this->expectException(LogicException::class);
            $views->contextFor('Home');
        } finally {
            $project->remove();
        }
    }

    public function testInvalidProviderKeyFailsBeforeAnyResultIsCached(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $invalid = true;
            $views->provide(static function (ViewContext $context) use (&$invalid): array {
                return $invalid ? ['errors' => 'fake'] : ['safe' => 'ready'];
            });
            $views->beginRequest(new Request('GET', '/'));
            try {
                try {
                    $views->contextFor('Home');
                    self::fail('A provider replaced the reserved ErrorBag.');
                } catch (InvalidArgumentException) {
                    $invalid = false;
                }
                self::assertSame('ready', $views->contextFor('Home')['safe']);
            } finally {
                $views->endRequest();
            }
        } finally {
            $project->remove();
        }
    }

    public function testInvalidOutputAndComposerCollisionFailClearly(): void
    {
        $project = new TemporaryProject();
        try {
            $views = (new Application($project->path()))->views();
            $views->provide(static fn (ViewContext $context): string => 'not an array');
            try {
                $views->contextFor('Home');
                self::fail('A non-array provider result was accepted.');
            } catch (UnexpectedValueException) {
                self::assertTrue(true);
            }

            $second = (new Application($project->path()))->views();
            $second->compose('Home', static fn (ViewContext $context): array => ['title' => 'One']);
            $second->compose('Home', static fn (ViewContext $context): array => ['title' => 'Two']);
            $this->expectException(LogicException::class);
            $second->contextFor('Home');
        } finally {
            $project->remove();
        }
    }

    public function testClassProviderResolvesLazilyFromTheOwningContainer(): void
    {
        $alpha = new TemporaryProject();
        $beta = new TemporaryProject();
        try {
            $appA = new Application($alpha->path());
            $appB = new Application($beta->path());
            $appA->container()->instance(ViewContextBrandSource::class, new ViewContextBrand('Alpha'));
            $appB->container()->instance(ViewContextBrandSource::class, new ViewContextBrand('Beta'));
            foreach ([$appA, $appB] as $app) {
                $app->views()->provide(ViewContextBrandProvider::class);
                $app->views()->compose('Home', ViewContextBrandComposer::class);
            }

            self::assertSame('Alpha', $appA->views()->contextFor('Home')['brand']);
            self::assertSame('Beta', $appB->views()->contextFor('Home')['brand']);
            self::assertSame('Alpha home', $appA->views()->contextFor('Home')['homeLabel']);
            self::assertSame('Beta home', $appB->views()->contextFor('Home')['homeLabel']);
        } finally {
            $alpha->remove();
            $beta->remove();
        }
    }
}

/** The test binding proves a class provider uses its own Application container. */
interface ViewContextBrandSource
{
    public function value(): string;
}

/** Small immutable source for the two-Application container test. */
final class ViewContextBrand implements ViewContextBrandSource
{
    public function __construct(private readonly string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }
}

/** A class provider receives its dependency through SqueHub's Container. */
final class ViewContextBrandProvider
{
    public function __construct(private readonly ViewContextBrandSource $source)
    {
    }

    public function provide(ViewContext $context): array
    {
        return ['brand' => $this->source->value()];
    }
}

/** A class composer follows the same per-Application resolution rule. */
final class ViewContextBrandComposer
{
    public function __construct(private readonly ViewContextBrandSource $source)
    {
    }

    public function compose(ViewContext $context): array
    {
        return ['homeLabel' => $this->source->value() . ' home'];
    }
}
