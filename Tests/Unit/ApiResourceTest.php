<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Api;

use App\Api\ApiResource;
use App\Api\ResourceCollection;
use App\Api\ResourceException;
use App\Database\Collections\ModelCollection;
use App\Database\Model;
use App\Database\Pagination\Page;
use App\Http\JsonResponse;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Public representation and recursive normalization are independent of HTTP and database services. */
final class ApiResourceTest extends TestCase
{
    private const SECRET = 'SQUEHUB_API_RESOURCE_PRIVATE_VALUE';

    public function testModelExposesOnlyTheExplicitPublicShape(): void
    {
        $model = ResourceUser::hydrate([
            'id' => 42,
            'name' => 'Valentine',
            'email' => 'private@example.test',
            'password_hash' => self::SECRET,
            'reset_token' => self::SECRET,
            'internal_flag' => true,
        ]);
        self::assertArrayHasKey('email', $model->toArray());
        self::assertArrayHasKey('internal_flag', $model->toArray());
        $resource = PublicUserResource::make($model);
        self::assertSame(['id' => 42, 'name' => 'Valentine'], $resource->resolve());
        self::assertSame(['id' => 42, 'name' => 'Valentine'], $resource->jsonSerialize());
        self::assertSame('{"id":42,"name":"Valentine"}', json_encode($resource, JSON_THROW_ON_ERROR));
        self::assertSame(self::SECRET, $model->password_hash);
        self::assertFalse($model->changed());
    }

    public function testArraysAndPlainApplicationObjectsCanBeTransformedExplicitly(): void
    {
        self::assertSame(['id' => 7, 'name' => 'Ada'], RowResource::make([
            'id' => 7, 'name' => 'Ada', 'private' => self::SECRET,
        ])->resolve());
        $source = (object) ['id' => 8, 'name' => 'Lin', 'private' => self::SECRET];
        self::assertSame(['name' => 'Lin'], NameObjectResource::make($source)->resolve());
        self::assertSame(self::SECRET, $source->private);
    }

    public function testNullSkipsApplicationTransformation(): void
    {
        $resource = ThrowingResource::make(null);
        self::assertNull($resource->resolve());
        self::assertSame('null', json_encode($resource, JSON_THROW_ON_ERROR));
        self::assertSame('null', $resource->response()->content());
    }

    public function testWrongInputProducesSafeErrorWithOriginalCause(): void
    {
        $error = $this->resourceFailure(static fn () => PublicUserResource::make(self::SECRET)->resolve());
        self::assertInstanceOf(InvalidArgumentException::class, $error->getPrevious());
        self::assertNotSame('', $error->getMessage());
    }

    public function testConstructorFailurePreservesSafeOuterBoundary(): void
    {
        $error = $this->resourceFailure(static fn () => FailingConstructorResource::make(self::SECRET));
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        self::assertSame(self::SECRET, $error->getPrevious()->getMessage());
    }

    public function testSourceReferenceIsReadonlyButSourceObjectChangesRemainVisible(): void
    {
        $source = (object) ['name' => 'Before'];
        $resource = NameObjectResource::make($source);
        self::assertSame(['name' => 'Before'], $resource->resolve());
        $source->name = 'After';
        self::assertSame(['name' => 'After'], $resource->resolve());
        $this->expectException(\Error::class);
        $resource->replaceSource((object) ['name' => 'Replacement']);
    }

