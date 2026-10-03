<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Http\Response;
use App\Plugins\TestCase;
use App\Plugins\View;
use App\View\FragmentRenderResult;
use App\View\ViewNotFoundException;
use App\View\ViewRenderException;
use InvalidArgumentException;

/** Exercises the explicit HTTP bridge without changing direct View rendering. */
final class ViewResponseRenderTest extends TestCase
{
    public function testResponseMatchesBothRenderPathsWithoutEchoingAndSendsOnlyOnce(): void
    {
        $this->testApplication()->write('Project/Views/Layouts/Page.squehub.php',
            '<html><head>@stack(\'styles\')</head><body>@yield(\'body\')'
            . '@stack(\'scripts\')</body></html>');
        $this->testApplication()->write('Project/Views/Pages/Welcome.squehub.php',
            "@extends('Layouts.Page')@section('body')<p>{{ \$message }}</p>"
            . "@style('/welcome.css')@script('/welcome.js')@endsection");
        $this->app();
        $data = ['message' => 'Ẹ káàbọ̀ こんにちは مرحبا 😀'];

        $result = View::renderResult('Pages.Welcome', $data);
        ob_start();
        try {
            View::render('Pages.Welcome', $data);
            $legacyOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        ob_start();
        try {
            $response = View::response('Pages.Welcome', $data, status: 201,
                headers: ['X-View-Mode' => 'created']);
            $preSendOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $preSendOutput);
        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(\App\Plugins\Response::class, $response);
        self::assertSame(201, $response->status());
        self::assertSame('created', $response->header('X-View-Mode'));
        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame($result->html(), $response->content());
        self::assertSame($legacyOutput, $response->content());
        self::assertSame(1, substr_count($response->content(), '/welcome.css'));
        self::assertSame(1, substr_count($response->content(), '/welcome.js'));
        self::assertStringContainsString($data['message'], $response->content());

        ob_start();
        try {
            $response->send();
            $response->send();
            $sent = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame($response->content(), $sent);
    }

    public function testOneResponseRunsPageLayoutComponentComposerAndAssetCaptureOnce(): void
    {
        $this->testApplication()->write('Project/Views/Layouts/Once.squehub.php', <<<'VIEW'
@php $tick('layout'); @endphp<html><head>@stack('scripts')</head><body>@yield('body')</body></html>
VIEW);
        $this->testApplication()->write('Project/Views/Pages/Once.squehub.php', <<<'VIEW'
@extends('Layouts.Once')
@section('body')
@php $tick('page'); @endphp
@component('Once', ['tick' => $tick])@endcomponent
@push('scripts')@php $tick('asset'); @endphp<script src="/once.js"></script>@endpush
@endsection
VIEW);
        $this->testApplication()->write('Project/Views/Components/Once.squehub.php', <<<'VIEW'
@props(['tick'])@php $tick('component'); @endphp<b>PAGE</b>
VIEW);
        $this->app();
        $counts = ['page' => 0, 'layout' => 0, 'component' => 0, 'asset' => 0,
            'composer' => 0];
        $tick = static function (string $name) use (&$counts): void {
            ++$counts[$name];
        };
        View::compose('Pages.Once', static function () use (&$counts): array {
            ++$counts['composer'];
            return [];
        });

        ob_start();
        try {
            $response = View::response('Pages.Once', ['tick' => $tick]);
            $echoed = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $echoed);
        self::assertSame(['page' => 1, 'layout' => 1, 'component' => 1,
            'asset' => 1, 'composer' => 1], $counts);
        self::assertSame(1, substr_count($response->content(), '<b>PAGE</b>'));
        self::assertSame(1, substr_count($response->content(), '/once.js'));
        self::assertSame(200, $response->status());
    }

    public function testStatusesHeadersAndBodiesAreIndependentResponseState(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Value.squehub.php',
            '{{ $value }}');
        $this->app();

        $first = View::response('Pages.Value', ['value' => '0'], status: 202,
            headers: ['X-Mode' => 'first', 'content-type' => 'text/plain; charset=UTF-8']);
        $second = View::response('Pages.Value', ['value' => ''], status: 422,
            headers: ['X-Mode' => 'second']);
        self::assertSame('0', $first->content());
        self::assertSame('', $second->content());
        self::assertSame(202, $first->status());
        self::assertSame(422, $second->status());
        self::assertSame('first', $first->header('x-mode'));
        self::assertSame('second', $second->header('x-mode'));
        self::assertSame('text/plain; charset=UTF-8', $first->header('Content-Type'));
        self::assertSame('text/html; charset=UTF-8', $second->header('Content-Type'));
        self::assertCount(1, array_filter(array_keys($first->headers()),
            static fn (string $name): bool => strcasecmp($name, 'Content-Type') === 0));
    }

    public function testInvalidStatusAndHeaderUseNormalResponseValidation(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Valid.squehub.php', 'valid');
        $this->app();
        foreach ([0, 600] as $status) {
            try {
                View::response('Pages.Valid', status: $status);
                self::fail('Invalid HTTP status was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('HTTP status must be between 100 and 599.', $error->getMessage());
            }
        }
        try {
            View::response('Pages.Valid', headers: ['X-Probe' => "bad\r\nInjected: yes"]);
            self::fail('A header containing CRLF was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Invalid HTTP header name or value.', $error->getMessage());
        }
    }

    public function testFragmentRemainsNeutralAndRenderingFailureLeaksNoPartialOutput(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Fragment.squehub.php',
            "@fragment('body')<p>selected</p>@endfragment");
        $this->testApplication()->write('Project/Views/Pages/Failure.squehub.php',
            "PARTIAL<?php throw new \\RuntimeException('VIEW_RESPONSE_SECRET'); ?>");
        $this->app();

        $fragment = View::fragment('Pages.Fragment', 'body');
        self::assertInstanceOf(FragmentRenderResult::class, $fragment);
        self::assertSame('<p>selected</p>', $fragment->html());

        ob_start();
        try {
            try {
                View::response('Pages.Failure');
                self::fail('The failing View returned a Response.');
            } catch (ViewRenderException $error) {
                self::assertStringNotContainsString('VIEW_RESPONSE_SECRET', $error->getMessage());
            }
            self::assertSame('', (string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->expectException(ViewNotFoundException::class);
        View::response('Pages.Absent');
    }
}
