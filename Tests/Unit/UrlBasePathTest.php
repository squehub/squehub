<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\UrlBasePath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Verifies deployment prefixes without involving route declarations or server globals. */
final class UrlBasePathTest extends TestCase
{
    public function testCanonicalRootAndNestedBasePaths(): void
    {
        self::assertSame('', (new UrlBasePath())->value());
        self::assertSame('', (new UrlBasePath('/'))->value());
        self::assertSame('/app', (new UrlBasePath('/app/'))->value());
        self::assertSame('/clients/acme', (new UrlBasePath('/clients/acme'))->value());
    }

    public function testStripRequiresAWholeLeadingSegmentAndKeepsRoot(): void
    {
        $base = new UrlBasePath('/app');
        self::assertSame('/', $base->strip('/app'));
        self::assertSame('/', $base->strip('/app/'));
        self::assertSame('/users/42', $base->strip('/app/users/42'));
        foreach (['/application', '/apple', '/app2', '/users/app', '/other'] as $outside) {
            self::assertNull($base->strip($outside), $outside);
        }
        self::assertSame('/app/users', (new UrlBasePath())->strip('/app/users'));
    }

    public function testPublicPathsUseOnePrefixAndPreserveRootDeployment(): void
    {
        $base = new UrlBasePath('/app');
        self::assertSame('/app/', $base->publicPath('/'));
        self::assertSame('/app/users/42', $base->publicPath('/users/42'));
        // A declared route containing the mount text is still an application
        // route; route URL generation must not guess the developer's intent.
        self::assertSame('/app/app/users/42', $base->publicPath('/app/users/42'));
        self::assertSame('/users/42', (new UrlBasePath())->publicPath('/users/42'));
        self::assertSame('/clients/acme/users', (new UrlBasePath('/clients/acme'))->publicPath('/users'));
    }

    public function testPublicLocationsPrefixInternalPathsOnly(): void
    {
        $base = new UrlBasePath('/app');
        self::assertSame('/app/login', $base->publicLocation('/login'));
        self::assertSame('/app/login', $base->publicLocation('/app/login'));
        self::assertSame('https://external.example.test/login',
            $base->publicLocation('https://external.example.test/login'));
    }

    public function testAssetUrlsPrefixApplicationAssetsButNotExternalAssets(): void
    {
        $base = new UrlBasePath('/app');
        self::assertSame('/app/assets/app.css', $base->assetUrl('/assets/app.css'));
        self::assertSame('/app/assets/app.css', $base->assetUrl('assets/app.css'));
        self::assertSame('/app/assets/app.css?v=1', $base->assetUrl('/assets/app.css?v=1'));
        self::assertSame('assets/app.css', (new UrlBasePath())->assetUrl('assets/app.css'));
        self::assertSame('https://cdn.example.test/app.css',
            $base->assetUrl('https://cdn.example.test/app.css'));
    }

    public function testAssetUrlsPreserveQueriesFragmentsAndAWholeMountedSegment(): void
    {
        $base = new UrlBasePath('/squehub-v2/');
        self::assertSame('/squehub-v2', $base->value());
        self::assertSame('/squehub-v2/assets/app.css?v=42#theme',
            $base->assetUrl('/assets/app.css?v=42#theme'));
        self::assertSame('/squehub-v2/assets/icons.svg#logo',
            $base->assetUrl('assets/icons.svg#logo'));
        self::assertSame('/squehub-v2/assets/app.css?v=42#theme',
            $base->assetUrl('/squehub-v2/assets/app.css?v=42#theme'));
        self::assertSame('/squehub-v2/application/assets/app.css',
            $base->assetUrl('/application/assets/app.css'));
        self::assertSame('/squehub-v2/apple/assets/app.css',
            $base->assetUrl('/apple/assets/app.css'));
        self::assertSame('/app/application/assets/app.css',
            (new UrlBasePath('/app'))->assetUrl('/application/assets/app.css'));
        self::assertSame('/clients/acme/assets/app.css',
            (new UrlBasePath('/clients/acme'))->assetUrl('/assets/app.css'));
        self::assertSame('/assets/app.css?v=42#theme',
            (new UrlBasePath())->assetUrl('/assets/app.css?v=42#theme'));
    }

    public function testAssetUrlsKeepSupportedExternalReferencesOutsideTheMount(): void
    {
        $base = new UrlBasePath('/squehub-v2');
        foreach ([
            'https://cdn.example.test/app.css',
            'http://cdn.example.test/app.js',
            'data:image/svg+xml,%3Csvg%3E',
            'blob:https://example.test/asset-id',
            '//cdn.example.test/app.css',
        ] as $external) {
            self::assertSame($external, $base->assetUrl($external), $external);
        }
    }

    public function testUnsafeLocalAndUnsupportedSchemeAssetUrlsAreRejectedAtRootAndMount(): void
    {
        $invalid = [
            '', '/assets/../secret.css', '/assets/%2e%2e/secret.css',
            '/assets/%2Fsecret.css', '/assets/%5csecret.css',
            '/assets/%GG.css', '/assets\\secret.css',
            'assets/../secret.css', 'assets/%2e%2e/secret.css',
            'assets/%GG.css', 'assets\\secret.css',
            "assets/app.css\r\nLocation: /outside", "assets/\0secret.css",
            'javascript:alert(1)', 'file:///etc/passwd', 'D:\\secret\\file.txt',
            'https:foo', 'https:/assets/app.css', 'https://', 'https:///assets/app.css',
            'http://?asset=app.css', 'https://user:pass@cdn.example.test/app.css',
            'https://cdn.example.test\\evil/app.css', '//?asset=app.css', '//#icon',
        ];
        foreach (['', '/squehub-v2'] as $mount) {
            $base = new UrlBasePath($mount);
            foreach ($invalid as $url) {
                try {
                    $base->assetUrl($url);
                    self::fail('Unsafe asset URL was accepted at ' . ($mount ?: '/') . ': ' . $url);
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testInvalidDeploymentPrefixFailsBeforeRequestHandling(): void
    {
        foreach ([
            'app', '//app', 'https://example.test/app', '/app//users', '/app/../admin',
            '/app/./users', '/app/%2e%2e/admin', '/app/%2Fadmin', '/app?x=1',
            '/app#section', "/app\\users", "/app\r\nLocation: /outside",
        ] as $invalid) {
            try {
                new UrlBasePath($invalid);
                self::fail('Unsafe deployment prefix was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true, $invalid);
            }
        }
    }
}
