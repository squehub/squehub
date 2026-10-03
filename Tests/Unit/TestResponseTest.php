<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\JsonResponse;
use App\Http\RedirectResponse;
use App\Http\Response;
use App\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** Exercises fluent HTTP assertions and secret-safe failure messages. */
final class TestResponseTest extends TestCase
{
    public function testResponseInspectionAndStatusAssertionsRemainFluent(): void
    {
        $original = new Response('Welcome', 200, ['X-Request-ID' => 'fixture-id']);
        $response = new TestResponse($original);

        self::assertSame($original, $response->response());
        self::assertSame(200, $response->status());
        self::assertSame('Welcome', $response->content());
        self::assertSame('fixture-id', $response->header('x-request-id'));
        self::assertSame($response, $response->assertStatus(200)->assertOk()
            ->assertHeader('X-Request-ID')->assertHeader('X-Request-ID', 'fixture-id')
            ->assertContains('Welcome')->assertNotContains('password'));

        (new TestResponse(new Response('created', 201)))->assertCreated();
        (new TestResponse(new Response('', 204)))->assertNoContent();
    }

    public function testRedirectAssertionsRequireRedirectStatusAndLocation(): void
    {
        (new TestResponse(new RedirectResponse('/dashboard')))
            ->assertRedirect()->assertRedirect('/dashboard')->assertStatus(302);
        (new TestResponse(new RedirectResponse('/form', 303)))
            ->assertRedirect('/form')->assertStatus(303);

        self::assertFailureWithout('private-destination', static function (): void {
            (new TestResponse(new RedirectResponse('/private-destination')))
                ->assertRedirect('/other');
        });
        self::assertFailureWithout('private-destination', static function (): void {
            (new TestResponse(new Response('private-destination')))->assertRedirect();
        });
    }

    public function testJsonAssertionsUseStrictExactPathsIncludingListIndicesAndNull(): void
    {
        $response = new TestResponse(new JsonResponse([
            'data' => ['id' => 7, 'name' => null],
            'items' => [['id' => 8]],
        ]));

        self::assertSame($response, $response->assertJson()->assertJsonPath('data.id', 7)
            ->assertJsonPath('items.0.id', 8)->assertJsonPath('data.name', null)
            ->assertJsonHas('data.name')->assertJsonMissing('data.password'));

        self::assertFailureWithout('7', static function () use ($response): void {
            $response->assertJsonPath('data.id', '7');
        });
        self::assertFailureMentionsPath('data.name', static function () use ($response): void {
            $response->assertJsonMissing('data.name');
        });
    }

    public function testJsonValidationRejectsWrongMediaTypeInvalidBodyAndInvalidPath(): void
    {
        self::assertFailureWithout('private-body', static function (): void {
            (new TestResponse(new Response('private-body')))->assertJson();
        });
        self::assertFailureWithout('private-body', static function (): void {
            (new TestResponse(new Response('{private-body', 200,
                ['Content-Type' => 'application/problem+json'])))->assertJson();
        });
        self::assertFailureWithout('private-body', static function (): void {
            (new TestResponse(new JsonResponse(['private-body' => true])))->assertJsonHas('private-body..x');
        });
    }

    public function testAssertionFailuresDoNotExposeSensitiveResponseOrExpectedValues(): void
    {
        $secret = 'SQUEHUB_TEST_RESPONSE_SECRET_DO_NOT_LEAK';
        $response = new TestResponse(new JsonResponse(['token' => $secret], 403,
            ['X-Private-Token' => $secret]));

        foreach ([
            static fn () => $response->assertStatus(200),
            static fn () => $response->assertHeader('X-Private-Token', 'other'),
            static fn () => $response->assertJsonPath('token', 'other'),
            static fn () => $response->assertJsonPath('missing', $secret),
            static fn () => $response->assertContains('other'),
            static fn () => $response->assertNotContains($secret),
        ] as $assertion) {
            self::assertFailureWithout($secret, $assertion);
        }
    }

    /** Inspect only PHPUnit's message, never its response-content diff output. */
    private static function assertFailureWithout(string $secret, callable $assertion): void
    {
        $failed = false;
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            $failed = true;
            self::assertStringNotContainsString($secret, $failure->getMessage());
        }
        self::assertTrue($failed, 'The response assertion should have failed.');
    }

    /** Path context is useful while JSON content and comparison values stay private. */
    private static function assertFailureMentionsPath(string $path, callable $assertion): void
    {
        $failed = false;
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            $failed = true;
            self::assertStringContainsString('"' . $path . '"', $failure->getMessage());
        }
        self::assertTrue($failed, 'The response assertion should have failed.');
    }
}
