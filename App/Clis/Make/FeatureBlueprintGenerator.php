<?php

declare(strict_types=1);

namespace App\Clis\Make;

use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Database\Identifier;
use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use WeakMap;

/**
 * Plans one SqueHub Feature Blueprint using the guarded Generator publisher.
 * The blueprint creates only a read route and explicit structural hooks. It
 * never inspects a database or runs application code while planning.
 */
final class FeatureBlueprintGenerator
{
    private Generator $generator;
    private string $root;

    /** @var WeakMap<ChangePlan, array{route:string,name:string}> */
    private WeakMap $reviewedRoutes;

    public function __construct(string $basePath, ?DateTimeImmutable $clock = null)
    {
        $resolved = realpath($basePath);
        if ($resolved === false || !is_dir($resolved)) {
            throw new GeneratorException('Application root was not found.');
        }
        $this->root = $resolved;
        $this->generator = new Generator($resolved, $clock);
        $this->reviewedRoutes = new WeakMap();
    }

    public function plan(
        string $name,
        ?string $package = null,
        ?string $table = null,
        ?string $route = null
    ): ChangePlan {
        $class = $this->generator->featureClassName($name);
        $target = $package === null ? $class : $package . '/' . $class;
        if (strlen($target) > 160) {
            throw new GeneratorException('Feature and Package names are too long together.');
        }
        $base = $package === null ? 'Project' : 'Project/Packages/' . $package;
        $namespace = str_replace('/', '\\', $base);
        $stem = self::snake($class);
        $packageStem = $package === null ? null : self::snake($package);
        $table ??= $packageStem === null ? $stem : $packageStem . '_' . $stem;
        self::assertTable($table);
        $route ??= $packageStem === null ? '/' . $stem : '/' . $packageStem . '/' . $stem;
        self::assertRoute($route);
        $routeName = str_replace(['/', '-'], ['.', '_'], trim($route, '/')) . '.index';

        $migration = $this->generator->migrationArtifact('create_' . $table . '_table');
        $routeSource = $base . '/Routes/Features/' . $class . '.php';
        $testClass = ($package ?? '') . $class . 'FeatureTest';
        $testSource = 'Tests/Integration/' . $testClass . '.php';
        $fixtureRouteSource = 'Project/Routes/Features/' . $testClass . '.php';
        $args = [$namespace, $class, $table, $route, $routeName, $routeSource,
            $migration['path'], $testClass, $fixtureRouteSource];
        $files = [
            $base . '/Models/' . $class . '.php' => GeneratorTemplates::feature('model', ...$args),
            $base . '/Controllers/' . $class . 'Controller.php' => GeneratorTemplates::feature('controller', ...$args),
            $base . '/Api/Resources/' . $class . 'Resource.php' => GeneratorTemplates::feature('resource', ...$args),
            $base . '/Validation/' . $class . 'Validation.php' => GeneratorTemplates::feature('validation', ...$args),
            $routeSource => GeneratorTemplates::feature('route', ...$args),
            $migration['path'] => $migration['contents'],
            $testSource => GeneratorTemplates::feature('test', ...$args),
        ];
        $conflicts = $this->knownRouteConflicts($route, $routeName);
        $warnings = [
            'Migration execution: NOT INCLUDED.',
            'Dynamic and legacy route declarations require runtime review.',
        ];
        if ($package !== null) {
            $warnings[] = 'Package migration and test use runnable application-level directories; Package state is unchanged.';
        }
        $plan = $this->generator->planComposite($target,
            $files, $package, $migration['class'], $warnings, $conflicts);
        $this->reviewedRoutes[$plan] = ['route' => $route, 'name' => $routeName];
        return $plan;
    }

