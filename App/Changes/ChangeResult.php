<?php

declare(strict_types=1);

namespace App\Changes;

use InvalidArgumentException;

/**
 * An explicit application outcome. Partial filesystem failures cannot be
 * described as a rollback unless the operation actually restored every step.
 */
final readonly class ChangeResult
{
    /**
     * @param list<ChangeAction> $applied
     * @param list<ChangeAction> $unapplied
     */
    public function __construct(
        public ChangePlan $plan,
        public array $applied,
        public ?ChangeAction $failed = null,
        public array $unapplied = [],
        public bool $verified = false,
        public ?string $recoveryPath = null,
    ) {
        foreach ([$applied, $unapplied] as $actions) {
            foreach ($actions as $action) {
                if (!$action instanceof ChangeAction) {
                    throw new InvalidArgumentException('Change result action is invalid.');
                }
            }
        }
        if ($recoveryPath !== null && (str_starts_with($recoveryPath, '/')
            || str_starts_with($recoveryPath, '\\') || preg_match('/\A[A-Za-z]:/', $recoveryPath) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $recoveryPath) === 1
            || str_contains($recoveryPath, '\\')
            || in_array('', explode('/', $recoveryPath), true)
            || in_array('.', explode('/', $recoveryPath), true)
            || in_array('..', explode('/', $recoveryPath), true))) {
            throw new InvalidArgumentException('Change recovery path must be application-relative.');
        }
    }

    public function complete(): bool
    {
        return $this->failed === null && $this->unapplied === []
            && $this->verified && $this->recoveryPath === null;
    }

    /**
     * Report observed effects without serializing an exception or source data.
     * Applied order is retained because it is the order in which effects became
     * visible; the plan fingerprint identifies the reviewed proposal.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_sha256' => $this->plan->fingerprint(),
            'applied' => array_map(static fn (ChangeAction $action): array =>
                $action->toArray(), $this->applied),
            'failed' => $this->failed?->toArray(),
            'unapplied' => array_map(static fn (ChangeAction $action): array =>
                $action->toArray(), $this->unapplied),
            'verified' => $this->verified,
            'recovery_path' => $this->recoveryPath,
            'complete' => $this->complete(),
        ];
    }
}
