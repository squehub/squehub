# S3-compatible Storage

SqueHub's existing `Storage` API can select an optional S3-compatible object store. The adapter passed deterministic fake-client tests on Windows and a user-run guarded live test against a disposable MinIO service on native Linux. That result qualifies the tested S3-compatible MinIO path; AWS S3 itself was not used. Local and Array drives remain the base installation defaults.

## Optional dependency and configuration

Install the optional AWS SDK only in applications that select the S3 drive:

```bash
composer require aws/aws-sdk-php:^3
```

The base framework does not require this package. An unselected S3 drive performs no SDK construction or network call. Selecting S3 without the SDK raises a clear Storage configuration exception.

The shipped `Config/Storage.php` defines an inert `s3` drive. Supply a dedicated bucket, region, credentials, optional endpoint, logical prefix, and path-style selection through its documented `STORAGE_S3_*` environment reads. The main keys are `STORAGE_S3_BUCKET`, `STORAGE_S3_REGION`, `STORAGE_S3_ACCESS_KEY`, `STORAGE_S3_SECRET_KEY`, `STORAGE_S3_SESSION_TOKEN`, `STORAGE_S3_ENDPOINT`, `STORAGE_S3_PREFIX`, and `STORAGE_S3_PATH_STYLE`. Use `STORAGE_DRIVE=s3` to make it the default, or select it explicitly:

```php
use App\Plugins\Storage;

Storage::drive('s3')->write('reports/summary.txt', 'ready');
$body = Storage::drive('s3')->read('reports/summary.txt');
```

Storage paths remain SqueHub-relative logical paths. Validation rejects traversal, controls, malformed paths, and keys outside the configured drive prefix before an object-store operation. Credentials are never used as object keys. The prefix isolates an application within a bucket; it is not a permission boundary. Set bucket IAM/policy permissions to the intended prefix as well. Custom endpoints are operator configuration, not request input. Production endpoints should use TLS; HTTP is useful only for a deliberately local test service.

## Object-store behavior

Reads, writes, existence checks, metadata, copies, moves, directory listings, and streams use the ordinary `StorageDrive` methods. Files are objects. Directories are represented by marker objects or inferred from child key prefixes; they are not S3 filesystem directories. Listings are sorted and paginated through the provider's continuation tokens. Missing logical directories fail under the existing Storage listing contract.

`writeStream()` copies from the caller's **current stream position** to a temporary spool with bounded memory before SDK upload. It leaves the caller's stream open and reports the bytes supplied by that cursor. The spool may use local temporary disk for larger streams, so deploy with adequate private temporary storage. `readStream()` returns a readable stream that the caller must close. Avoid reading a very large remote object through `read()` if `readStream()` is appropriate.

Copy is a remote object copy. Move is copy followed by delete; it is not atomic. A failed delete may leave both objects. With `overwrite: false`, the destination check is a preflight HEAD, not an atomic create-if-absent: another writer can create that key before the subsequent copy. Overwrites and concurrent writers have the object store's semantics, not local filesystem locks. Recursive directory removal is bounded and scoped to the selected logical prefix, yet should still be used only on paths the application intends to delete. The framework does not delete an entire bucket or issue a bucket-wide cleanup operation. S3 eventual behavior and provider-specific limits should be qualified on the deployment backend.

Provider exceptions expose safe categories, not credentials or raw SDK exception bodies. Storage diagnostics continue to record aggregate operations, timing, byte counts, and failures without object contents or credentials. Studio and ordinary CLI inspection show configured drive names and driver type only; they do not list remote objects or probe the endpoint.

The SDK bridge bounds each provider request with a 5-second connection timeout and a 30-second total timeout, and disables its implicit retries. An application may explicitly retry an idempotent operation at its own boundary; a Queue job uses the existing Queue retry policy. A multipart upload or copy may involve more than one bounded request, so the total high-level operation can take longer than one request timeout. A failed multipart transfer is aborted only when the SDK identifies the exact owned destination upload; operators should still monitor orphaned multipart uploads on their provider.

## Qualification

The deterministic tests use `Tests/Fixtures/FakeS3ObjectClient.php`, `Tests/Unit/S3StorageDriverTest.php`, and `Tests/Integration/S3StorageIntegrationTest.php`. `Tests/Integration/S3StorageLiveOptInTest.php` skips unless the operator explicitly sets `SQUEHUB_TEST_S3_ENABLED=1` and supplies a disposable bucket, endpoint, region, credentials, and a base test prefix. Each live run adds a cryptographically random subprefix, creates a sibling sentinel, and removes only objects it created; it never empties the bucket. Do not use production credentials or a production bucket for that test. Verify its temporary run prefix is empty afterward.

In the reported native Linux qualification, the optional SDK was installed only in the disposable test copy. MinIO answered on a loopback endpoint, and `S3StorageLiveOptInTest` passed 1 test with 27 assertions against a disposable `squehub-test` bucket. The owned random prefix was empty afterward; the bucket, temporary data, and process were removed, and `SQUEHUB_TEST_S3_*` variables were unset. This is live S3-compatible MinIO evidence, not AWS S3 qualification or proof for an arbitrary production bucket.

The optional AWS SDK bridge uses SqueHub's Storage contract and does not route through Flysystem. The core Composer graph no longer requires Flysystem. See [Storage](Storage.md), [deployment](Deployment.md), and [release readiness](ReleaseReadiness.md).
