<?php

declare(strict_types=1);

namespace App\Testing;

use App\Http\Response;
use JsonException;
use PHPUnit\Framework\Assert;

/**
 * Adds focused PHPUnit assertions to the real HTTP response returned by Kernel.
 *
 * Failure messages deliberately omit response bodies, JSON values, headers,
 * and comparison needles because these may contain credentials or tokens.
 */
final class TestResponse
{
    public function __construct(private Response $response)
    {
    }

    public function response(): Response { return $this->response; }
    public function status(): int { return $this->response->status(); }
    public function content(): string { return $this->response->content(); }
    public function header(string $name): ?string { return $this->response->header($name); }

    public function assertStatus(int $status): static
    {
        Assert::assertTrue($this->status() === $status,
            "Expected response status {$status}; received {$this->status()}.");
        return $this;
    }

    public function assertOk(): static { return $this->assertStatus(200); }
    public function assertCreated(): static { return $this->assertStatus(201); }
    public function assertNoContent(): static { return $this->assertStatus(204); }

    /** A redirect needs both a redirect status and a Location header. */
    public function assertRedirect(?string $location = null): static
    {
        Assert::assertTrue(in_array($this->status(), [301, 302, 303, 307, 308], true),
            "Expected a redirect response; received status {$this->status()}.");
        $actual = $this->header('Location');
        Assert::assertTrue($actual !== null && $actual !== '',
            'Expected a redirect response with a Location header.');
        if ($location !== null) {
            Assert::assertTrue($actual === $location,
                'Expected the redirect Location to match the supplied destination.');
        }
        return $this;
    }

    /** The optional value is compared strictly but never repeated on failure. */
    public function assertHeader(string $name, ?string $value = null): static
    {
        $actual = $this->header($name);
        Assert::assertTrue($actual !== null, 'Expected response header is missing.');
        if ($value !== null) {
            Assert::assertTrue($actual === $value,
                'Expected response header value to match the supplied value.');
        }
        return $this;
    }

    /** JSON assertions require the same content type understood by Request. */
    public function assertJson(): static
    {
        $this->decodedJson();
        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): static
    {
        [$found, $value] = $this->lookup($path);
        Assert::assertTrue($found, 'Expected JSON path ' . self::pathLabel($path) . ' is missing.');
        Assert::assertTrue($value === $expected,
            'Expected JSON path ' . self::pathLabel($path) . ' to strictly match the supplied value.');
        return $this;
    }

    public function assertJsonHas(string $path): static
    {
        [$found] = $this->lookup($path);
        Assert::assertTrue($found, 'Expected JSON path ' . self::pathLabel($path) . ' is missing.');
        return $this;
    }

    /** Missing means this exact dotted path is absent, including a final null. */
    public function assertJsonMissing(string $path): static
    {
        [$found] = $this->lookup($path);
        Assert::assertFalse($found, 'Expected JSON path ' . self::pathLabel($path) . ' to be absent.');
        return $this;
    }

    public function assertContains(string $text): static
    {
        Assert::assertTrue(str_contains($this->content(), $text),
            'Expected response content to contain the supplied text.');
        return $this;
    }

    public function assertNotContains(string $text): static
    {
        Assert::assertFalse(str_contains($this->content(), $text),
            'Expected response content not to contain the supplied text.');
        return $this;
    }

    /**
     * JSON paths support only dot-separated object keys and numeric list keys.
     * There is no escaping, wildcard, or general JSONPath evaluation.
     *
     * @return array{bool, mixed}
     */
    private function lookup(string $path): array
    {
        Assert::assertTrue($path !== '' && !in_array('', explode('.', $path), true),
            'JSON path must contain nonempty dot-separated segments.');
        $value = $this->decodedJson();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }
        return [true, $value];
    }

    private function decodedJson(): mixed
    {
        $type = strtolower(trim(explode(';', (string) $this->header('Content-Type'), 2)[0]));
        Assert::assertTrue($type === 'application/json' || str_ends_with($type, '+json'),
            'Expected a JSON Content-Type response header.');
        try {
            return json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Assert::fail('Expected a valid JSON response body.');
        }
    }

    /** Bound and JSON-escape a developer-supplied path without exposing values. */
    private static function pathLabel(string $path): string
    {
        if (strlen($path) > 120) return '[path longer than 120 bytes]';
        return (string) json_encode($path, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
            | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
