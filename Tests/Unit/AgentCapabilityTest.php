<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Agent\AgentException;
use App\Agent\AgentManager;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Agent grants remain scoped to one Application and cannot enable unfinished mutations. */
final class AgentCapabilityTest extends TestCase
{
    public function testDefaultInventoryAllowsOnlyBoundedReadCapabilities(): void
    {
        $project = TestApplication::temporary();
        try {
            $agent = new AgentManager($project->application());
            $byName = array_column($agent->capabilities(), null, 'name');
            foreach (['read_framework_metadata', 'read_application_contract', 'read_routes',
                'read_package_metadata', 'read_docs', 'read_health_metadata'] as $name) {
                self::assertTrue($byName[$name]['allowed']);
            }
            foreach (['read_schema', 'read_source', 'read_logs', 'run_tests',
                'create_plan', 'apply_plan', 'run_migration', 'manage_package'] as $name) {
                self::assertFalse($byName[$name]['allowed']);
            }
            self::assertSame('read-only', $agent->status()['mode']);
            self::assertNotContains('create_plan', array_column($agent->tools(), 'name'));
            self::assertNotContains('squehub://schema', array_column($agent->resources(), 'uri'));
            try {
                $agent->tool('create_plan', ['operation' => 'migration_source']);
                self::fail('An ungranted plan must fail.');
            } catch (AgentException $exception) {
                self::assertStringNotContainsString($project->root(), $exception->getMessage());
            }
            $this->expectException(AgentException::class);
            $agent->resource('squehub://schema');
        } finally {
            $project->cleanup();
        }
    }

    public function testExplicitScopesExposeOnlyAllowedSubjectsAndStayApplicationLocal(): void
    {
        $first = TestApplication::temporary(['agent' => ['grants' => [
            'read_schema' => ['connections' => ['testing']],
            'create_plan' => ['operations' => ['migration_source']],
        ]]]);
        $second = TestApplication::temporary();
        try {
            $enabled = new AgentManager($first->application());
            $disabled = new AgentManager($second->application());
            $byName = array_column($enabled->capabilities(), null, 'name');
            self::assertSame(['testing'], $byName['read_schema']['scope_values']);
            self::assertSame(['migration_source'], $byName['create_plan']['scope_values']);
            self::assertSame('inspection-and-proposal', $enabled->status()['mode']);
            self::assertContains('squehub://schema', array_column($enabled->resources(), 'uri'));
            self::assertContains('create_plan', array_column($enabled->tools(), 'name'));
            self::assertSame(['testing'], array_column($enabled->resource('squehub://schema')['connections'], 'connection'));
            self::assertNotContains('squehub://schema', array_column($disabled->resources(), 'uri'));
            self::assertNotSame($enabled->status()['application']['fingerprint'],
                $disabled->status()['application']['fingerprint']);
            $this->expectException(AgentException::class);
            $enabled->tool('inspect_schema', ['table' => 'users', 'connection' => 'production']);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    public function testInvalidAndUnsupportedGrantsFailBeforeUse(): void
    {
        foreach ([
            ['no_such_capability' => true],
            ['read_schema' => true],
            ['read_schema' => ['connections' => ['../production']]],
            ['create_plan' => ['operations' => []]],
            ['run_tests' => true],
            ['apply_plan' => ['fingerprints' => ['abc']]],
        ] as $grants) {
            $project = TestApplication::temporary(['agent' => ['grants' => $grants]]);
            try {
                try {
                    new AgentManager($project->application());
                    self::fail('An invalid grant must fail closed.');
                } catch (AgentException $exception) {
                    self::assertStringNotContainsString($project->root(), $exception->getMessage());
                }
            } finally {
                $project->cleanup();
            }
        }
    }

    public function testDisabledDefaultAndUnknownArgumentsAreDenied(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => ['read_docs' => false]]]);
        try {
            $agent = new AgentManager($project->application());
            self::assertNotContains('search_docs', array_column($agent->tools(), 'name'));
            foreach ([
                ['search_docs', ['query' => 'routing']],
                ['unknown', []],
                ['inspect_schema', ['table' => 'users', 'connection' => 'testing']],
                ['create_plan', ['operation' => 'migration_source', 'execute' => true]],
            ] as [$name, $arguments]) {
                try {
                    $agent->tool($name, $arguments);
                    self::fail('A denied or unknown operation must fail.');
                } catch (AgentException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $project->cleanup();
        }
    }

    public function testApplicationContractDoesNotBypassDisabledRouteGrant(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => ['read_routes' => false]]]);
        try {
            $agent = new AgentManager($project->application());
            $contract = $agent->resource('squehub://application/contract');
            self::assertSame('denied', $contract['state']);
            self::assertSame([], $contract['operations']);
            self::assertNotContains('squehub://routes', array_column($agent->resources(), 'uri'));
            $this->expectException(AgentException::class);
            $agent->resource('squehub://routes');
        } finally {
            $project->cleanup();
        }
    }
}
