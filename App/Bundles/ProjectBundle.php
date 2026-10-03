<?php

declare(strict_types=1);

namespace App\Bundles;

use App\Activation\ActivationException;
use App\Activation\ActivationStore;
use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use JsonException;
use Throwable;
use WeakMap;

/**
 * Portable application source export and deliberate import. The framed format
 * contains a bounded JSON inventory followed by ordered raw file bytes. It has
 * no archive metadata capable of introducing links or executable instructions.
 * Imported PHP is data until a separately authorized application boot.
 */
final class ProjectBundle
{
    public const SOURCE_ROOTS = [
        'Project', 'Config', 'Database/Migrations', 'Database/Seeders',
        'Database/Factories', 'Assets', 'public/assets', 'composer.json', 'composer.lock',
    ];

    private const MAGIC = "SQUEHUB-BUNDLE/1\n";

    private string $sourceRoot;

    /** @var WeakMap<ChangePlan,array{archive:string,archive_hash:string,target:string,target_existed:bool,manifest:string,project:string}> */
    private WeakMap $planned;

    public function __construct(string $sourceRoot)
    {
        if (is_link($sourceRoot) || !is_dir($sourceRoot)) {
            throw new BundleException('Bundle source must be a physical application directory.');
        }
        $this->sourceRoot = BundlePath::existingInput($sourceRoot);
        $this->planned = new WeakMap();
    }

    /**
     * Export source and activation metadata, never live records or .env. The
     * destination appears only after a complete archive has been verified.
     */
    public function export(string $destination): BundleManifest
    {
        $destination = $this->destination($destination);
        [$sources, $directories, $roots] = $this->sourceInventory();
        $activation = $this->activationState();
        if (!isset($sources['Project/Activation.json'])
            && ($activation['packages'] !== [] || $activation['kits'] !== [])) {
            $sources['Project/Activation.json'] = ['bytes' => self::activationJson($activation)];
        }
        ksort($sources, SORT_STRING);
        $files = [];
        $total = 0;
        foreach ($sources as $path => $source) {
            $bytes = $source['bytes'] ?? null;
            if (is_string($bytes)) {
                $size = strlen($bytes);
                $hash = hash('sha256', $bytes);
            } else {
                BundlePath::requirePhysical($source['file']);
                $size = @filesize($source['file']);
                $hash = @hash_file('sha256', $source['file']);
            }
            if (!is_int($size) || !is_string($hash) || $size > BundleManifest::MAX_FILE_BYTES) {
                throw new BundleException('Bundle source file is unavailable or exceeds the limit.');
            }
            $total += $size;
            if ($total > BundleManifest::MAX_TOTAL_BYTES || count($files) >= BundleManifest::MAX_FILES) {
                throw new BundleException('Bundle source exceeds the resource limit.');
            }
            $files[] = ['path' => $path, 'size' => $size, 'sha256' => $hash];
        }
        $composer = $this->composerMetadata($sources);
        $summary = ['packages' => [], 'kits' => []];
        foreach (['packages', 'kits'] as $kind) {
            foreach ($activation[$kind] as $name => $record) {
                $summary[$kind][] = ['name' => $name, 'enabled' => $record['enabled']];
            }
        }
        $manifest = BundleManifest::fromArray([
            'format' => 1,
            'framework' => 'squehub-v2',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'project' => $composer['name'],
            'php' => $composer['require']['php'],
            'composer_sha256' => $this->fileHash($files, 'composer.json'),
            'source_roots' => $roots,
            'activation' => $summary,
            'directories' => array_keys($directories),
            'files' => $files,
        ]);
        $this->writeArchive($destination, $manifest, $sources);
        return $manifest;
    }

