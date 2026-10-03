<?php

declare(strict_types=1);

namespace App\Upgrades;

use App\Changes\ChangePlan;
use InvalidArgumentException;

/** A static assessment backed by the same content-free Change Plan as other v2 changes. */
final readonly class UpgradeReport
{
    /**
     * @param list<array{status:string,code:string,subject:string}> $findings
     */
    public function __construct(private ChangePlan $plan, private array $findings)
    {
        foreach ($findings as $finding) {
            if (!in_array($finding['status'], ['review', 'blocked', 'unknown'], true)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $finding['code']) !== 1
                || strlen($finding['subject']) > 512
                || preg_match('/[\x00-\x1f\x7f]/', $finding['subject']) === 1) {
                throw new InvalidArgumentException('Upgrade finding is invalid.');
            }
        }
    }

    public function plan(): ChangePlan { return $this->plan; }

    /** @return list<array{status:string,code:string,subject:string}> */
    public function findings(): array { return $this->findings; }

    /** Unknown evidence cannot be interpreted as compatibility. */
    public function status(): string
    {
        if ($this->plan->hasConflicts()) return 'blocked';
        $status = $this->plan->actions === [] ? 'compatible' : 'review';
        foreach ($this->findings as $finding) {
            if ($finding['status'] === 'blocked') return 'blocked';
            if ($finding['status'] === 'unknown') $status = 'unknown';
            elseif ($finding['status'] === 'review' && $status === 'compatible') $status = 'review';
        }
        return $status;
    }

    /** @return array{status:string,plan:array<string,mixed>,findings:list<array{status:string,code:string,subject:string}>} */
    public function toArray(): array
    {
        return ['status' => $this->status(), 'plan' => $this->plan->toArray(),
            'findings' => $this->findings];
    }
}
