<?php

declare(strict_types=1);

namespace App\Agent;

use App\Foundation\Application;

/**
 * One Application's immutable grants. Configuration may grant only supported
 * operations and can never turn a named capability into a host-wide permit.
 */
final class CapabilitySet
{
    /** @var array<string,bool|array<string,list<string>>> */
    private array $grants;
    private string $applicationFingerprint;

    /** @param array<array-key,mixed> $grants */
    public function __construct(Application $app, array $grants = [])
    {
        $root = realpath($app->basePath());
        if (!is_string($root)) {
            throw new AgentException('Agent application root is unavailable.');
        }
        $this->applicationFingerprint = hash('sha256', str_replace('\\', '/', $root));
        $validated = [];
        foreach ($grants as $name => $value) {
            $definition = is_string($name) ? CapabilityRegistry::get($name) : null;
            if ($definition === null) {
                throw new AgentException('Agent capability configuration is invalid.');
            }
            if ($value === false) {
                $validated[$name] = false;
                continue;
            }
            if (!$definition['supported'] || $definition['mode'] === 'write') {
                throw new AgentException('Unsupported Agent capability cannot be granted.');
            }
            if ($definition['scope'] === null) {
                if ($value !== true) {
                    throw new AgentException('Agent capability configuration is invalid.');
                }
                $validated[$name] = true;
                continue;
            }
            $scope = $definition['scope'];
            if (!is_array($value) || array_keys($value) !== [$scope]
                || !is_array($value[$scope]) || !array_is_list($value[$scope])
                || $value[$scope] === [] || count($value[$scope]) > 64) {
                throw new AgentException('Agent capability scope is invalid.');
            }
            $names = [];
            foreach ($value[$scope] as $entry) {
                if (!is_string($entry) || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,127}\z/D', $entry) !== 1) {
                    throw new AgentException('Agent capability scope is invalid.');
                }
                $names[$entry] = true;
            }
            $validated[$name] = [$scope => array_keys($names)];
        }
        $this->grants = $validated;
    }

    /**
     * The current Application is always implicit. Any caller-supplied subject
     * must match the configured connection or operation allowlist exactly.
     */
    public function allows(string $name, ?string $subject = null): bool
    {
        $definition = CapabilityRegistry::get($name);
        if ($definition === null || !$definition['supported']) return false;
        $grant = $this->grants[$name] ?? $definition['default'];
        if ($grant === false) return false;
        if ($definition['scope'] === null) return $grant === true;
        if (!is_array($grant) || $subject === null) return false;
        return in_array($subject, $grant[$definition['scope']] ?? [], true);
    }

    public function require(string $name, ?string $subject = null): void
    {
        if (!$this->allows($name, $subject)) {
            throw new AgentException('Agent capability is unavailable or denied.');
        }
    }

    public function applicationFingerprint(): string
    {
        return $this->applicationFingerprint;
    }

    /** @return list<array{name:string,risk:string,mode:string,allowed:bool,scope:?string,scope_values:list<string>,supported:bool}> */
    public function inventory(): array
    {
        $rows = [];
        foreach (CapabilityRegistry::all() as $name => $definition) {
            $grant = $this->grants[$name] ?? $definition['default'];
            $rows[] = ['name' => $name, 'risk' => $definition['risk'],
                'mode' => $definition['mode'], 'allowed' => $definition['supported']
                    && $grant !== false, 'scope' => $definition['scope'],
                'scope_values' => is_array($grant) && $definition['scope'] !== null
                    ? ($grant[$definition['scope']] ?? []) : [],
                'supported' => $definition['supported']];
        }
        return $rows;
    }
}
