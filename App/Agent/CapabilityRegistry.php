<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * The single Agent permission vocabulary. Unsupported entries are visible for
 * planning and documentation but can never become executable through config.
 */
final class CapabilityRegistry
{
    /** @var array<string,array{risk:string,mode:string,default:bool,scope:?string,supported:bool}> */
    private const DEFINITIONS = [
        'read_framework_metadata' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_application_contract' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_routes' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_package_metadata' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_docs' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_health_metadata' => ['risk' => 'low', 'mode' => 'read', 'default' => true, 'scope' => null, 'supported' => true],
        'read_schema' => ['risk' => 'sensitive', 'mode' => 'read', 'default' => false, 'scope' => 'connections', 'supported' => true],
        'read_source' => ['risk' => 'sensitive', 'mode' => 'read', 'default' => false, 'scope' => 'roots', 'supported' => false],
        'read_logs' => ['risk' => 'sensitive', 'mode' => 'read', 'default' => false, 'scope' => 'sources', 'supported' => false],
        'run_tests' => ['risk' => 'execution', 'mode' => 'execute', 'default' => false, 'scope' => 'suites', 'supported' => false],
        'create_plan' => ['risk' => 'review', 'mode' => 'read', 'default' => false, 'scope' => 'operations', 'supported' => true],
        'apply_plan' => ['risk' => 'mutation', 'mode' => 'write', 'default' => false, 'scope' => 'fingerprints', 'supported' => false],
        'run_migration' => ['risk' => 'mutation', 'mode' => 'write', 'default' => false, 'scope' => 'connections', 'supported' => false],
        'manage_package' => ['risk' => 'mutation', 'mode' => 'write', 'default' => false, 'scope' => 'packages', 'supported' => false],
    ];

    /** @return array<string,array{risk:string,mode:string,default:bool,scope:?string,supported:bool}> */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array{risk:string,mode:string,default:bool,scope:?string,supported:bool}|null */
    public static function get(string $name): ?array
    {
        return self::DEFINITIONS[$name] ?? null;
    }
}
