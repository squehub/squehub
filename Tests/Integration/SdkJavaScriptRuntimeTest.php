<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\Sdk\Emitters\JavaScriptEmitter;
use App\Api\Sdk\SdkModel;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises emitted ESM with Fetch, without installing a JavaScript package. */
final class SdkJavaScriptRuntimeTest extends TestCase
{
    public function testGeneratedRuntimeHandlesPathsAuthErrorsAndSessionJson(): void
    {
        try {
            $node = new Process(['node', '--version']);
            $node->run();
            if (!$node->isSuccessful()) self::markTestSkipped('Node is unavailable.');
        } catch (Throwable) {
            self::markTestSkipped('Node is unavailable.');
        }

        $project = new TemporaryProject();
        try {
            foreach ((new JavaScriptEmitter())->emit(self::model()) as $name => $source) {
                $project->write($name, $source);
            }
            $project->write('Runner.mjs', <<<'JS'
import assert from 'node:assert/strict';
import { SqueHubClient, SqueHubApiError, SqueHubProtocolError } from './index.js';

let calls = 0;
let mode = 'success';
const fetcher = async (url, options) => {
  calls++;
  if (mode === 'success') {
    assert.equal(url.toString(), 'https://api.test/api/users/Lagos%20%C3%A9%20%26%3F%3D%23?q=a+%26%3D%3F%23+%C3%A9');
    assert.equal(options.headers.get('Authorization'), 'Bearer runtime-token');
    assert.equal(options.headers.get('Accept'), 'application/vnd.squehub+json, application/json');
    return new Response(JSON.stringify({name: 'Ada'}), {status: 200,
      headers: {'Content-Type': 'application/vnd.squehub+json'}});
  }
  if (mode === 'error') {
    return new Response(JSON.stringify({error: {code: 'not_found', message: 'Missing'},
      request_id: '0123456789abcdef0123456789abcdef'}), {status: 404,
      headers: {'Content-Type': 'application/json', 'X-Request-ID': 'safe'}});
  }
  assert.equal(options.credentials, 'include');
  assert.equal(options.headers.get('X-CSRF-Token'), 'runtime-csrf');
  assert.equal(options.headers.get('Content-Type'), 'application/json');
  assert.equal(options.body, '{"name":"Ada"}');
  return new Response(null, {status: 204});
};

const client = new SqueHubClient({baseUrl: 'https://api.test', fetch: fetcher,
  token: () => 'runtime-token', csrfToken: () => 'runtime-csrf', credentials: 'include'});
const result = await client.users.show({path: {id: 'Lagos é &?=#'}, query: {q: 'a &=?# é'}});
assert.equal(result.name, 'Ada');
assert.equal(calls, 1);

for (const value of ['a/b', 'a\\b', '.', '..', '', 'a\n']) {
  await assert.rejects(client.users.show({path: {id: value}}), SqueHubProtocolError);
}
await assert.rejects(client.users.show({path: {id: 'safe'}, query: {fliter: 'x'}}),
  SqueHubProtocolError);
await assert.rejects(client.users.show({path: {id: 'safe'}, body: {unused: true}}),
  SqueHubProtocolError);
await assert.rejects(client.users.show({path: {id: 'safe'}, cookie: 'unexpected'}),
  SqueHubProtocolError);
assert.equal(calls, 1);
for (const baseUrl of ['https://api.test/?', 'https://api.test/#']) {
  assert.throws(() => new SqueHubClient({baseUrl, fetch: fetcher}), SqueHubProtocolError);
}

mode = 'error';
await assert.rejects(client.users.show({path: {id: 'safe'}}), error => {
  assert.ok(error instanceof SqueHubApiError);
  assert.equal(error.status, 404);
  assert.equal(error.code, 'not_found');
  assert.equal(error.requestId, '0123456789abcdef0123456789abcdef');
  assert.ok(!error.message.includes('runtime-token'));
  return true;
});

mode = 'session';
assert.equal(await client.users.update({body: {name: 'Ada', optional: undefined}}), undefined);
assert.equal(calls, 3);
JS
            );
            $run = new Process(['node', $project->path('Runner.mjs')], $project->path());
            $run->run();
            self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
            self::assertSame('', $run->getErrorOutput());
        } finally {
            $project->remove();
        }
    }

    private static function model(): SdkModel
    {
        $show = [
            'operation_id' => 'users.show', 'symbol' => 'UsersShow',
            'segments' => ['users', 'show'], 'method' => 'GET',
            'path' => '/api/users/{id}', 'api_version' => null,
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true,
                    'schema' => ['type' => 'string']],
                ['name' => 'q', 'in' => 'query', 'required' => false,
                    'schema' => ['type' => 'string']],
            ],
            'request_body' => null,
            'responses' => ['200' => ['content_type' => 'application/vnd.squehub+json',
                'schema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                ], 'required' => ['name']], 'headers' => []]],
            'security' => ['type' => 'pat'],
        ];
        $update = [
            'operation_id' => 'users.update', 'symbol' => 'UsersUpdate',
            'segments' => ['users', 'update'], 'method' => 'POST',
            'path' => '/api/users', 'api_version' => null,
            'parameters' => [],
            'request_body' => ['required' => true, 'content_type' => 'application/json',
                'schema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'optional' => ['type' => 'string'],
                ], 'required' => ['name']]],
            'responses' => ['204' => ['content_type' => null, 'schema' => null,
                'headers' => []]],
            'security' => ['type' => 'session', 'cookie_name' => 'SQUEHUBSESSID'],
        ];

        return new SdkModel(null, [], [], [$show, $update], [], str_repeat('0', 64));
    }
}
