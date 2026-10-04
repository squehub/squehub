<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Data\QueuePayloadData;
use App\Data\TypedPayloadException;
use App\Data\TypedPayloadRegistry;
use App\Foundation\Application;
use App\Queue\QueueCodec;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Explicit registration controls which values may cross a persisted boundary. */
final class TypedPayloadRegistryTest extends TestCase
{
    private function registry(): TypedPayloadRegistry
    {
        $registry = new TypedPayloadRegistry();
        $registry->define('user.registration', 1, RegistrationPayload::class);
        return $registry;
    }

    public function testStableAliasVersionAndDeclaredFieldsRoundTrip(): void
    {
        $registry = $this->registry();
        $envelope = $registry->encode(new RegistrationPayload('u-7', 'PRIVATE_TOKEN'));
        self::assertSame(['type' => 'user.registration', 'version' => 1,
            'data' => ['user_id' => 'u-7']], $envelope);
        self::assertStringNotContainsString('PRIVATE_TOKEN', json_encode($envelope));
        $restored = $registry->decode($envelope);
        self::assertInstanceOf(RegistrationPayload::class, $restored);
        self::assertSame('u-7', $restored->userId);
        self::assertSame('', $restored->privateRuntimeToken());
        self::assertSame(QueuePayloadData::class,
            (new \ReflectionClass(\App\Plugins\QueuePayloadData::class))->getName());
        self::assertSame(TypedPayloadRegistry::class,
            (new \ReflectionClass(\App\Plugins\TypedPayloadRegistry::class))->getName());
        self::assertSame(TypedPayloadException::class,
            (new \ReflectionClass(\App\Plugins\TypedPayloadException::class))->getName());
    }

