<?php

declare(strict_types=1);

namespace App\View\Assets;

use App\Core\ViewEscaper;
use App\Foundation\UrlBasePath;
use App\Frontend\AssetMapper;
use App\Frontend\FrontendManager;
use Stringable;

/**
 * Collects assets for one top-level View render. Layouts execute child-first,
 * so entries are ordered only after the complete inheritance tree is known.
 * No asset or once key survives this object, even when rendering fails.
 */
final class AssetRenderState
{
    /** @var array<int, string> */
    private array $layouts = [];

    /** @var list<string> */
    private array $fragments = [];

    /** @var list<array{owner: string, kind: string, stack: string, value: ?string, once: ?string, prepend: bool}> */
    private array $declarations = [];

    /** @var array<string, string> */
    private array $tokens = [];

    private readonly string $nonce;

    /**
     * @param list<array{owners: list<string>, kind: string, url: string, once: ?string}> $external
     */
    public function __construct(private readonly array $external = [],
        private readonly ?UrlBasePath $basePath = null,
        private readonly ?AssetMapper $mapper = null,
        private readonly ?FrontendManager $frontendManager = null)
    {
        $this->nonce = bin2hex(random_bytes(16));
    }

    public function participateLayout(string $owner, int $depth): void
    {
        $this->layouts[$depth] = $owner;
    }

    public function participateInclude(string $owner): void
    {
        $this->participateFragment($owner);
    }

    /** Reserve component ownership before evaluating its caller-owned slots. */
    public function participateComponent(string $owner): void
    {
        $this->participateFragment($owner);
    }

    private function participateFragment(string $owner): void
    {
        if (!in_array($owner, $this->fragments, true)) {
            $this->fragments[] = $owner;
        }
    }

    public function style(string $owner, mixed $url, mixed $once = null): void
    {
        $this->direct($owner, 'style', $url, $once);
    }

    public function script(string $owner, mixed $url, mixed $once = null): void
    {
        $this->direct($owner, 'script', $url, $once);
    }

    /** Register one logical frontend entry without emitting at declaration time. */
    public function frontend(string $owner, string $name): void
    {
        if (strlen($name) > 128
            || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new AssetException('Frontend entry name is invalid.');
        }
        $this->declarations[] = [
            'owner' => $owner, 'kind' => 'frontend', 'stack' => '',
            'value' => $name, 'once' => null, 'prepend' => false,
        ];
    }

    /** Reserve the declaration position before evaluating its captured body. */
    public function reserveBlock(string $owner, string $stack, mixed $once, bool $prepend): int
    {
        self::assertStack($stack);
        $this->declarations[] = [
            'owner' => $owner, 'kind' => 'block', 'stack' => $stack,
            'value' => null, 'once' => self::onceKey($once), 'prepend' => $prepend,
        ];
        return array_key_last($this->declarations);
    }

    public function completeBlock(int $index, string $html): void
    {
        if (!isset($this->declarations[$index]) || $this->declarations[$index]['kind'] !== 'block'
            || $this->declarations[$index]['value'] !== null) {
            throw new AssetException('Asset capture state is invalid.');
        }
        $this->declarations[$index]['value'] = $html;
    }

    /**
     * A random render-local token defers output until layouts and later
     * declarations have executed. Repeated stack positions share one token.
     */
    public function stack(string $name): string
    {
        self::assertStack($name);
        foreach ($this->tokens as $token => $stack) {
            if ($stack === $name) {
                return $token;
            }
        }
        $token = "\x1E" . $this->nonce . ':' . hash('sha256', $name) . "\x1F";
        $this->tokens[$token] = $name;
        return $token;
    }

    /** Replace placeholders after the outermost View frame has finished. */
    public function finalize(string $output): string
    {
        return $this->finalizeOutput($output, false)['html'];
    }

    /**
     * A Fragment has no Layout stack positions to request its resources. Return
     * every participating named stack with the same ordering and once rules as
     * a full render, after all Fragment content has executed.
     *
     * @return array{html: string, stacks: array<string, string>}
     */
    public function finalizeFragment(string $output): array
    {
        return $this->finalizeOutput($output, true);
    }

