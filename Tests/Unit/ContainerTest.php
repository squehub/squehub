<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Container\Container;
use App\Container\Exception\BindingResolutionException;
use App\Container\Exception\CircularDependencyException;
use App\Container\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

interface RepositoryContract {}
final class Repository implements RepositoryContract {}
final class SimpleService {}
final class LevelC {}
final class LevelB { public function __construct(public LevelC $c) {} }
final class LevelA { public function __construct(public LevelB $b) {} }
final class UsesRepository { public function __construct(public RepositoryContract $repository) {} }
final class NeedsScalar { public function __construct(public string $host) {} }
final class HasDefault { public function __construct(public int $timeout = 30) {} }
final class OptionalRepository { public function __construct(public ?RepositoryContract $repository = null) {} }
final class CycleA { public function __construct(public CycleB $b) {} }
final class CycleB { public function __construct(public CycleA $a) {} }

final class ContainerTest extends TestCase
{
    public function testConcreteAndNestedConstructorResolution(): void
    {
        $container = new Container();
        self::assertInstanceOf(SimpleService::class, $container->make(SimpleService::class));
        self::assertInstanceOf(LevelC::class, $container->make(LevelA::class)->b->c);
        self::assertFalse($container->has(SimpleService::class)); // has means explicit registration.
    }

    public function testInterfaceBindingAndPsrGet(): void
    {
        $container = new Container();
        $container->bind(RepositoryContract::class, Repository::class);
        self::assertTrue($container->has(RepositoryContract::class));
        self::assertInstanceOf(Repository::class, $container->get(UsesRepository::class)->repository);
    }

    public function testFactoryBindingReceivesContainer(): void
    {
        $container = new Container();
        $container->bind(SimpleService::class, fn (Container $c): SimpleService => new SimpleService());
        self::assertInstanceOf(SimpleService::class, $container->make(SimpleService::class));
    }

    public function testTransientAndSingletonLifetimes(): void
    {
        $container = new Container();
        $container->bind(SimpleService::class);
        self::assertNotSame($container->make(SimpleService::class), $container->make(SimpleService::class));
        $container->singleton(SimpleService::class);
        self::assertSame($container->make(SimpleService::class), $container->make(SimpleService::class));
    }

    public function testInstanceAndAliasReturnIdenticalObject(): void
    {
        $container = new Container();
        $instance = new SimpleService();
        $container->instance(SimpleService::class, $instance);
        $container->alias(SimpleService::class, 'simple');
        self::assertTrue($container->has('simple'));
        self::assertSame($instance, $container->make('simple'));
        self::assertSame($instance, $container->make(SimpleService::class));
    }

    public function testMissingInterfaceFailsWithContext(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('RepositoryContract');
        (new Container())->make(UsesRepository::class);
    }

    public function testUnresolvableScalarNamesTheParameter(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('$host');
        (new Container())->make(NeedsScalar::class);
    }

    public function testDefaultAndNullableParameters(): void
    {
        $container = new Container();
        self::assertSame(30, $container->make(HasDefault::class)->timeout);
        self::assertNull($container->make(OptionalRepository::class)->repository);
        $container->bind(RepositoryContract::class, Repository::class);
        self::assertInstanceOf(Repository::class, $container->make(OptionalRepository::class)->repository);
    }

    public function testConstructorCycleShowsPath(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('CycleA');
        (new Container())->make(CycleA::class);
    }

    public function testAliasCycleIsRejected(): void
    {
        $container = new Container();
        $container->alias('b', 'a');
        $this->expectException(CircularDependencyException::class);
        $container->alias('a', 'b');
    }

    public function testUnknownClassHasUsefulError(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('does not exist');
        (new Container())->make('does not exist');
    }

    public function testFactoryMustReturnCompatibleObject(): void
    {
        $container = new Container();
        $container->bind(RepositoryContract::class, fn () => new SimpleService());
        $this->expectException(BindingResolutionException::class);
        $container->make(RepositoryContract::class);
    }

    public function testContainersDoNotShareState(): void
    {
        $first = new Container();
        $first->instance(SimpleService::class, new SimpleService());
        self::assertFalse((new Container())->has(SimpleService::class));
    }

    public function testExplicitBrokenBindingUsesContainerErrorRatherThanNotFound(): void
    {
        $container = new Container();
        $container->bind(RepositoryContract::class, 'MissingRepositoryClass');
        self::assertTrue($container->has(RepositoryContract::class));
        $this->expectException(BindingResolutionException::class);
        $container->get(RepositoryContract::class);
    }

    public function testFactoryCycleIsDetectedAndResolutionStateRecovers(): void
    {
        $container = new Container();
        $container->bind('loop', fn (Container $c): object => $c->make('loop'));
        try {
            $container->make('loop');
            self::fail('Expected a circular dependency exception.');
        } catch (CircularDependencyException $exception) {
            self::assertStringContainsString('loop -> loop', $exception->getMessage());
        }
        self::assertInstanceOf(SimpleService::class, $container->make(SimpleService::class));
    }
}
