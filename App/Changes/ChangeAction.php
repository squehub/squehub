<?php

declare(strict_types=1);

namespace App\Changes;

use App\Contributions\ContributionOwner;
use InvalidArgumentException;

/**
 * One portable, content-free description of a proposed source or state change.
 * Fingerprints describe bytes or state; executable content stays with the
 * operation that already knows how to publish it safely.
 */
final readonly class ChangeAction
{
    public function __construct(
        public string $kind,
        public string $subject,
        public ContributionOwner $owner,
        public ?string $before,
        public ?string $after,
        public string $risk,
        public string $reason = '',
        public ?string $category = null,
    ) {
        if (!in_array($kind, ['create', 'modify', 'delete', 'state', 'autoload'], true)) {
            throw new InvalidArgumentException('Change action kind is invalid.');
        }
        if (!in_array($risk, ['low', 'review', 'destructive'], true)) {
            throw new InvalidArgumentException('Change action risk is invalid.');
        }
        if ($category !== null && !in_array($category, [
            'file', 'directory', 'activation', 'configuration', 'package', 'kit',
            'bundle', 'migration', 'database', 'asset', 'composer', 'autoload',
        ], true)) {
            throw new InvalidArgumentException('Change action category is invalid.');
        }
        self::validateSubject($subject);
        self::validateText($reason, 512);
        foreach ([$before, $after] as $fingerprint) {
            if ($fingerprint !== null && preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Change action fingerprint is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kind,
            'subject' => $this->subject,
            'owner' => $this->owner->toArray(),
            'before' => $this->before,
            'after' => $this->after,
            'risk' => $this->risk,
            'reason' => $this->reason,
        ];
        if ($this->category !== null) {
            $data['category'] = $this->category;
        }
        return $data;
    }

    private static function validateSubject(string $subject): void
    {
        self::validateText($subject, 512);
        if ($subject === '' || str_starts_with($subject, '/') || str_starts_with($subject, '\\')
            || preg_match('/\A[A-Za-z]:/', $subject) === 1 || str_contains($subject, '\\')) {
            throw new InvalidArgumentException('Change action subject must be application-relative.');
        }
        foreach (explode('/', $subject) as $segment) {
            if ($segment === '.' || $segment === '..' || $segment === '') {
                throw new InvalidArgumentException('Change action subject is unsafe.');
            }
        }
    }

    private static function validateText(string $text, int $maximum): void
    {
        if (strlen($text) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $text) === 1
            || preg_match('/(?<![A-Za-z0-9_])(?:APP_KEY|[A-Za-z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY))\s*[:=]\s*\S+/i', $text) === 1) {
            throw new InvalidArgumentException('Change action text is invalid.');
        }
    }
}