    /** Read the entire framed payload and verify every checksum without extraction. */
    public function inspect(string $archive): BundleManifest
    {
        [$stream, $manifest] = $this->openArchive($archive);
        $composer = null;
        $activation = null;
        try {
            foreach ($manifest->files() as $file) {
                if ($file['path'] === 'composer.json') {
                    $raw = self::readExact($stream, $file['size']);
                    if (!hash_equals($file['sha256'], hash('sha256', $raw))) {
                        throw new BundleException('Bundle payload checksum does not match.');
                    }
                    $composer = self::decodeComposer($raw);
                } elseif ($file['path'] === 'Project/Activation.json') {
                    $raw = self::readExact($stream, $file['size']);
                    if (!hash_equals($file['sha256'], hash('sha256', $raw))) {
                        throw new BundleException('Bundle payload checksum does not match.');
                    }
                    $activation = self::decodeActivationSummary($raw);
                } else {
                    self::readBytes($stream, $file['size'], null, $file['sha256']);
                }
            }
            if (fgetc($stream) !== false || !feof($stream)) {
                throw new BundleException('Bundle has unexpected trailing data.');
            }
            if ($composer === null || $composer['name'] !== $manifest->project()
                || $composer['require']['php'] !== $manifest->phpConstraint()) {
                throw new BundleException('Bundle Composer identity does not match its manifest.');
            }
            if (($activation ?? ['packages' => [], 'kits' => []])
                !== $manifest->toArray()['activation']) {
                throw new BundleException('Bundle activation summary does not match its payload.');
            }
            return $manifest;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Review an empty or existing SqueHub destination. Identical files are
     * omitted; changed files carry the exact before-hash. Owned files changed
     * since their registry snapshot and shared ownership are blocking conflicts.
     */
    public function planImport(string $archive, string $targetRoot): ChangePlan
    {
        $manifest = $this->inspect($archive);
        $archivePath = self::archivePath($archive);
        $archiveHash = @hash_file('sha256', $archivePath);
        if (!is_string($archiveHash)) { throw new BundleException('Bundle cannot be fingerprinted.'); }
        [$target, $existed] = self::targetRoot($targetRoot);
        self::requireApplicationTarget($target, $existed, $manifest->project());
        $ownership = $existed ? self::ownership($target) : [];
        $owner = new ContributionOwner('application', 'Project');
        $actions = [];
        $preconditions = [];
        $conflicts = [];
        foreach ($manifest->directories() as $directory) {
            try {
                $path = BundlePath::target($target, $directory);
                if (file_exists($path)) {
                    if (!is_dir($path)) { $conflicts[] = 'A file blocks directory ' . $directory . '.'; }
                    continue;
                }
                $actions[] = new ChangeAction('create', $directory, $owner, null, null,
                    'low', 'Create project source directory.', 'directory');
                $preconditions[$directory] = null;
            } catch (BundleException) {
                $conflicts[] = 'Unsafe or case-conflicting directory ' . $directory . '.';
            }
        }
        foreach ($manifest->files() as $file) {
            $relative = $file['path'];
            try {
                $path = BundlePath::target($target, $relative);
                if (file_exists($path) && !is_file($path)) {
                    $conflicts[] = 'A directory blocks file ' . $relative . '.';
                    continue;
                }
                $before = file_exists($path) ? @hash_file('sha256', $path) : null;
                if ($before === false) { throw new BundleException('Bundle target cannot be fingerprinted.'); }
                if ($before === $file['sha256']) {
                    // A no-op is still a reviewed target state. Reject drift
                    // after preview instead of silently accepting new bytes.
                    $preconditions[$relative] = $before;
                    continue;
                }
                $claims = $ownership[$relative] ?? [];
                if (count($claims) > 1) {
                    $conflicts[] = 'Shared ownership blocks replacement of ' . $relative . '.';
                    continue;
                }
                if ($claims !== [] && $before !== $claims[0]) {
                    $conflicts[] = 'Modified owned file blocks replacement of ' . $relative . '.';
                    continue;
                }
                $kind = $before === null ? 'create' : 'modify';
                $actions[] = new ChangeAction($kind, $relative, $owner, $before,
                    $file['sha256'], $kind === 'create' ? 'low' : 'review',
                    $kind === 'create' ? 'Import project source file.' : 'Replace reviewed project source file.',
                    self::fileCategory($relative));
                $preconditions[$relative] = $before;
            } catch (BundleException) {
                $conflicts[] = 'Unsafe or case-conflicting file ' . $relative . '.';
            }
        }
        $warnings = ['Source code remains untrusted until reviewed; import does not run Composer, migrations, or Seeders.'];
        if ($manifest->toArray()['activation']['packages'] !== []
            || $manifest->toArray()['activation']['kits'] !== []) {
            $warnings[] = 'Imported activation state may enable Package code on a later application boot.';
        }
        $plan = new ChangePlan('bundle:import', 'Project', $owner, $actions,
            $warnings, array_values(array_unique($conflicts)), $preconditions,
            new ChangePlanMetadata('bundle', $archiveHash,
                ['untrusted_source', 'secrets_excluded'],
                ['framework_version', 'filesystem_case'],
                ['bundle_checksum', 'file_checksum', 'ownership', 'path_presence']));
        $this->planned[$plan] = [
            'archive' => $archivePath, 'archive_hash' => $archiveHash,
            'target' => $target, 'target_existed' => $existed,
            'manifest' => $manifest->fingerprint(),
            'project' => $manifest->project(),
        ];
        return $plan;
    }

    /**
     * Apply only a plan created by this instance. The archive and all reviewed
     * target fingerprints are checked again before writes; each published file
     * is verified. Multiple files are not one transaction, so a later failure
     * carries an honest partial ChangeResult instead of claiming rollback.
     */
    public function apply(ChangePlan $plan): ChangeResult
    {
        $input = $this->planned[$plan] ?? null;
        if ($input === null || $plan->operation !== 'bundle:import' || $plan->hasConflicts()) {
            throw new BundleException('Bundle plan is unavailable or has unresolved conflicts.');
        }
        $manifest = $this->inspect($input['archive']);
        if ($manifest->fingerprint() !== $input['manifest']
            || @hash_file('sha256', $input['archive']) !== $input['archive_hash']) {
            throw new BundleException('Bundle changed after review.');
        }
        $this->assertCurrent($plan, $input);
        $staged = $this->stagePayload($input['archive'], $manifest);
        $applied = [];
        $createdRoot = false;
        try {
            if (@hash_file('sha256', $input['archive']) !== $input['archive_hash']) {
                throw new BundleException('Bundle changed while being staged.');
            }
            $this->assertCurrent($plan, $input);
            if (!$input['target_existed']) {
                if (!@mkdir($input['target'], 0775) || !is_dir($input['target'])) {
                    throw new BundleException('Bundle target directory could not be created.');
                }
                $createdRoot = true;
            }
            foreach ($plan->actions as $action) {
                if (!$action instanceof ChangeAction) {
                    throw new BundleException('Bundle plan action is invalid.');
                }
                $path = BundlePath::target($input['target'], $action->subject);
                if ($action->after === null) {
                    if (file_exists($path) || is_link($path)
                        || !@mkdir($path, 0775) || !is_dir($path)) {
                        throw new BundleException('Bundle directory changed or could not be created.');
                    }
                } else {
                    $actual = file_exists($path) ? @hash_file('sha256', $path) : null;
                    if ($actual !== $action->before) {
                        throw new BundleException('Bundle target changed after review.');
                    }
                    $source = $staged[$action->subject] ?? null;
                    if (!is_string($source) || @hash_file('sha256', $source) !== $action->after) {
                        throw new BundleException('Bundle staging changed before publication.');
                    }
                    $temporary = @tempnam(dirname($path), '.SqueHub-Bundle-');
                    if ($temporary === false) {
                        throw new BundleException('Bundle file could not be staged for publication.');
                    }
                    try {
                        if (!@copy($source, $temporary)
                            || @hash_file('sha256', $temporary) !== $action->after) {
                            throw new BundleException('Bundle file could not be staged for publication.');
                        }
                        if ($action->before === null) {
                            if (!@link($temporary, $path)) {
                                throw new BundleException('Bundle target appeared during publication.');
                            }
                        } elseif (!@rename($temporary, $path)) {
                            throw new BundleException('Bundle target could not be replaced.');
                        }
                    } finally {
                        if (is_file($temporary)) { @unlink($temporary); }
                    }
                    if (@hash_file('sha256', $path) !== $action->after) {
                        throw new BundleException('Bundle target could not be verified.');
                    }
                }
                $applied[] = $action;
            }
            return new ChangeResult($plan, $applied, null, [], true);
        } catch (Throwable $exception) {
            $failed = $plan->actions[count($applied)] ?? null;
            if ($createdRoot && $applied === []) { @rmdir($input['target']); }
            $result = new ChangeResult($plan, $applied, $failed,
                array_slice($plan->actions, count($applied)), false);
            throw new ChangeApplyException($result, $exception);
        } finally {
            foreach ($staged as $file) { if (is_file($file)) { @unlink($file); } }
        }
    }

    /** @return array{array<string,array{file?:string,bytes?:string}>,array<string,true>,list<string>} */
    private function sourceInventory(): array
    {
        $sources = [];
        $directories = [];
        $roots = [];
        foreach (self::SOURCE_ROOTS as $root) {
            $path = BundlePath::target($this->sourceRoot, $root);
            if (!file_exists($path) && !is_link($path)) {
                if ($root === 'composer.json') { throw new BundleException('Bundle source needs composer.json.'); }
                continue;
            }
            BundlePath::requirePhysical($path);
            if (is_dir($path)) {
                $roots[] = $root;
                $parts = explode('/', $root);
                $prefix = '';
                foreach ($parts as $part) {
                    $prefix = ltrim($prefix . '/' . $part, '/');
                    $directories[$prefix] = true;
                }
                $this->walkSource($root, $sources, $directories);
            } elseif (is_file($path)) {
                $roots[] = $root;
                $sources[$root] = ['file' => $path];
            } else {
                throw new BundleException('Bundle source contains an unsupported entry.');
            }
        }
        if (!in_array('Project', $roots, true)) {
            throw new BundleException('Bundle source needs a physical Project directory.');
        }
        ksort($directories, SORT_STRING);
        return [$sources, $directories, $roots];
    }

    /** @param array<string,array{file?:string,bytes?:string}> $sources @param array<string,true> $directories */
    private function walkSource(string $relative, array &$sources, array &$directories): void
    {
        $path = $this->sourceRoot . '/' . $relative;
        BundlePath::requirePhysical($path);
        $entries = @scandir($path);
        if ($entries === false) { throw new BundleException('Bundle source directory cannot be read.'); }
        $folded = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $child = $relative . '/' . $entry;
            BundlePath::requireRelative($child);
            $key = BundlePath::collisionKey($entry);
            if (isset($folded[$key])) {
                throw new BundleException('Bundle source contains case-colliding names.');
            }
            $folded[$key] = true;
            if (BundlePath::excluded($child)) { continue; }
            $full = $this->sourceRoot . '/' . $child;
            BundlePath::requirePhysical($full);
            if (is_dir($full)) {
                $directories[$child] = true;
                if (count($directories) > BundleManifest::MAX_FILES * 2) {
                    throw new BundleException('Bundle source has too many directories.');
                }
                $this->walkSource($child, $sources, $directories);
            } elseif (is_file($full)) {
                $sources[$child] = ['file' => $full];
                if (count($sources) > BundleManifest::MAX_FILES) {
                    throw new BundleException('Bundle source has too many files.');
                }
            } else {
                throw new BundleException('Bundle source contains an unsupported entry.');
            }
        }
    }

    /** @return array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>} */
    private function activationState(): array
    {
        try {
            return (new ActivationStore($this->sourceRoot))->read();
        } catch (ActivationException $exception) {
            throw new BundleException('Bundle activation metadata is invalid.', 0, $exception);
        }
    }

    /** @param array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>} $state */
    private static function activationJson(array $state): string
    {
        foreach ($state['packages'] as &$record) {
            $record['files'] = (object) ($record['files'] ?? []);
            if (isset($record['contribution_snapshot'])) {
                $record['contribution_snapshot'] = (object) $record['contribution_snapshot'];
            }
        }
        unset($record);
        foreach ($state['kits'] as &$record) {
            $record['definition'] = (object) ($record['definition'] ?? []);
            $record['published'] = (object) ($record['published'] ?? []);
        }
        unset($record);
        try {
            return json_encode(['format' => 1, 'packages' => (object) $state['packages'],
                'kits' => (object) $state['kits']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new BundleException('Bundle activation metadata cannot be encoded.', 0, $exception);
        }
    }

    /**
     * The manifest's enabled-state summary is a security-relevant preview.
     * Verify it against the bounded registry bytes, including a missing file,
     * before any import plan can be shown or approved. Reading JSON here does
     * not instantiate Package or Kit code or write activation state.
     *
     * @return array{packages:list<array{name:string,enabled:bool}>,kits:list<array{name:string,enabled:bool}>}
     */
    private static function decodeActivationSummary(string $raw): array
    {
        try {
            $state = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BundleException('Bundle activation metadata is invalid.', 0, $exception);
        }
        if (!$state instanceof \stdClass || ($state->format ?? null) !== 1
            || !(($state->packages ?? null) instanceof \stdClass)
            || !(($state->kits ?? null) instanceof \stdClass)
            || count(get_object_vars($state)) !== 3) {
            throw new BundleException('Bundle activation metadata is invalid.');
        }
        $summary = ['packages' => [], 'kits' => []];
        foreach (['packages', 'kits'] as $kind) {
            $records = get_object_vars($state->{$kind});
            ksort($records, SORT_STRING);
            foreach ($records as $name => $record) {
                if (!is_string($name) || strlen($name) > 128
                    || preg_match('/\A[A-Z][A-Za-z0-9_]*\z/D', $name) !== 1
                    || !$record instanceof \stdClass || !is_bool($record->enabled ?? null)) {
                    throw new BundleException('Bundle activation metadata is invalid.');
                }
                $summary[$kind][] = ['name' => $name, 'enabled' => $record->enabled];
            }
        }
        return $summary;
    }

    /** @param array<string,array{file?:string,bytes?:string}> $sources @return array<string,mixed> */
    private function composerMetadata(array $sources): array
    {
        $path = $sources['composer.json']['file'] ?? null;
        if (!is_string($path)) { throw new BundleException('Bundle source needs composer.json.'); }
        $size = @filesize($path);
        if (!is_int($size) || $size > 1048576 || $size === 0) {
            throw new BundleException('Bundle Composer metadata is unavailable or too large.');
        }
        $raw = @file_get_contents($path);
        return self::decodeComposer($raw);
    }

    /** @return array<string,mixed> */
    private static function decodeComposer(mixed $raw): array
    {
        try {
            $data = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new BundleException('Bundle Composer metadata is invalid.', 0, $exception);
        }
        if (!is_array($data) || !is_string($data['name'] ?? null)
            || !is_string($data['require']['php'] ?? null)) {
            throw new BundleException('Bundle Composer identity or PHP requirement is missing.');
        }
        return $data;
    }

    /** @param list<array{path:string,size:int,sha256:string}> $files */
    private function fileHash(array $files, string $relative): string
    {
        foreach ($files as $file) {
            if ($file['path'] === $relative) { return $file['sha256']; }
        }
        throw new BundleException('Bundle required source file is missing.');
    }

    private function destination(string $destination): string
    {
        if ($destination === '' || is_link($destination) || file_exists($destination)) {
            throw new BundleException('Bundle destination already exists or is unsafe.');
        }
        $name = basename(str_replace('\\', '/', $destination));
        BundlePath::requireRelative($name);
        if (is_link(dirname($destination))) {
            throw new BundleException('Bundle destination parent is unavailable or linked.');
        }
        $parent = BundlePath::existingInput(dirname($destination));
        $destination = $parent . '/' . $name;
        foreach (self::SOURCE_ROOTS as $root) {
            $source = $this->sourceRoot . '/' . $root;
            if (self::sameOrWithin($destination, $source)) {
                throw new BundleException('Bundle destination cannot be inside exported source.');
            }
        }
        return $destination;
    }

    /**
     * Stage next to the destination and publish by hard link only after every
     * source checksum still matches. This avoids exposing a partial archive and
     * prevents replacing an archive that another process created meanwhile.
     *
     * @param array<string,array{file?:string,bytes?:string}> $sources
     */
    private function writeArchive(string $destination, BundleManifest $manifest, array $sources): void
    {
        try {
            $json = json_encode($manifest->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BundleException('Bundle manifest cannot be encoded.', 0, $exception);
        }
        if (!is_string($json) || strlen($json) > BundleManifest::MAX_MANIFEST_BYTES) {
            throw new BundleException('Bundle manifest exceeds the size limit.');
        }
        $temporary = @tempnam(dirname($destination), '.SqueHub-Bundle-');
        if ($temporary === false) { throw new BundleException('Bundle destination cannot be staged.'); }
        $stream = @fopen($temporary, 'wb');
        if ($stream === false) { @unlink($temporary); throw new BundleException('Bundle destination cannot be opened.'); }
        try {
            try {
                self::writeBytes($stream, self::MAGIC . sprintf('%08x', strlen($json)) . "\n" . $json);
                foreach ($manifest->files() as $file) {
                    $source = $sources[$file['path']];
                    $bytes = $source['bytes'] ?? null;
                    if (is_string($bytes)) {
                        self::writeBytes($stream, $bytes);
                        continue;
                    }
                    BundlePath::requirePhysical($source['file']);
                    $input = @fopen($source['file'], 'rb');
                    if ($input === false) { throw new BundleException('Bundle source file cannot be opened.'); }
                    try {
                        self::copyExpected($input, $stream, $file['size'], $file['sha256']);
                    } finally {
                        fclose($input);
                    }
                }
                if (!@fflush($stream)) { throw new BundleException('Bundle destination could not be flushed.'); }
            } finally {
                fclose($stream);
            }
            if (!@link($temporary, $destination)) {
                throw new BundleException('Bundle destination appeared or could not be published.');
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    /** @return array{resource,BundleManifest} */
    private function openArchive(string $archive): array
    {
        $path = self::archivePath($archive);
        $size = @filesize($path);
        if (!is_int($size) || $size > BundleManifest::MAX_TOTAL_BYTES
            + BundleManifest::MAX_MANIFEST_BYTES + 64) {
            throw new BundleException('Bundle archive is unavailable or too large.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new BundleException('Bundle archive cannot be read.'); }
        try {
            $header = self::readExact($stream, strlen(self::MAGIC) + 9);
            if (!str_starts_with($header, self::MAGIC)
                || preg_match('/\A[0-9a-f]{8}\n\z/D', substr($header, strlen(self::MAGIC))) !== 1) {
                throw new BundleException('Bundle format is invalid or unsupported.');
            }
            $length = hexdec(substr($header, strlen(self::MAGIC), 8));
            if ($length < 1 || $length > BundleManifest::MAX_MANIFEST_BYTES) {
                throw new BundleException('Bundle manifest exceeds the size limit.');
            }
            $json = self::readExact($stream, $length);
            try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException $exception) {
                throw new BundleException('Bundle manifest is invalid JSON.', 0, $exception);
            }
            if (!is_array($data)) { throw new BundleException('Bundle manifest must be an object.'); }
            return [$stream, BundleManifest::fromArray($data)];
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private static function archivePath(string $archive): string
    {
        if ($archive === '' || is_link($archive) || !is_file($archive)) {
            throw new BundleException('Bundle archive is unavailable or linked.');
        }
        return BundlePath::existingInput($archive);
    }

    /** @return array{string,bool} */
    private static function targetRoot(string $targetRoot): array
    {
        if ($targetRoot === '' || is_link($targetRoot)) {
            throw new BundleException('Bundle target root is unavailable or linked.');
        }
        if (is_dir($targetRoot)) {
            return [BundlePath::existingInput($targetRoot), true];
        }
        if (file_exists($targetRoot)) {
            throw new BundleException('Bundle target root is not a directory.');
        }
        $name = basename(str_replace('\\', '/', $targetRoot));
        BundlePath::requireRelative($name);
        if (is_link(dirname($targetRoot))) {
            throw new BundleException('Bundle target parent is unavailable or linked.');
        }
        $parent = BundlePath::existingInput(dirname($targetRoot));
        return [$parent . '/' . $name, false];
    }

    private static function requireApplicationTarget(string $target, bool $existed, string $project): void
    {
        if (!$existed) { return; }
        $entries = @scandir($target);
        if ($entries === false) { throw new BundleException('Bundle target cannot be inspected.'); }
        $entries = array_values(array_diff($entries, ['.', '..']));
        if ($entries === []) { return; }
        if (!is_dir($target . '/Project') || is_link($target . '/Project')
            || !is_file($target . '/composer.json') || is_link($target . '/composer.json')) {
            throw new BundleException('Existing bundle target is not an identifiable SqueHub project.');
        }
        BundlePath::requirePhysical($target . '/Project');
        BundlePath::requirePhysical($target . '/composer.json');
        $raw = @file_get_contents($target . '/composer.json');
        try { $composer = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null; }
        catch (JsonException) { $composer = null; }
        if (!is_array($composer) || !is_string($composer['name'] ?? null)
            || !is_string($composer['require']['php'] ?? null)) {
            throw new BundleException('Existing bundle target Composer identity is invalid.');
        }
        if ($composer['name'] !== $project) {
            throw new BundleException('Existing bundle target is a different Composer project.');
        }
    }

    /** @return array<string,list<string>> */
    private static function ownership(string $target): array
    {
        if (!is_dir($target . '/Project')) { return []; }
        try { $state = (new ActivationStore($target))->read(); }
        catch (ActivationException $exception) {
            throw new BundleException('Target activation metadata cannot be inspected.', 0, $exception);
        }
        $claims = [];
        foreach ($state['packages'] as $name => $record) {
            foreach ($record['files'] ?? [] as $relative => $hash) {
                $claims['Project/Packages/' . $name . '/' . $relative][] = $hash;
            }
        }
        foreach ($state['kits'] as $record) {
            foreach ($record['published'] ?? [] as $relative => $item) {
                $claims[$relative][] = $item['hash'];
            }
        }
        return $claims;
    }

    /** @param array{archive:string,archive_hash:string,target:string,target_existed:bool,manifest:string,project:string} $input */
    private function assertCurrent(ChangePlan $plan, array $input): void
    {
        [$target, $existed] = self::targetRoot($input['target']);
        if ($target !== $input['target'] || $existed !== $input['target_existed']) {
            throw new BundleException('Bundle target root changed after review.');
        }
        self::requireApplicationTarget($target, $existed, $input['project']);
        foreach ($plan->preconditions as $relative => $before) {
            $path = BundlePath::target($target, $relative);
            if ($before === null) {
                if (file_exists($path) || is_link($path)) {
                    throw new BundleException('Bundle target appeared after review.');
                }
            } elseif (!is_file($path) || @hash_file('sha256', $path) !== $before) {
                throw new BundleException('Bundle target changed after review.');
            }
        }
        $ownership = $existed ? self::ownership($target) : [];
        foreach ($plan->actions as $action) {
            if (!$action instanceof ChangeAction || $action->after === null) { continue; }
            $claims = $ownership[$action->subject] ?? [];
            if (count($claims) > 1 || ($claims !== [] && $claims[0] !== $action->before)) {
                throw new BundleException('Bundle target ownership changed after review.');
            }
        }
    }

    /** @return array<string,string> Application-relative path => private staged file. */
    private function stagePayload(string $archive, BundleManifest $manifest): array
    {
        [$stream, $read] = $this->openArchive($archive);
        $staged = [];
        try {
            if ($read->fingerprint() !== $manifest->fingerprint()) {
                throw new BundleException('Bundle manifest changed during staging.');
            }
            foreach ($manifest->files() as $file) {
                $temporary = @tempnam(sys_get_temp_dir(), 'SqueHub-Bundle-');
                if ($temporary === false) { throw new BundleException('Bundle file cannot be staged.'); }
                $staged[$file['path']] = $temporary;
                $output = @fopen($temporary, 'wb');
                if ($output === false) { throw new BundleException('Bundle file cannot be staged.'); }
                try { self::readBytes($stream, $file['size'], $output, $file['sha256']); }
                finally { fclose($output); }
            }
            if (fgetc($stream) !== false || !feof($stream)) {
                throw new BundleException('Bundle has unexpected trailing data.');
            }
            return $staged;
        } catch (Throwable $exception) {
            foreach ($staged as $file) { if (is_file($file)) { @unlink($file); } }
            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Stream bounded bytes through a digest. The same primitive verifies an
     * archive during inspection and stages a verified copy during import.
     *
     * @param resource $input
     * @param resource|null $output
     */
    private static function readBytes($input, int $length, $output, string $expected): void
    {
        $hash = hash_init('sha256');
        while ($length > 0) {
            $chunk = fread($input, min(65536, $length));
            if (!is_string($chunk) || $chunk === '') {
                throw new BundleException('Bundle payload is truncated.');
            }
            hash_update($hash, $chunk);
            if ($output !== null) { self::writeBytes($output, $chunk); }
            $length -= strlen($chunk);
        }
        if (!hash_equals($expected, hash_final($hash))) {
            throw new BundleException('Bundle payload checksum does not match.');
        }
    }

    /** @param resource $input @param resource $output */
    private static function copyExpected($input, $output, int $length, string $expected): void
    {
        self::readBytes($input, $length, $output, $expected);
        if (fgetc($input) !== false || !feof($input)) {
            throw new BundleException('Bundle source changed while exporting.');
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($stream, $length - strlen($bytes));
            if (!is_string($part) || $part === '') {
                throw new BundleException('Bundle archive is truncated.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    /** @param resource $stream */
    private static function writeBytes($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new BundleException('Bundle archive could not be written.');
            }
            $offset += $written;
        }
    }

    private static function sameOrWithin(string $path, string $root): bool
    {
        $root = rtrim($root, '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($path, $root) === 0
                || strncasecmp($path, $root . '/', strlen($root) + 1) === 0;
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function fileCategory(string $path): string
    {
        if ($path === 'Project/Activation.json') { return 'activation'; }
        if (str_starts_with($path, 'Project/Packages/')) { return 'package'; }
        if (str_starts_with($path, 'Project/Kits/')) { return 'kit'; }
        if (str_starts_with($path, 'Config/')) { return 'configuration'; }
        if (str_starts_with($path, 'Database/Migrations/')) { return 'migration'; }
        if (str_starts_with($path, 'Assets/') || str_starts_with($path, 'public/assets/')) {
            return 'asset';
        }
        if ($path === 'composer.json' || $path === 'composer.lock') { return 'composer'; }
        return 'file';
    }
}
