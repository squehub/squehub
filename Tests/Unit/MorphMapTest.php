<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\MorphMap;

use App\Database\Model;
use App\Database\Relations\MorphMap;
use App\Database\Relations\RelationException;
use PHPUnit\Framework\TestCase;

/** Keeps persisted morph aliases explicit, unique, and independent per map. */
final class MorphMapTest extends TestCase
{
    public function testRegisteredAliasResolvesOnlyItsConcreteModel(): void
    {
        $map = new MorphMap();
        self::assertSame($map, $map->define('post', MorphUnitPost::class));
        self::assertSame(MorphUnitPost::class, $map->classFor('post'));
        self::assertSame('post', $map->aliasFor(MorphUnitPost::class));
        self::assertSame([MorphUnitPost::class], $map->classes());
        self::assertSame([], (new MorphMap())->classes());
    }

    public function testDuplicateAliasesAndClassesCannotReplaceExistingMapping(): void
    {
        $map = (new MorphMap())->define('post', MorphUnitPost::class);
        foreach ([['post', MorphUnitVideo::class], ['other', MorphUnitPost::class]] as [$alias, $class]) {
            try {
                $map->define($alias, $class);
                self::fail('A duplicate morph registration replaced an existing mapping.');
            } catch (RelationException) {
                self::assertSame(MorphUnitPost::class, $map->classFor('post'));
            }
        }
    }

    public function testInvalidAliasesAndUntrustedClassesFail(): void
    {
        foreach (['', 'Post', 'post/type', '../post', str_repeat('a', 129)] as $alias) {
            try {
                (new MorphMap())->define($alias, MorphUnitPost::class);
                self::fail('An invalid morph alias was accepted.');
            } catch (RelationException) {
                self::assertTrue(true);
            }
        }
        foreach ([\stdClass::class, AbstractMorphUnit::class] as $class) {
            try {
                (new MorphMap())->define('test', $class);
                self::fail('An invalid morph class was accepted.');
            } catch (RelationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUnknownStoredTypeDoesNotBecomeAClassName(): void
    {
        $map = new MorphMap();
        $this->expectException(RelationException::class);
        $map->classFor('Project\\Models\\Dangerous');
    }
}

final class MorphUnitPost extends Model
{
}

final class MorphUnitVideo extends Model
{
}

abstract class AbstractMorphUnit extends Model
{
}