    public function testOnlyTrustedDefinitionsAreEligibleAndApplicationsAreIsolated(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $firstApp = new Application($firstProject->path());
            $secondApp = new Application($secondProject->path());
            $first = $firstApp->container()->make(TypedPayloadRegistry::class);
            $second = $secondApp->container()->make(TypedPayloadRegistry::class);
            self::assertNotSame($first, $second);
            self::assertSame($first, $firstApp->container()
                ->make(\App\Plugins\TypedPayloadRegistry::class));
            $first->define('user.registration', 1, RegistrationPayload::class);
            $envelope = $first->encode(new RegistrationPayload('u-1', 'secret'));
            $this->expectException(TypedPayloadException::class);
            $second->decode($envelope);
        } finally {
            $firstProject->remove();
            $secondProject->remove();
        }
    }

    public function testRegistrationRejectsDuplicateAliasClassAndMalformedDefinition(): void
    {
        $registry = $this->registry();
        foreach ([
            static fn () => $registry->define('user.registration', 1, AlternatePayload::class),
            static fn () => $registry->define('another', 1, RegistrationPayload::class),
            static fn () => $registry->define('Has Uppercase', 1, AlternatePayload::class),
            static fn () => $registry->define('another', 0, AlternatePayload::class),
            static fn () => $registry->define('another', 1, \stdClass::class),
        ] as $define) {
            try { $define(); self::fail('Invalid definition was registered.'); }
            catch (TypedPayloadException) { self::assertTrue(true); }
        }
        $this->expectException(TypedPayloadException::class);
        $registry->encode(new AlternatePayload());
    }

    public function testMalformedUnknownAndUnsupportedVersionsFailWithoutDataDisclosure(): void
    {
        $registry = $this->registry();
        $secret = 'PRIVATE_TYPED_PAYLOAD_SECRET';
        $valid = $registry->encode(new RegistrationPayload('u-2', $secret));
        foreach ([
            ['type' => 'missing', 'version' => 1, 'data' => ['secret' => $secret]],
            ['type' => 'user.registration', 'version' => 2, 'data' => ['secret' => $secret]],
            ['type' => 'user.registration', 'version' => '1', 'data' => []],
            ['type' => 'user.registration', 'version' => 1, 'data' => [], 'class' => \stdClass::class],
            ['type' => 'user.registration', 'version' => 1, 'data' => [0 => $secret]],
            ['type' => 'user.registration', 'version' => 1, 'data' => ['bad' => new \stdClass()]],
            ['type' => 'user.registration', 'version' => 1, 'data' => ['unexpected' => $secret]],
        ] as $invalid) {
            try { $registry->decode($invalid); self::fail('Malformed payload was accepted.'); }
            catch (TypedPayloadException $failure) {
                self::assertStringNotContainsString($secret, $failure->getMessage());
            }
        }
        $restored = $registry->decode($valid);
        self::assertInstanceOf(RegistrationPayload::class, $restored);
        self::assertSame('u-2', $restored->userId);
    }

    public function testProjectionRejectsNestedObjectsAndOversizedPayload(): void
    {
        $registry = new TypedPayloadRegistry();
        $registry->define('unsafe', 1, UnsafePayload::class);
        $registry->define('large', 1, LargePayload::class);
        foreach ([new UnsafePayload(), new LargePayload()] as $value) {
            try { $registry->encode($value); self::fail('Invalid projection was accepted.'); }
            catch (TypedPayloadException $failure) {
                self::assertStringNotContainsString('PRIVATE_TOKEN', $failure->getMessage());
            }
        }
        self::assertSame(60000, QueueCodec::MAX_PAYLOAD_BYTES);
    }

    public function testApplicationThrownProjectionOrReconstructionMessagesAreSanitized(): void
    {
        $registry = new TypedPayloadRegistry();
        $registry->define('broken', 1, BrokenPayload::class);
        try { $registry->encode(new BrokenPayload()); self::fail('Broken projection accepted.'); }
        catch (TypedPayloadException $failure) {
            self::assertStringNotContainsString('PRIVATE_TOKEN', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
        try { $registry->decode(['type' => 'broken', 'version' => 1, 'data' => []]);
            self::fail('Broken reconstruction accepted.'); }
        catch (TypedPayloadException $failure) {
            self::assertStringNotContainsString('PRIVATE_TOKEN', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testApplicationThrownFrameworkExceptionCannotLeakItsMessage(): void
    {
        $registry = new TypedPayloadRegistry();
        $registry->define('secret.exception', 1, SecretTypedExceptionPayload::class);
        try { $registry->encode(new SecretTypedExceptionPayload());
            self::fail('Secret-bearing projection exception escaped.'); }
        catch (TypedPayloadException $failure) {
            self::assertSame('Typed payload encoding failed.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
        try { $registry->decode(['type' => 'secret.exception', 'version' => 1, 'data' => []]);
            self::fail('Secret-bearing reconstruction exception escaped.'); }
        catch (TypedPayloadException $failure) {
            self::assertSame('Typed payload reconstruction failed.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }
}

/** A private runtime token is deliberately absent from its durable projection. */
final readonly class RegistrationPayload implements QueuePayloadData
{
    public function __construct(public string $userId, private string $runtimeToken) {}
    public function privateRuntimeToken(): string { return $this->runtimeToken; }
    public function toQueueData(): array { return ['user_id' => $this->userId]; }
    public static function fromQueueData(array $data): static
    {
        if (array_keys($data) !== ['user_id'] || !is_string($data['user_id'])) {
            throw new \RuntimeException('PRIVATE_TOKEN');
        }
        return new static($data['user_id'], '');
    }
}

/** An unregistered value cannot receive a type alias implicitly. */
final readonly class AlternatePayload implements QueuePayloadData
{
    public function toQueueData(): array { return []; }
    public static function fromQueueData(array $data): static { return new static(); }
}

/** Public object properties must not be encoded through JSON's object rules. */
final readonly class UnsafePayload implements QueuePayloadData
{
    public function toQueueData(): array { return ['nested' => ['object' => new \stdClass()]]; }
    public static function fromQueueData(array $data): static { return new static(); }
}

/** The typed envelope uses the same byte ceiling as QueueCodec. */
final readonly class LargePayload implements QueuePayloadData
{
    public function toQueueData(): array { return ['content' => str_repeat('x', 60000)]; }
    public static function fromQueueData(array $data): static { return new static(); }
}

/** Application error text may contain secrets and must not cross the boundary. */
final readonly class BrokenPayload implements QueuePayloadData
{
    public function toQueueData(): array { throw new \RuntimeException('PRIVATE_TOKEN'); }
    public static function fromQueueData(array $data): static
    {
        throw new \RuntimeException('PRIVATE_TOKEN');
    }
}

/** A callback can throw a framework exception containing its own secret. */
final readonly class SecretTypedExceptionPayload implements QueuePayloadData
{
    public function toQueueData(): array
    {
        throw new TypedPayloadException('PRIVATE_TYPED_CALLBACK_SECRET');
    }
    public static function fromQueueData(array $data): static
    {
        throw new TypedPayloadException('PRIVATE_TYPED_CALLBACK_SECRET');
    }
}
