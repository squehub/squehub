<?php

declare(strict_types=1);

namespace App\Changes;

use App\Contributions\ContributionOwner;
use InvalidArgumentException;
use JsonException;

/**
 * Immutable review data shared by generators, Package and Kit lifecycle,
 * bundles, and other subsystem-owned operations.
 * The internal version is deliberately not a public importable plan format.
 * Execution inputs and source contents are kept by the operation owner.
 */
final readonly class ChangePlan
{
    /**
     * @param list<ChangeAction> $actions
     * @param list<string> $warnings
     * @param list<string> $conflicts
     * @param array<string, string|null> $preconditions Expected SHA-256 or absence.
     */
    public function __construct(
        public string $operation,
        public string $target,
        public ContributionOwner $owner,
        public array $actions,
        public array $warnings = [],
        public array $conflicts = [],
        public array $preconditions = [],
        public ?ChangePlanMetadata $metadata = null,
    ) {
        if (count($actions) > 20000 || count($warnings) > 1000
            || count($conflicts) > 1000 || count($preconditions) > 20000) {
            throw new InvalidArgumentException('Change plan exceeds the review limit.');
        }
        self::validateText($operation, 160);
        self::validateText($target, 160);
        if ($operation === '' || $target === '') {
            throw new InvalidArgumentException('Change plan identity is invalid.');
        }
        if (preg_match('~(?:\A|\s)[A-Za-z]:[/\\\\]~', $operation . ' ' . $target) === 1
            || str_starts_with($target, '/') || str_starts_with($target, '\\')) {
            throw new InvalidArgumentException('Change plan identity must not contain a machine path.');
        }
        foreach ($actions as $action) {
            if (!$action instanceof ChangeAction) {
                throw new InvalidArgumentException('Change plan action is invalid.');
            }
        }
        foreach ([$warnings, $conflicts] as $messages) {
            foreach ($messages as $message) {
                if (!is_string($message) || $message === '') {
                    throw new InvalidArgumentException('Change plan message is invalid.');
                }
                self::validateText($message, 512);
            }
        }
        foreach ($preconditions as $subject => $fingerprint) {
            if (!is_string($subject) || $subject === '' || strlen($subject) > 512
                || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
                || str_starts_with($subject, '/') || str_starts_with($subject, '\\')
                || preg_match('/\A[A-Za-z]:/', $subject) === 1
                || str_contains($subject, '\\')) {
                throw new InvalidArgumentException('Change plan precondition subject is invalid.');
            }
            foreach (explode('/', $subject) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('Change plan precondition subject is unsafe.');
                }
            }
            if ($fingerprint !== null && (!is_string($fingerprint)
                || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1)) {
                throw new InvalidArgumentException('Change plan precondition fingerprint is invalid.');
            }
        }
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** Blocking conflicts outrank action risk without changing the legacy risk vocabulary. */
    public function reviewStatus(): string
    {
        return $this->hasConflicts() ? 'blocked' : $this->risk();
    }

    public function risk(): string
    {
        $rank = ['low' => 0, 'review' => 1, 'destructive' => 2];
        $risk = 'low';
        foreach ($this->actions as $action) {
            if ($rank[$action->risk] > $rank[$risk]) {
                $risk = $action->risk;
            }
        }
        return $risk;
    }

    /**
     * The digest excludes machine paths, clocks, executable content and
     * operation-private source handles. The optional metadata participates in
     * identity; plans built without it retain their original JSON and digest.
     */
    public function fingerprint(): string
    {
        try {
            $json = json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Change plan cannot be encoded.', 0, $exception);
        }
        return hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $actions = array_map(static fn (ChangeAction $action): array => $action->toArray(), $this->actions);
        usort($actions, static function (array $a, array $b): int {
            return [$a['kind'], $a['subject'], $a['owner']['type'], $a['owner']['name'],
                $a['before'], $a['after'], $a['risk'], $a['reason'], $a['category'] ?? '']
                <=> [$b['kind'], $b['subject'], $b['owner']['type'], $b['owner']['name'],
                    $b['before'], $b['after'], $b['risk'], $b['reason'], $b['category'] ?? ''];
        });
        $warnings = $this->warnings;
        $conflicts = $this->conflicts;
        sort($warnings, SORT_STRING);
        sort($conflicts, SORT_STRING);
        $preconditions = $this->preconditions;
        ksort($preconditions, SORT_STRING);
        $data = [
            'version' => 1,
            'operation' => $this->operation,
            'target' => $this->target,
            'owner' => $this->owner->toArray(),
            'actions' => $actions,
            'warnings' => $warnings,
            'conflicts' => $conflicts,
            'preconditions' => $preconditions,
        ];
        if ($this->metadata !== null) {
            $data['metadata'] = $this->metadata->toArray();
        }
        return $data;
    }

    private static function validateText(string $text, int $maximum): void
    {
        if (strlen($text) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $text) === 1
            || preg_match('/(?<![A-Za-z0-9_])(?:APP_KEY|[A-Za-z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY))\s*[:=]\s*\S+/i', $text) === 1) {
            throw new InvalidArgumentException('Change plan text is invalid.');
        }
    }
}
