<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Collections;

use App\Database\Collections\ModelCollection;
use App\Database\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Locks down the modern, in-memory collection contract without a database. */
final class ModelCollectionTest extends TestCase
{
    public function testEmptyAndOrderedAccess(): void
    {
        $empty = new ModelCollection();
        self::assertCount(0, $empty);
        self::assertTrue($empty->isEmpty());
        self::assertFalse($empty->isNotEmpty());
        self::assertNull($empty->first());
        self::assertNull($empty->last());
        self::assertNull($empty->at(0));
        self::assertSame([], iterator_to_array($empty));
        self::assertSame([], $empty->toArray());
        self::assertSame('[]', $empty->toJson());

        $ada = CollectionUser::hydrate(['id' => 1, 'email' => 'ada@example.test', 'active' => '1']);
        $lin = CollectionUser::hydrate(['id' => 2, 'email' => 'lin@example.test', 'active' => '0']);
        $users = new ModelCollection([$ada, $lin]);
        self::assertCount(2, $users);
        self::assertSame($ada, $users->first());
        self::assertSame($lin, $users->last());
        self::assertSame($lin, $users->at(1));
        self::assertNull($users->at(-1));
        self::assertNull($users->at(9));
        self::assertSame($ada, $users[0]);
        self::assertFalse(isset($users[2]));
        self::assertSame([$ada, $lin], iterator_to_array($users));
        self::assertSame(['ada@example.test', 'lin@example.test'], $users->pluck('email'));
        self::assertSame([true, false], $users->pluck('active'));
        self::assertSame([1, 2], $users->map(static fn (CollectionUser $user): int => (int) $user->id));
        $active = $users->filter(static fn (CollectionUser $user): bool => $user->active);
        self::assertSame($ada, $active->first());
        self::assertCount(1, $active);
        self::assertCount(2, $users);
        self::assertSame($ada, $users[0]);
        self::assertFalse($ada->changed());
    }

    public function testSerializationUsesModelRulesAndDoesNotLoadRelations(): void
    {
        $user = CollectionUser::hydrate([
            'id' => 1, 'email' => 'ada@example.test', 'active' => '1', 'password' => 'secret',
        ]);
        $user->setRelation('children', new ModelCollection());
        $collection = new ModelCollection([$user]);
        self::assertSame([['id' => 1, 'email' => 'ada@example.test', 'active' => true]], $collection->toArray());
        self::assertSame($collection->toArray(), $collection->jsonSerialize());
        self::assertSame($collection->toArray(), json_decode($collection->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertTrue($user->relationLoaded('children'));
        self::assertSame(['secret'], $collection->pluck('password')); // Explicit reads may access hidden fields.
    }

    public function testInvalidElementsAndReadOnlyOffsetsFail(): void
    {
        try {
            new ModelCollection([new CollectionUser(), ['not a model']]);
            self::fail('Collection must reject non-model elements.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $users = new ModelCollection([new CollectionUser()]);
        try {
            $users[] = new CollectionUser();
            self::fail('Collection must be read-only.');
        } catch (LogicException) {
            self::assertCount(1, $users);
        }
        $this->expectException(LogicException::class);
        unset($users[0]);
    }

    public function testJsonErrorsAreNotSilenced(): void
    {
        $user = CollectionUser::hydrate(['score' => INF]);
        $this->expectException(JsonException::class);
        (new ModelCollection([$user]))->toJson();
    }
}

final class CollectionUser extends Model
{
    protected array $casts = ['active' => 'boolean'];
    protected array $hidden = ['password'];
}
