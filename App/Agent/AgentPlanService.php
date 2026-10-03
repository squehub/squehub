<?php

declare(strict_types=1);

namespace App\Agent;

use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Clis\Make\FeatureBlueprintGenerator;
use App\Database\Migrations\MigrationPlanner;
use App\Foundation\Application;
use App\Kits\KitManager;
use App\Packages\PackageManager;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Review-only bridge to the existing subsystem planners. Their private
 * prepared inputs never cross this boundary, and no generic Agent apply path
 * can bypass the subsystem's ownership and freshness checks.
 */
final class AgentPlanService
{
    public const OPERATIONS = [
        'migration_source', 'package_enable', 'kit_enable', 'feature_blueprint',
    ];

    public function __construct(private Application $app, private CapabilitySet $capabilities,
        private ?DateTimeImmutable $clock = null)
    {
    }

    /** @return array{plan:array<string,mixed>,fingerprint:string,provenance:array<string,string>,applied:bool,apply_supported:bool} */
    public function propose(string $operation, string $target = ''): array
    {
        if (!in_array($operation, self::OPERATIONS, true) || strlen($target) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || ($operation === 'migration_source' && $target !== '')
            || ($operation !== 'migration_source' && $target === '')) {
            throw new AgentException('Agent plan request is invalid.');
        }
        $this->capabilities->require('create_plan', $operation);
        try {
            $base = match ($operation) {
                'migration_source' => (new MigrationPlanner())->plan($this->app->basePath()),
                'package_enable' => $this->app->container()->make(PackageManager::class)->planEnable($target),
                'kit_enable' => $this->app->container()->make(KitManager::class)->planEnable($target),
                'feature_blueprint' => (new FeatureBlueprintGenerator($this->app->basePath()))->plan($target),
            };
        } catch (Throwable) {
            // Package source labels, file paths, and parser failures do not
            // belong in an MCP error even when the caller selected the target.
            throw new AgentException('Agent plan could not be created safely.');
        }
        try {
            $source = hash('sha256', $base->fingerprint() . "\0"
                . $this->capabilities->applicationFingerprint() . "\0" . AgentContext::VERSION);
            $metadata = new ChangePlanMetadata('agent', $source,
                ['untrusted_source'], ['framework_version'],
                ['file_checksum', 'activation_state', 'manual_review']);
            $plan = new ChangePlan($base->operation, $base->target, $base->owner,
                $base->actions, $base->warnings, $base->conflicts,
                $base->preconditions, $metadata);
            $payload = $plan->toArray();
            $fingerprint = $plan->fingerprint();
        } catch (Throwable) {
            throw new AgentException('Agent plan could not be created safely.');
        }
        $now = ($this->clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        return [
            'plan' => $payload,
            'fingerprint' => $fingerprint,
            'provenance' => ['source' => 'agent', 'framework_version' => AgentContext::VERSION,
                'application_fingerprint' => $this->capabilities->applicationFingerprint(),
                'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'requested_capability' => 'create_plan'],
            'applied' => false,
            'apply_supported' => false,
        ];
    }
}