    public function apply(ChangePlan $plan): ChangeResult
    {
        $reviewed = $this->reviewedRoutes[$plan] ?? null;
        if ($reviewed === null) {
            throw new GeneratorException('Feature Blueprint plan is invalid.');
        }
        if ($this->knownRouteConflicts($reviewed['route'], $reviewed['name']) !== []) {
            throw new GeneratorException('Route declarations changed after Feature Blueprint review.');
        }
        return $this->generator->applyComposite($plan);
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /** Table names are migration identifiers, not arbitrary SQL or paths. */
    private static function assertTable(string $table): void
    {
        if (strlen($table) > 64
            || preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D', $table) !== 1) {
            throw new GeneratorException('Feature table must be a lowercase snake_case identifier of at most 64 bytes.');
        }
        try {
            Identifier::simple($table);
        } catch (Throwable $exception) {
            throw new GeneratorException('Feature table is not a valid database identifier.', 0, $exception);
        }
    }

    /** A literal route is safe to embed as source and needs no variable dispatch. */
    private static function assertRoute(string $route): void
    {
        if (strlen($route) > 120
            || preg_match('~\A/[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)*\z~D', $route) !== 1) {
            throw new GeneratorException('Feature route must be a bounded literal lowercase path.');
        }
    }

    /**
     * Detect only conflicts proven from literal path-first route declarations.
     * Arbitrary PHP route files are never executed in preview; dynamic and
     * legacy registrations remain explicitly outside this static proof.
     *
     * @return list<string>
     */
    private function knownRouteConflicts(string $route, string $routeName): array
    {
        $directories = [];
        foreach (['Project', 'project', 'App', 'app'] as $owner) {
            foreach (['Routes', 'routes'] as $routes) {
                $directories[] = $this->root . '/' . $owner . '/' . $routes;
            }
        }
        $packages = $this->root . '/Project/Packages';
        if (is_dir($packages) && !is_link($packages)) {
            foreach (new \DirectoryIterator($packages) as $candidate) {
                if ($candidate->isDot() || !$candidate->isDir() || $candidate->isLink()) { continue; }
                $directories[] = $candidate->getPathname() . '/Routes';
                $directories[] = $candidate->getPathname() . '/routes';
            }
        }
        $conflicts = [];
        $filesSeen = 0;
        $bytesSeen = 0;
        $visited = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) { continue; }
            if (is_link($directory) || !self::contained($this->root, $directory)) {
                throw new GeneratorException('Route directory is unsafe.');
            }
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory === false || isset($visited[$resolvedDirectory])) { continue; }
            $visited[$resolvedDirectory] = true;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new GeneratorException('Route source contains a linked entry.');
                }
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
                $size = $file->getSize();
                $bytesSeen += $size;
                if (++$filesSeen > 2000 || $size > 2097152 || $bytesSeen > 33554432
                    || !self::contained($this->root, $file->getPathname())) {
                    throw new GeneratorException('Route source cannot be inspected within safe limits.');
                }
                $source = @file_get_contents($file->getPathname());
                if (!is_string($source)) {
                    throw new GeneratorException('Route source cannot be read.');
                }
                foreach (self::literalRouteChains($source) as [$path, $name, $hasGet]) {
                    if ($hasGet && $path === $route) {
                        $conflicts[] = 'GET route ' . $route . ' is already declared in a literal route file.';
                    }
                    if ($name === $routeName) {
                        $conflicts[] = 'Route name ' . $routeName . ' is already declared in a literal route file.';
                    }
                }
            }
        }
        return array_values(array_unique($conflicts));
    }

    /** @return list<array{string, ?string, bool}> */
    private static function literalRouteChains(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $result = [];
        $count = count($tokens);
        for ($i = 0; $i + 4 < $count; ++$i) {
            if (!self::routeSymbol(self::tokenText($tokens[$i]))
                || self::tokenText($tokens[$i + 1]) !== '::'
                || self::tokenText($tokens[$i + 2]) !== 'path'
                || self::tokenText($tokens[$i + 3]) !== '('
                || !is_array($tokens[$i + 4])
                || $tokens[$i + 4][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $path = self::literal($tokens[$i + 4][1]);
            if ($path === null) { continue; }
            $hasGet = false;
            $name = null;
            for ($j = $i + 5; $j + 2 < $count && self::tokenText($tokens[$j]) !== ';'; ++$j) {
                if (self::tokenText($tokens[$j]) !== '->') { continue; }
                if (self::tokenText($tokens[$j + 1]) === 'get'
                    && self::tokenText($tokens[$j + 2]) === '(') {
                    $hasGet = true;
                }
                if (self::tokenText($tokens[$j + 1]) === 'named'
                    && self::tokenText($tokens[$j + 2]) === '('
                    && is_array($tokens[$j + 3] ?? null)
                    && $tokens[$j + 3][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $name = self::literal($tokens[$j + 3][1]);
                }
            }
            $result[] = [$path, $name, $hasGet];
        }
        return $result;
    }

    private static function tokenText(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    private static function routeSymbol(string $token): bool
    {
        return in_array($token, [
            'Route', 'App\\Plugins\\Route', '\\App\\Plugins\\Route',
            'App\\Routing\\Route', '\\App\\Routing\\Route',
            'App\\Core\\Route', '\\App\\Core\\Route',
        ], true);
    }

    private static function literal(string $token): ?string
    {
        $quote = $token[0] ?? '';
        if (!in_array($quote, ["'", '"'], true) || substr($token, -1) !== $quote
            || str_contains($token, '\\') || str_contains($token, '$')) {
            return null;
        }
        return substr($token, 1, -1);
    }

    private static function contained(string $root, string $path): bool
    {
        $resolved = realpath($path);
        if ($resolved === false) { return false; }
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($resolved, $prefix, strlen($prefix)) === 0
            : strncmp($resolved, $prefix, strlen($prefix)) === 0;
    }
}
