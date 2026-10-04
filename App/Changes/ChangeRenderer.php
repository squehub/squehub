<?php

declare(strict_types=1);

namespace App\Changes;

use Symfony\Component\Console\Formatter\OutputFormatter;

/** Presents the same bounded, content-free review format for every operation. */
final class ChangeRenderer
{
    public static function render(ChangePlan $plan, string $heading = 'SqueHub Change Plan'): string
    {
        $lines = [
            $heading,
            'Operation: ' . self::safe($plan->operation),
            'Target: ' . self::safe($plan->target),
            'Owner: ' . self::safe($plan->owner->type . ' ' . $plan->owner->name),
            'Plan: ' . $plan->fingerprint(),
        ];
        if ($plan->metadata !== null) {
            $metadata = $plan->metadata->toArray();
            $source = 'Source: ' . $metadata['source'];
            if ($metadata['source_sha256'] !== null) {
                $source .= ' sha256:' . substr($metadata['source_sha256'], 0, 16);
            }
            $lines[] = $source;
            foreach (['security' => 'Security review', 'compatibility' => 'Compatibility review',
                'verification' => 'Expected verification'] as $key => $label) {
                if ($metadata[$key] !== []) {
                    $lines[] = $label . ': ' . implode(', ', $metadata[$key]);
                }
            }
        }
        foreach (['create', 'modify', 'delete', 'state', 'autoload'] as $kind) {
            $matching = array_values(array_filter($plan->actions,
                static fn (ChangeAction $action): bool => $action->kind === $kind));
            if ($matching === []) { continue; }
            usort($matching, static fn (ChangeAction $a, ChangeAction $b): int =>
                strcmp($a->subject, $b->subject));
            $lines[] = strtoupper($kind);
            foreach ($matching as $action) {
                $line = '  ' . self::safe($action->subject);
                if (in_array($kind, ['modify', 'delete', 'state'], true)) {
                    $line .= ' [owner ' . self::safe($action->owner->key());
                    if ($action->before !== null) {
                        $line .= ', before sha256:' . substr($action->before, 0, 16);
                    }
                    if ($action->after !== null) {
                        $line .= ', after sha256:' . substr($action->after, 0, 16);
                    }
                    $line .= ']';
                }
                $line .= ' [risk ' . $action->risk;
                if ($action->category !== null) {
                    $line .= ', category ' . $action->category;
                }
                $line .= ']';
                if ($action->reason !== '') { $line .= ' — ' . self::safe($action->reason); }
                $lines[] = $line;
            }
        }
        $lines[] = 'Risk: ' . $plan->risk();
        if ($plan->hasConflicts()) {
            $lines[] = 'Status: BLOCKED';
        }
        foreach ($plan->warnings as $warning) {
            $lines[] = 'WARNING ' . self::safe($warning);
        }
        $lines[] = 'Conflicts: ' . count($plan->conflicts);
        foreach ($plan->conflicts as $conflict) {
            $lines[] = 'CONFLICT ' . self::safe($conflict);
        }
        $lines[] = 'No changes have been applied.';
        return implode(PHP_EOL, $lines);
    }

    /** Console metadata may come from a Package descriptor; escape markup. */
    private static function safe(string $value): string
    {
        return OutputFormatter::escape($value);
    }
}