    /** @return array{html: string, stacks: array<string, string>} */
    private function finalizeOutput(string $output, bool $includeAllStacks): array
    {
        $ordered = $this->orderedEntries();
        $stacks = [];
        $seenDirect = [];
        $seenOnce = [];
        $seenFrontend = [];
        $seenGenerated = [];
        foreach ($ordered as $entry) {
            $value = $entry['value'];
            if ($value === null) {
                throw new AssetException('An asset block was not closed.');
            }
            if ($entry['kind'] === 'frontend') {
                if (isset($seenFrontend[$value])) { continue; }
                $seenFrontend[$value] = true;
                $manager = $this->frontendManager
                    ?? throw new AssetException('Frontend entry requires an Application.');
                foreach ($manager->entryTags($value) as $stack => $tags) {
                    foreach ($tags as $tag) {
                        $identity = hash('sha256', $stack . "\0" . $tag);
                        if (isset($seenGenerated[$identity])) { continue; }
                        $seenGenerated[$identity] = true;
                        $stacks[$stack]['normal'][] = $tag;
                    }
                }
                continue;
            }
            // Direct and registered assets share one public URL projection.
            // Captured @push HTML is application-owned markup and is untouched.
            if ($entry['kind'] !== 'block') {
                if ($this->mapper !== null) {
                    $value = $this->mapper->url($value);
                } elseif ($this->basePath !== null) {
                    $value = $this->basePath->assetUrl($value);
                }
            }

            // Once is checked before direct-resource deduplication so a
            // conflicting declaration cannot be hidden by an earlier URL.
            $once = $entry['once'];
            if ($once !== null) {
                $key = hash('sha256', $once);
                $identity = hash('sha256', $entry['kind'] . "\0" . $entry['stack']
                    . "\0" . $value . "\0" . ($entry['prepend'] ? '1' : '0'));
                if (isset($seenOnce[$key])) {
                    if ($seenOnce[$key] !== $identity) {
                        throw new AssetException('An asset once key has conflicting declarations.');
                    }
                    continue;
                }
                $seenOnce[$key] = $identity;
            }

            if ($entry['kind'] !== 'block') {
                $resource = hash('sha256', $entry['kind'] . "\0" . $entry['stack']
                    . "\0" . $value);
                if (isset($seenDirect[$resource])) {
                    continue;
                }
                $seenDirect[$resource] = true;
                $escaped = ViewEscaper::escape($value);
                $html = $entry['kind'] === 'style'
                    ? '<link rel="stylesheet" href="' . $escaped . '">'
                    : '<script src="' . $escaped . '"></script>';
            } else {
                $html = $value;
            }
            $group = $entry['prepend'] ? 'prepend' : 'normal';
            $stacks[$entry['stack']][$group][] = $html;
        }

        // Stack HTML can itself contain a placeholder captured by @push or
        // @section. Resolve recursively, rejecting self-reference rather than
        // leaking a token or looping forever.
        $resolved = [];
        $resolving = [];
        $expandStack = function (string $name) use (&$expandStack, &$resolved, &$resolving, $stacks): string {
            if (isset($resolved[$name])) {
                return $resolved[$name];
            }
            if (isset($resolving[$name])) {
                throw new AssetException('Circular asset stack reference.');
            }
            $resolving[$name] = true;
            $items = array_merge($stacks[$name]['prepend'] ?? [], $stacks[$name]['normal'] ?? []);
            $html = implode("\n", $items);
            $replacements = [];
            foreach ($this->tokens as $token => $target) {
                if (str_contains($html, $token)) {
                    $replacements[$token] = $expandStack($target);
                }
            }
            unset($resolving[$name]);
            return $resolved[$name] = strtr($html, $replacements);
        };

        $finalStacks = [];
        if ($includeAllStacks) {
            foreach (array_keys($stacks) as $name) {
                $finalStacks[$name] = $expandStack($name);
            }
        }

        $replacements = [];
        foreach ($this->tokens as $token => $name) {
            if (str_contains($output, $token)) {
                $replacements[$token] = $expandStack($name);
            }
        }
        return ['html' => strtr($output, $replacements), 'stacks' => $finalStacks];
    }

    /** Reject non-text values before they reach an HTML attribute or file path. */
    public static function url(mixed $value): string
    {
        if (!is_string($value) && !$value instanceof Stringable) {
            throw new AssetException('An asset URL must be text.');
        }
        $url = (string) $value;
        if (trim($url) === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new AssetException('An asset URL is empty or contains control characters.');
        }
        return $url;
    }

    /** @return ?string The key is kept only for the active render. */
    public static function onceKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !$value instanceof Stringable) {
            throw new AssetException('An asset once key must be text.');
        }
        $key = (string) $value;
        if (trim($key) === '' || strlen($key) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new AssetException('An asset once key is invalid.');
        }
        return $key;
    }

    private function direct(string $owner, string $kind, mixed $url, mixed $once): void
    {
        $this->declarations[] = [
            'owner' => $owner, 'kind' => $kind,
            'stack' => $kind === 'style' ? 'styles' : 'scripts',
            'value' => self::url($url), 'once' => self::onceKey($once),
            'prepend' => false,
        ];
    }

    /**
     * External declarations precede template declarations for their first
     * participating owner. Physical filenames do not determine ownership.
     *
     * @return list<array{owner: string, kind: string, stack: string, value: ?string, once: ?string, prepend: bool}>
     */
    private function orderedEntries(): array
    {
        $layouts = $this->layouts;
        krsort($layouts, SORT_NUMERIC);
        $owners = array_values($layouts);
        foreach ($this->fragments as $owner) {
            if (!in_array($owner, $owners, true)) {
                $owners[] = $owner;
            }
        }

        $ordered = [];
        $externalUsed = [];
        foreach ($owners as $owner) {
            foreach ($this->external as $index => $entry) {
                if (isset($externalUsed[$index]) || !in_array($owner, $entry['owners'], true)) {
                    continue;
                }
                $externalUsed[$index] = true;
                $ordered[] = [
                    'owner' => $owner, 'kind' => $entry['kind'],
                    'stack' => $entry['kind'] === 'style' ? 'styles' : 'scripts',
                    'value' => $entry['url'], 'once' => $entry['once'], 'prepend' => false,
                ];
            }
            foreach ($this->declarations as $entry) {
                if ($entry['owner'] === $owner) {
                    $ordered[] = $entry;
                }
            }
        }
        return $ordered;
    }

    private static function assertStack(string $name): void
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new AssetException('Asset stack name is invalid.');
        }
    }
}
