# SqueHub v2 application Storage

An optional S3-compatible drive uses the same `App\Plugins\Storage` API. See [Provider-backed Storage](ProviderStorage.md) for the optional SDK, configuration, stream and directory semantics, and qualification status. Local and Array drives remain available without cloud credentials.

`storage()` resolves the current Application's `StorageManager`. The manager selects its configured default drive and builds each named drive on first use. Register `StorageServiceProvider` in an Application-only or CLI setup; `Bootstrap/App.php` registers it for the standard app. Provider boot does not create directories or open files.

```php
storage()->write('documents/readme.txt', 'Hello from SqueHub');
$contents = storage()->read('documents/readme.txt');
$archive = storage()->drive('archive');
$archive->write('reports/2026.pdf', $contents);
```

`Config/Storage.php` selects `default` and maps names under `drives`. `local` uses a `root`; `null` means the Application's `Storage/Files` directory. A relative root is resolved against the Application base path, never the process working directory. UNC roots are not supported in this release. `array` is an isolated, non-persistent in-memory drive useful for tests. Unknown drive names and unsupported driver types fail clearly. Two Applications do not share array data; two local drives configured to the same physical root deliberately share files.

## API and results

| Method | Result |
| --- | --- |
| `read($path)` | Binary-safe string; missing or directory paths throw `StorageException`. |
| `write($path, $contents)` | Creates parents and replaces the file; `$contents` must be a string. |
| `exists($path)` | Boolean for either a file or directory. |
| `remove($path)` | `true` if a file was removed, `false` if absent; directories throw. |
| `copy($source, $destination, overwrite: false)` | Copies within one drive, creates destination parents, rejects an existing destination by default. |
| `move($source, $destination, overwrite: false)` | Moves within one drive with the same collision rule. |
| `files($directory = '', recursive: false)` | Sorted logical file paths. |
| `directories($directory = '', recursive: false)` | Sorted logical directory paths. |
| `makeDirectory($path)` | Creates parents; an existing directory succeeds. |
| `removeDirectory($path, recursive: false)` | Rejects nonempty directories unless explicitly recursive; never accepts the drive root. |
| `size($path)` | File size in bytes. |
| `modifiedAt($path)` | `DateTimeImmutable` in UTC. |
| `mimeType($path)` | MIME from file contents via `fileinfo`, or `null` if unavailable. |
| `readStream($path)` | Readable stream at offset zero. The caller closes it. |
| `writeStream($path, $stream)` | Reads a readable stream from its current cursor to EOF. The caller retains and closes it. |

```php
$files = storage()->files('documents', recursive: true);
$bytes = storage()->size('documents/readme.txt');
$modified = storage()->modifiedAt('documents/readme.txt');
$mime = storage()->mimeType('documents/readme.txt');

$stream = fopen($localTemporaryFile, 'rb');
try {
    storage()->writeStream('uploads/file.bin', $stream);
} finally {
    fclose($stream);
}
```

Local stream writes copy bounded chunks and replace the destination only after the full copy succeeds. The array driver materializes bytes in memory and is unsuitable for large production files. `readStream()` releases its lock before returning; a caller keeping the handle open does not receive a snapshot guarantee against later replacement. Local `modifiedAt()` uses filesystem timestamp resolution; array timestamps use the model clock. MIME detection is advisory, not sanitization or malware scanning.

## Logical path and containment contract

Use `/` separated relative paths such as `avatars/user-1.jpg`. File operations reject `''`; listings accept it to mean the drive root. Absolute paths, backslashes, empty or `.` or `..` segments, controls, Windows-invalid characters (`< > : " | ? *`), device basenames (`CON`, `NUL`, `COM1` through `COM9`, `LPT1` through `LPT9`, and peers), trailing spaces or dots, and `.squehub` or `.squehub-tmp-` segments are rejected. Unicode names are allowed. Each segment is limited to 255 bytes and the entire logical path to 4096 bytes.

The local driver checks each existing path component with link and resolved-path checks and requires it to stay inside the configured root. Its own `.squehub/locks` namespace is reserved and hidden from listings. Atomic write temporary files use reserved unpredictable names in the destination directory and are hidden. Root deletion is forbidden. Recursive deletion inspects the subtree before removing entries and rejects unexpected links. A configured root itself cannot be a symbolic link. Junction/reparse escapes are checked with resolved paths where PHP exposes them.

Local operations take a drive namespace lock plus deterministic sorted path locks. Writers use a temporary file in the destination directory, flush it, and coordinate replacement with readers, including Windows replacement behavior. `copy()` and `move()` lock both paths in a stable order. `move()` copies safely before removing the source; if source removal fails after destination creation, the caller may need to resolve both copies. These guarantees apply to cooperating SqueHub local driver calls. Portable PHP cannot prevent an untrusted local process from swapping filesystem components between checks and use; choose an application-controlled root. Hashed lock filenames are correlation identifiers, not encryption.

`Storage/Files` belongs to application Storage. `Storage/Logs`, application data `Storage/Cache`, [compiled Views](CompiledViews.md) under `Storage/Views`, and native sessions keep their own formats and lifecycle. Storage does not run stored content, deserialize it, validate uploads, authorize access, sanitize HTML/images/executables, or encrypt bytes. An application decides when a validated `UploadedFile` is persisted. Removing a stored file does not trigger database foreign keys or any storage event. Storage file metadata must not be treated as authorization truth.

An optional S3-compatible object drive is available; see [Provider-backed Storage](ProviderStorage.md) for its object/directory and non-atomic move contracts. Dedicated R2, GCS, Azure, FTP/SFTP drivers, public or temporary URLs, visibility/ACL APIs, cross-drive copy/move, encryption at rest, virus scanning, media library, file versioning, and automatic Storage events remain unavailable.

Request diagnostics aggregate attempted reads, writes, removals, copies, moves, listings and checks, plus failures, successful read/write bytes, and driver time. They retain no drive names, paths, filenames, or contents. Storage exceptions use a short path fingerprint instead of raw logical or physical names. An Application still works without HTTP, Session, Database, Cache, Events, Logger, or Diagnostics. If a caller-owned database transaction is rolled back, Storage operations are unaffected; coordinate such workflows in application code.

## Application-facing Plugins import

Application code may import `App\Plugins\Storage`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
