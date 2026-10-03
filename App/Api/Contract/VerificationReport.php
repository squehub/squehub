<?php

declare(strict_types=1);

namespace App\Api\Contract;

use JsonException;

/**
 * Deterministic verification evidence for CI and later tooling. A report is a
 * snapshot of this run, not a cached claim about future application behavior.
 */
final readonly class VerificationReport
{
    /**
     * @param array<string,int> $summary
     * @param list<VerificationFinding> $findings
     */
    public function __construct(private array $summary, private array $findings,
        private bool $failOnWarning = false)
    {
    }

    public function passed(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === 'error'
                || ($this->failOnWarning && $finding->severity === 'warning')) return false;
        }
        return true;
    }

    public function exitCode(): int { return $this->passed() ? 0 : 1; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $findings = array_map(static fn (VerificationFinding $finding): array => $finding->toArray(),
            $this->findings);
        usort($findings, static fn (array $a, array $b): int => [
            $a['operation_id'] ?? '', $a['case'] ?? '', $a['code'], $a['path'] ?? '', $a['severity']
        ] <=> [
            $b['operation_id'] ?? '', $b['case'] ?? '', $b['code'], $b['path'] ?? '', $b['severity']
        ]);
        return [
            'squehub_verification' => '1',
            'passed' => $this->passed(),
            'summary' => $this->summary,
            'findings' => $findings,
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (JsonException $exception) {
            throw new ContractException('Verification report could not be encoded.', 0, $exception);
        }
    }

    public function toText(): string
    {
        $summary = $this->summary;
        $lines = [
            'SqueHub API Contract Verification',
            'Public operations: ' . ($summary['public_operations'] ?? 0),
            'With cases: ' . ($summary['operations_with_cases'] ?? 0),
            'Without cases: ' . ($summary['operations_without_cases'] ?? 0),
            'Declared statuses: ' . ($summary['declared_response_statuses'] ?? 0),
            'Exercised statuses: ' . ($summary['statuses_exercised'] ?? 0),
            'Cases executed: ' . ($summary['cases_executed'] ?? 0),
            'Passed: ' . ($summary['cases_passed'] ?? 0),
            'Failed: ' . ($summary['cases_failed'] ?? 0),
            'Skipped: ' . ($summary['cases_skipped'] ?? 0),
            'Warnings: ' . ($summary['warnings'] ?? 0),
            'Errors: ' . ($summary['errors'] ?? 0),
        ];
        foreach ($this->toArray()['findings'] as $finding) {
            $label = strtoupper($finding['severity']) . ' ' . $finding['code'];
            if ($finding['operation_id'] !== null) $label .= ' ' . $finding['operation_id'];
            if ($finding['case'] !== null) $label .= ' [' . $finding['case'] . ']';
            if ($finding['path'] !== null) $label .= ' at ' . $finding['path'];
            $lines[] = $label . ': ' . $finding['message'];
        }
        $lines[] = 'Overall: ' . ($this->passed() ? 'PASS' : 'FAIL');
        return implode("\n", $lines) . "\n";
    }
}
