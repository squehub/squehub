<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Observability\CorrelationContext;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Explicit payload fixture lets tests inspect envelope metadata independently of job data. */
final class CorrelationCodecJob implements QueueJob
{
    public function handle(): void {}
    public function toQueuePayload(): array { return ['value' => 'safe']; }
    public static function fromQueuePayload(array $payload): static { return new static(); }
}

/** The ID format, scoped restoration, and Queue envelope are security boundaries. */
final class CorrelationContextTest extends TestCase
{
    public function testGeneratedIdsAreBoundedAndApplicationContextsAreIndependent(): void
    {
        $first = new CorrelationContext();
        $second = new CorrelationContext();
        $id = $first->begin();
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
        self::assertNull($second->current());
        self::assertNotSame($id, $second->begin());
        $first->clear();
        self::assertNull($first->current());
    }

    public function testNestedContextRestoresAfterSuccessAndFailure(): void
    {
        $context = new CorrelationContext();
        $outer = $context->begin();
        $inner = CorrelationContext::generate();
        self::assertSame($inner, $context->with($inner,
            static fn () => $context->current()));
        self::assertSame($outer, $context->current());
        try {
            $context->with($inner, static function (): never {
                throw new \RuntimeException('expected');
            });
        } catch (\RuntimeException) {}
        self::assertSame($outer, $context->current());
    }

    public function testInvalidIdsAreRejectedWithoutPublishingThem(): void
    {
        $context = new CorrelationContext();
        foreach (['', str_repeat('A', 32), "a\r\nother", str_repeat('a', 31),
            str_repeat('a', 33)] as $invalid) {
            self::assertFalse(CorrelationContext::valid($invalid));
            try { $context->begin($invalid); self::fail('Invalid ID was accepted.'); }
            catch (InvalidArgumentException) {}
            self::assertNull($context->current());
        }
    }

    public function testQueueEnvelopeCarriesOnlyValidatedMetadataAndReadsLegacyRecords(): void
    {
        $id = CorrelationContext::generate();
        $json = QueueCodec::encode(new CorrelationCodecJob(), $id);
        $parsed = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['correlation_id' => $id], $parsed['meta']);
        self::assertSame(['value' => 'safe'], $parsed['data']);
        self::assertSame($id, QueueCodec::correlationId($json));
        self::assertInstanceOf(CorrelationCodecJob::class, QueueCodec::decode($json));

        $legacy = QueueCodec::encode(new CorrelationCodecJob());
        self::assertArrayNotHasKey('meta', json_decode($legacy, true, 32, JSON_THROW_ON_ERROR));
        self::assertNull(QueueCodec::correlationId($legacy));
        self::assertInstanceOf(CorrelationCodecJob::class, QueueCodec::decode($legacy));
    }

    public function testCorruptQueueMetadataFailsInsteadOfBeingIgnored(): void
    {
        $base = json_decode(QueueCodec::encode(new CorrelationCodecJob()), true, 32, JSON_THROW_ON_ERROR);
        foreach ([['correlation_id' => "bad\r\nheader"],
            ['correlation_id' => str_repeat('A', 32)],
            ['correlation_id' => str_repeat('a', 32), 'secret' => 'x'],
            ['correlation_id' => 123]] as $invalid) {
            $base['meta'] = $invalid;
            $json = json_encode($base, JSON_THROW_ON_ERROR);
            try { QueueCodec::decode($json); self::fail('Corrupt metadata was accepted.'); }
            catch (QueueException $exception) {
                self::assertSame('Queue correlation metadata is invalid.', $exception->getMessage());
                self::assertStringNotContainsString('header', $exception->getMessage());
            }
        }
    }
}