    public function testScalarFieldsAndNestedArraysRetainJsonTypes(): void
    {
        $data = [
            'string' => 'Text', 'integer' => 12, 'float' => 12.5,
            'yes' => true, 'no' => false, 'null' => null,
            'nested' => ['list' => [1, 'two', ['three' => 3]], 'empty' => []],
        ];
        self::assertSame($data, TreeResource::make($data)->resolve());
        self::assertSame($data, json_decode(TreeResource::make($data)->response()->content(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCollectionKeepsIterationOrderAndAlwaysProducesAList(): void
    {
        $collection = RowResource::collection([
            'second' => ['id' => 2, 'name' => 'Second', 'private' => self::SECRET],
            19 => ['id' => 1, 'name' => 'First', 'private' => self::SECRET],
        ]);
        self::assertSame([['id' => 2, 'name' => 'Second'], ['id' => 1, 'name' => 'First']], $collection->resolve());
        self::assertSame('[{"id":2,"name":"Second"},{"id":1,"name":"First"}]', $collection->response()->content());
    }

    public function testGeneratorCollectionCanBeResolvedRepeatedly(): void
    {
        $iterations = 0;
        $rows = (static function () use (&$iterations): \Generator {
            ++$iterations;
            yield 'a' => ['id' => 1, 'name' => 'A'];
            yield 'a' => ['id' => 2, 'name' => 'B'];
        })();
        $collection = RowResource::collection($rows);
        $expected = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']];
        self::assertSame($expected, $collection->resolve());
        self::assertSame($expected, $collection->resolve());
        self::assertSame(1, $iterations);
    }

    public function testFailingIterableDoesNotPublishPartialCollectionOrSensitiveMessage(): void
    {
        $rows = (static function (): \Generator {
            yield ['id' => 1, 'name' => 'First'];
            throw new RuntimeException(self::SECRET);
        })();
        $error = $this->resourceFailure(static fn () => RowResource::collection($rows));
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        self::assertSame(self::SECRET, $error->getPrevious()->getMessage());
    }

    public function testCollectionRequiresConcreteResourceClass(): void
    {
        foreach ([ApiResource::class, \stdClass::class, 'SqueHub\\MissingResourceDefinition'] as $class) {
            $this->resourceFailure(static fn () => new ResourceCollection($class, []));
        }
    }

    public function testModelCollectionIsTransformedWithoutModelSerialization(): void
    {
        $models = new ModelCollection([
            ResourceUser::hydrate(['id' => 4, 'name' => 'D', 'password_hash' => self::SECRET]),
            ResourceUser::hydrate(['id' => 3, 'name' => 'C', 'password_hash' => self::SECRET]),
        ]);
        self::assertSame([['id' => 4, 'name' => 'D'], ['id' => 3, 'name' => 'C']], PublicUserResource::collection($models)->resolve());
        self::assertCount(2, $models);
    }

    public function testEmptyCollectionsStayEmptyJsonLists(): void
    {
        foreach ([[], new ModelCollection(), new \ArrayIterator()] as $items) {
            self::assertSame([], RowResource::collection($items)->resolve());
            self::assertSame('[]', RowResource::collection($items)->response()->content());
        }
    }

    public function testExistingPageMetadataIsDecoratedWithoutChangingSourceItems(): void
    {
        $rows = [['id' => 3, 'name' => 'C', 'private' => self::SECRET]];
        $page = new Page($rows, 2, 2, 3);
        self::assertSame([
            'data' => [['id' => 3, 'name' => 'C']],
            'meta' => [
                'page' => 2, 'per_page' => 2, 'total' => 3, 'pages' => 2,
                'from' => 3, 'to' => 3, 'has_next' => false, 'has_previous' => true,
            ],
        ], RowResource::collection($page)->resolve());
        self::assertSame($rows, $page->items());
        self::assertSame(3, $page->total());
    }

    public function testEmptyPageUsesExistingZeroPageAndNullRangeSemantics(): void
    {
        self::assertSame([
            'data' => [],
            'meta' => [
                'page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 0,
                'from' => null, 'to' => null, 'has_next' => false, 'has_previous' => false,
            ],
        ], RowResource::collection(new Page([], 1, 20, 0))->resolve());
    }

    public function testPaginatedMetadataCannotOverwriteFrameworkValues(): void
    {
        foreach (['page', 'per_page', 'total', 'pages', 'from', 'to', 'has_next', 'has_previous'] as $name) {
            $this->resourceFailure(static fn () => RowResource::collection(new Page([], 1, 20, 0))
                ->withMeta([$name => 999])->resolve());
        }
    }

    public function testNestedResourcesCollectionsAndNullAreExplicitlyResolved(): void
    {
        $nested = TreeResource::make([
            'user' => RowResource::make(['id' => 1, 'name' => 'A']),
            'children' => RowResource::collection([['id' => 2, 'name' => 'B']]),
            'profile' => RowResource::make(null),
            'deeper' => TreeResource::make(['value' => TreeResource::make(['leaf' => 3])]),
        ]);
        self::assertSame([
            'user' => ['id' => 1, 'name' => 'A'],
            'children' => [['id' => 2, 'name' => 'B']],
            'profile' => null,
            'deeper' => ['value' => ['leaf' => 3]],
        ], $nested->resolve());
    }

    public function testSharedNestedInstanceIsNotMistakenForAncestorCycle(): void
    {
        $shared = TreeResource::make(['id' => 1]);
        self::assertSame(['first' => ['id' => 1], 'second' => ['id' => 1]],
            TreeResource::make(['first' => $shared, 'second' => $shared])->resolve());
    }

    public function testConditionalFieldsAreAbsentAndExcludedClosuresAreNeverCalled(): void
    {
        self::assertSame([
            'included' => 'yes', 'included_null' => null, 'false_value' => false,
            'lazy' => ['value' => 7], 'nested' => ['visible' => 1],
            'list' => ['first', 'last'],
        ], ConditionalResource::make('source')->resolve());
    }

    public function testAllOmittedFieldsProduceTheDocumentedEmptyArray(): void
    {
        self::assertSame([], EmptyConditionalResource::make('source')->resolve());
        self::assertSame('[]', EmptyConditionalResource::make('source')->response()->content());
    }

    public function testMetadataCreatesIndependentRepresentationWithoutMutatingData(): void
    {
        $source = ['id' => 1, 'name' => 'A', 'private' => self::SECRET];
        $resource = RowResource::make($source);
        $first = $resource->withMeta(['request_id' => 'request-one']);
        $second = $resource->withMeta(['request_id' => 'request-two']);
        self::assertNotSame($resource, $first);
        self::assertSame(['id' => 1, 'name' => 'A'], $resource->resolve());
        self::assertSame(['data' => ['id' => 1, 'name' => 'A'], 'meta' => ['request_id' => 'request-one']], $first->resolve());
        self::assertSame(['data' => ['id' => 1, 'name' => 'A'], 'meta' => ['request_id' => 'request-two']], $second->resolve());
        self::assertSame(self::SECRET, $source['private']);
    }

    public function testCollectionMetadataAndNestedResourceMetadataAreNormalized(): void
    {
        self::assertSame([
            'data' => [['id' => 1, 'name' => 'A']],
            'meta' => ['summary' => ['count' => 1]],
        ], RowResource::collection([['id' => 1, 'name' => 'A']])
            ->withMeta(['summary' => TreeResource::make(['count' => 1])])->resolve());
    }

    public function testRepeatedMetadataReplacesPriorMetadataAndEmptyMetadataStillWraps(): void
    {
        $resource = TreeResource::make(['id' => 1])->withMeta(['first' => true]);
        $replacement = $resource->withMeta(['second' => true]);
        self::assertSame(['data' => ['id' => 1], 'meta' => ['first' => true]], $resource->resolve());
        self::assertSame(['data' => ['id' => 1], 'meta' => ['second' => true]], $replacement->resolve());
        self::assertSame(['data' => ['id' => 1], 'meta' => []], $replacement->withMeta([])->resolve());
    }

    public function testMetadataNamesMustBeNonemptyStrings(): void
    {
        foreach ([['' => true], [0 => true], ['0' => true], [17 => true]] as $metadata) {
            $this->resourceFailure(static fn () => TreeResource::make(['id' => 1])->withMeta($metadata));
        }
    }

    public function testNullResourceCanCarryExplicitMetadata(): void
    {
        self::assertSame(['data' => null, 'meta' => ['reason' => 'missing']],
            ThrowingResource::make(null)->withMeta(['reason' => 'missing'])->resolve());
    }

    public function testIndependentResourcesNeverShareDataOrMetadata(): void
    {
        $a = TreeResource::make(['id' => 1])->withMeta(['context' => 'one']);
        $b = TreeResource::make(['id' => 2]);
        self::assertSame(['id' => 2], $b->resolve());
        self::assertSame(['data' => ['id' => 1], 'meta' => ['context' => 'one']], $a->resolve());
        self::assertSame(['id' => 2], $b->resolve());
    }

    /** @dataProvider unsupportedOutputValues */
    public function testUnsupportedValuesFailWithoutDumpingTheirContents(mixed $value): void
    {
        $this->resourceFailure(static fn () => TreeResource::make(['output' => $value])->resolve());
    }

    public static function unsupportedOutputValues(): iterable
    {
        yield 'plain object' => [(object) ['private' => self::SECRET]];
        yield 'model' => [ResourceUser::hydrate(['password_hash' => self::SECRET])];
        yield 'datetime' => [new DateTimeImmutable('2026-09-26T12:00:00Z')];
        yield 'serializable object' => [new PrivateSerializable()];
        yield 'closure' => [static fn (): string => self::SECRET];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function testStreamResourcesAreNotSerialized(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        try {
            $this->resourceFailure(static fn () => TreeResource::make(['stream' => $stream])->resolve());
        } finally {
            fclose($stream);
        }
    }

    public function testUnsupportedSerializableHookIsNeverInvoked(): void
    {
        $value = new PrivateSerializable();
        $this->resourceFailure(static fn () => TreeResource::make(['object' => $value])->resolve());
        self::assertSame(0, $value->calls);
    }

    public function testInvalidUtf8KeysAndValuesAreRejected(): void
    {
        $this->resourceFailure(static fn () => TreeResource::make(['field' => "\xB1\x31"])->resolve());
        $this->resourceFailure(static fn () => TreeResource::make(["\xB1\x31" => 'value'])->resolve());
    }

    public function testMetadataUsesTheSameStrictValidation(): void
    {
        $this->resourceFailure(static fn () => TreeResource::make(['id' => 1])
            ->withMeta(['private' => new PrivateSerializable()])->resolve());
        $this->resourceFailure(static fn () => TreeResource::make(['id' => 1])
            ->withMeta(["\xB1\x31" => self::SECRET])->resolve());
    }

    public function testResourceCyclesFailAndDoNotPoisonLaterResolution(): void
    {
        $first = ChainResource::make('a');
        $second = ChainResource::make('b');
        $first->next = $second;
        $second->next = $first;
        $this->resourceFailure(static fn () => $first->resolve());
        $second->next = null;
        self::assertSame(['next' => ['next' => null]], $first->resolve());
        self::assertSame(['valid' => true], TreeResource::make(['valid' => true])->resolve());
    }

    public function testCyclicArraysHaveBoundedResolution(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;
        $this->resourceFailure(static fn () => TreeResource::make($cycle)->resolve());
    }

    public function testNewResourceAtEveryLevelStillHonorsDepthLimit(): void
    {
        $this->resourceFailure(static fn () => RecursiveResource::make('source')->resolve());
    }

    public function testDeepArraysAreRejectedBeforeJsonEncoding(): void
    {
        $nested = ['value' => true];
        for ($depth = 0; $depth < 70; ++$depth) {
            $nested = ['child' => $nested];
        }
        $this->resourceFailure(static fn () => TreeResource::make($nested)->resolve());
    }

    public function testApplicationFailurePreservesCauseButNotSensitiveMessage(): void
    {
        $error = $this->resourceFailure(static fn () => ThrowingResource::make('source')->resolve());
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        self::assertSame(self::SECRET, $error->getPrevious()->getMessage());
    }

    public function testConditionalClosureFailureUsesSafeResourceBoundary(): void
    {
        $error = $this->resourceFailure(static fn () => FailingConditionalResource::make('source')->resolve());
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
    }

    public function testResponseUsesJsonResponseStatusAndSafeContentTypeWithoutDoubleEncoding(): void
    {
        $resource = TreeResource::make(['message' => '<script>hello</script>', 'number' => 4]);
        $response = $resource->response(201, ['Content-Type' => 'text/plain', 'X-Resource' => 'yes']);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(201, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('yes', $response->header('X-Resource'));
        self::assertSame(['message' => '<script>hello</script>', 'number' => 4],
            json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('<script>', $response->content());
        self::assertSame($resource->resolve(), json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true));
    }

    public function testReasonableCollectionSizeRetainsExactPublicShape(): void
    {
        $rows = [];
        for ($id = 0; $id < 1000; ++$id) {
            $rows[] = ['id' => $id, 'name' => 'User ' . $id, 'private' => self::SECRET];
        }
        $output = RowResource::collection($rows)->resolve();
        self::assertCount(1000, $output);
        self::assertSame(['id' => 0, 'name' => 'User 0'], $output[0]);
        self::assertSame(['id' => 999, 'name' => 'User 999'], $output[999]);
        self::assertStringNotContainsString(self::SECRET, json_encode($output, JSON_THROW_ON_ERROR));
    }

    /** Inspect only the safe outer error; previous causes remain available to trusted debugging code. */
    private function resourceFailure(callable $operation): ResourceException
    {
        try {
            $operation();
        } catch (ResourceException $error) {
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
            return $error;
        }
        self::fail('Expected resource output to be rejected safely.');
    }
}

/**
 * Normal Model privacy still leaves fields that the public resource deliberately omits.
 *
 * @property int $id
 * @property string $name
 * @property string $password_hash
 */
final class ResourceUser extends Model
{
}

/** The source type belongs to each application's explicit transformation contract. */
final class PublicUserResource extends ApiResource
{
    public function toArray(): array
    {
        if (!$this->resource instanceof ResourceUser) {
            throw new InvalidArgumentException('Unexpected source: SQUEHUB_API_RESOURCE_PRIVATE_VALUE');
        }
        return ['id' => $this->resource->id, 'name' => $this->resource->name];
    }
}

/** Plain row input exercises resources that have no ORM dependency. */
final class RowResource extends ApiResource
{
    public function toArray(): array
    {
        return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
    }
}

/** Public object access is explicit; no automatic property enumeration is involved. */
final class NameObjectResource extends ApiResource
{
    public function toArray(): array
    {
        return ['name' => $this->resource->name];
    }

    public function replaceSource(mixed $replacement): void
    {
        // Deliberately violate the readonly contract to verify PHP's runtime guard.
        // @phpstan-ignore-next-line
        $this->resource = $replacement;
    }
}

/** Constructor exceptions must not copy source data into the public error message. */
final class FailingConstructorResource extends ApiResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
        throw new RuntimeException('SQUEHUB_API_RESOURCE_PRIVATE_VALUE');
    }

    public function toArray(): array
    {
        return [];
    }
}

/** Passes deliberate test trees to the normalizer, including unsupported values. */
final class TreeResource extends ApiResource
{
    public function toArray(): array
    {
        return $this->resource;
    }
}

/** A failed application transformation may contain sensitive context internally. */
final class ThrowingResource extends ApiResource
{
    public function toArray(): array
    {
        throw new RuntimeException('SQUEHUB_API_RESOURCE_PRIVATE_VALUE');
    }
}

/** Omission works at every array depth without evaluating excluded work. */
final class ConditionalResource extends ApiResource
{
    public function toArray(): array
    {
        return [
            'included' => $this->when(true, 'yes'),
            'included_null' => $this->when(true, null),
            'false_value' => $this->when(true, false),
            'excluded' => $this->when(false, 'hidden'),
            'not_evaluated' => $this->when(false, static function (): never {
                throw new RuntimeException('An excluded field was evaluated.');
            }),
            'lazy' => $this->when(true, static fn (): ApiResource => TreeResource::make(['value' => 7])),
            'nested' => ['hidden' => $this->when(false, 'hidden'), 'visible' => 1],
            'list' => ['first', $this->when(false, 'hidden'), 'last'],
        ];
    }
}

/** The empty representation remains an array rather than an implicit object. */
final class EmptyConditionalResource extends ApiResource
{
    public function toArray(): array
    {
        return ['hidden' => $this->when(false, null)];
    }
}

/** A lazy included field uses the same exception boundary as the transformation. */
final class FailingConditionalResource extends ApiResource
{
    public function toArray(): array
    {
        return ['value' => $this->when(true, static function (): never {
            throw new RuntimeException('SQUEHUB_API_RESOURCE_PRIVATE_VALUE');
        })];
    }
}

/** Exposes an explicit resource graph for ancestor-cycle tests. */
final class ChainResource extends ApiResource
{
    public ?ApiResource $next = null;

    public function toArray(): array
    {
        return ['next' => $this->next];
    }
}

/** New identities at each depth must not evade the recursive output bound. */
final class RecursiveResource extends ApiResource
{
    public function toArray(): array
    {
        return ['next' => self::make('source')];
    }
}

/** Arbitrary serializers must never be invoked by the strict resource normalizer. */
final class PrivateSerializable implements JsonSerializable
{
    public int $calls = 0;

    public function jsonSerialize(): mixed
    {
        ++$this->calls;
        return ['private' => 'SQUEHUB_API_RESOURCE_PRIVATE_VALUE'];
    }
}
