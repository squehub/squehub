<?php

declare(strict_types=1);

namespace App\Recovery;

use App\Bundles\ProjectBundle;
use App\Foundation\Application;
use App\Packages\PackageFiles;
use Throwable;

/**
 * Classifies the independently recoverable parts of one Application.
 *
 * A project bundle verifies its own bytes but not their trusted origin. A
 * supplied database file gets a checksum, never an inferred consistent-
 * snapshot label: a live database needs a backend-specific capture method.
 * This inspector performs no restore, migration, provider call, or write.
 */
final class RecoveryPlanner
{
    private const MAX_SNAPSHOT_BYTES = 17179869184; // 16 GiB; larger files need operator tooling.

    public function __construct(private Application $application)
    {
    }

    public function plan(
        ?string $sourceBundle = null,
        ?string $databaseSnapshot = null,
        ?string $snapshotDriver = null,
        ?string $expectedSnapshotSha256 = null,
    ): RecoveryPlan {
        $config = $this->application->config();
        $connection = $config->get('database.default');
        $driver = is_string($connection)
            ? $config->get('database.connections.' . $connection . '.driver') : null;
        $driver = in_array($driver, ['sqlite', 'mysql'], true) ? $driver : 'unknown';

        $source = ['status' => 'missing', 'included' => false, 'integrity' => 'unverified'];
        if ($sourceBundle !== null) {
            try {
                // inspect() validates framing, paths, size bounds and checksums.
                (new ProjectBundle($this->application->basePath()))->inspect($sourceBundle);
                $source = ['status' => 'available', 'included' => false,
                    'integrity' => 'verified_against_manifest'];
            } catch (Throwable) {
                $source = ['status' => 'invalid', 'included' => false, 'integrity' => 'unverified'];
            }
        }

        $database = ['status' => 'missing', 'driver' => $driver,
            'consistency' => 'unverified', 'restored' => false];
        if ($expectedSnapshotSha256 !== null && $databaseSnapshot === null) {
            throw new RecoveryException('Snapshot checksum requires an explicit database artifact.');
        }
        if ($databaseSnapshot !== null) {
            if (!in_array($snapshotDriver, ['sqlite', 'mysql'], true)) {
                throw new RecoveryException('The supplied database snapshot needs an explicit driver.');
            }
            if ($driver !== $snapshotDriver) {
                throw new RecoveryException('The supplied snapshot driver differs from the selected database.');
            }
            $size = $this->physicalArtifactSize($databaseSnapshot);
            $checksum = @hash_file('sha256', $databaseSnapshot);
            if ($checksum === false) {
                throw new RecoveryException('The supplied database snapshot cannot be fingerprinted.');
            }
            if ($expectedSnapshotSha256 !== null
                && (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSnapshotSha256) !== 1
                    || !hash_equals($expectedSnapshotSha256, $checksum))) {
                throw new RecoveryException('The supplied database snapshot checksum does not match.');
            }
            $database = ['status' => 'provided_unverified', 'driver' => $driver,
                'consistency' => 'operator_declaration_required', 'restored' => false,
                'bytes' => $size, 'sha256' => $checksum,
                'checksum' => $expectedSnapshotSha256 === null
                    ? 'calculated_unanchored' : 'verified_against_expected',
                'captured_at' => null, 'capture_tool' => null,
                'database_identity' => 'unverified'];
        }

        $storageName = $config->get('storage.default', 'local');
        $storageDriver = is_string($storageName)
            ? $config->get('storage.drives.' . $storageName . '.driver') : null;
        $uploadsStatus = match ($storageDriver) {
            'local' => 'separate_local_backup_required',
            's3' => 'external_object_backup_required',
            'array' => 'ephemeral',
            default => 'unknown',
        };

        return new RecoveryPlan([
            'source' => $source,
            'database' => $database,
            'uploads' => ['status' => $uploadsStatus, 'included' => false],
            'secrets' => ['status' => 'required_separately', 'included' => false,
                'app_key' => 'required_not_included',
                'previous_crypt_keys' => 'retain_if_used_not_included',
                'database_credentials' => 'required_if_configured_not_included',
                'mail_provider_credentials' => 'required_if_configured_not_included',
                'oidc_client_secrets' => 'required_if_configured_not_included'],
            'runtime' => ['status' => 'excluded_rebuildable', 'included' => false],
            'sessions' => ['status' => 'policy_required', 'included' => false],
            'queue' => ['status' => 'separate_decision_required', 'included' => false,
                'delivery' => 'at_least_once'],
        ]);
    }

    /** Reject links and unavailable files before reading any operator artifact. */
    private function physicalArtifactSize(string $path): int
    {
        if (!is_file($path)) {
            throw new RecoveryException('The supplied database snapshot is unavailable.');
        }
        try {
            PackageFiles::assertPhysical($path);
        } catch (Throwable $exception) {
            throw new RecoveryException('The supplied database snapshot path is unsafe.', 0, $exception);
        }
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_SNAPSHOT_BYTES) {
            throw new RecoveryException('The supplied database snapshot size is unsupported.');
        }
        return $size;
    }
}
