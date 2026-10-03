# Install SqueHub v2.0.0

These steps use a complete SqueHub v2.0.0 source tree. Select an exact v2 ref or matching archive and verify its contents before installing dependencies. An unversioned repository checkout or Composer package resolution can select a different generation; the [v1.x guide](https://squehub.com/docs/v1.x) remains available for v1 applications.

## Install Composer

SqueHub uses Composer for PHP dependencies. Install a PHP CLI first, then follow the [official Composer instructions](https://getcomposer.org/download/). On Windows, the [Composer Windows installer](https://getcomposer.org/doc/00-intro.md#installation-windows) sets up the `composer` command; open a new PowerShell window after installation so its updated `PATH` is available. On Linux or macOS, follow the [official Unix/macOS instructions](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos) to download and verify the installer and choose a local `composer.phar` or an executable on your `PATH`. Copy the installer checksum from Composer's live download page rather than from an old guide.

Check that your shell finds both programs:

```bash
php -v
composer --version
```

If Composer was installed locally as `composer.phar`, use `php composer.phar` in place of `composer` in the commands below.

## Obtain the v2 source

Obtain an exact, verified v2 ref from an authorized source repository or a matching complete archive. Keep `composer.lock`, `Docs/`, `Tests/`, and `phpunit.xml.dist` with the application source. For a Git ref, use its actual URL and ref name:

```bash
git clone --branch <verified-v2-ref> --single-branch <v2-source-url-or-path> my-app
cd my-app
git rev-parse HEAD
```

Match the full commit ID to the release or source record you selected. For an archive, verify that it identifies the same release and contains the complete source tree. Use `composer install` with the distributed lock file, not `composer update`, to install its recorded dependencies.

The unversioned commands below do not pin v2.0.0:

```bash
composer create-project squehub/squehub my-app
git clone https://github.com/squehub/squehub.git my-app
```

Likewise, **Code → Download ZIP** on the [public repository](https://github.com/squehub/squehub) downloads its default branch. For a v2 installation, select an explicit v2 ref or Composer version and verify that the downloaded package contains the v2 source. The steps below apply to the verified source checkout or archive.

## Requirements

- PHP 8.2 or newer and Composer.
- The PHP extensions required by Composer and the selected services. Check with `php -m` and `php squehub doctor`.
- A PDO driver for the chosen database: `pdo_sqlite` for SQLite or `pdo_mysql` for MySQL/MariaDB.
- A writable session path for browser sessions and writable runtime directories for the services you enable.
- For Crypt, sodium or OpenSSL. `CRYPT_DRIVER=auto` prefers sodium and uses OpenSSL AES-256-GCM when sodium is unavailable.
- cURL for real outgoing HTTP Client calls. PHP `zip` or an archive extractor for Composer downloads; `backup:dev` specifically requires the PHP `zip` extension.

Redis, SMTP, a Queue worker, and an OS scheduler are optional until the application selects the related capability. See [Deployment](Deployment.md) for service-specific requirements.

## Install dependencies

From the complete v2 source selected above (or its matching archive):

```bash
composer install
composer validate --strict
```

Keep `composer.lock` with that source. Check the selected ref when cloning a repository default branch; it is not pinned to v2.0.0 by the command alone.

## Configure the environment

There are two setup paths. **Option A** is the complete manual path. **Option B** uses the optional [SqueHub Setup](Setup.md) command to inspect and propose configuration changes. Both start from the verified v2 source selected above.

### Option A — Manual setup

Copy the distributed template into your private configuration file.

Windows PowerShell:

```powershell
Copy-Item .example.env .env
```

Linux or macOS:

```bash
cp .example.env .env
```

Generate a private 256-bit key:

```bash
php squehub key:generate
```

The command **prints** a new value; it does not edit `.env`. Paste the complete output into the `APP_KEY` line:

```dotenv
APP_KEY=base64:your-generated-key-here
```

Replace the placeholder with your own value. Do not commit `.env`, send the key to a log, or reuse another application's key. Set `APP_ENV` and `APP_DEBUG` deliberately, then review database, Cache, Session, Queue, Mail, Redis, and Storage settings you plan to use. The template's `APP_KEY` is blank. Comments, key order, and line endings do not count as changed configuration if parsed values still match `.example.env`.

Missing or unchanged `.env` settings trigger the **SqueHub setup required** page on web requests and a safe CLI notice. After changing parsed values, the next browser request sees the update without restarting `php squehub start`. An empty `APP_KEY` can leave Crypt unavailable even if another changed setting clears the setup page.

### Option B — SqueHub Setup

From the v2 project root, run:

```bash
php squehub setup
```

SqueHub Setup inspects the application and presents a reviewable plan for required configuration changes. It preserves existing settings and does not rotate an existing key or run Migrations, Seeders, or Package operations automatically. Review its plan before applying changes, then inspect the Doctor result. See the [Setup guide](Setup.md) for its security and verification boundaries. You can return to the manual steps above at any time.

When SQLite is selected, Setup can plan creation of a missing `Storage/` directory and an empty `Storage/Database.sqlite` file. That relative path resolves from the application root even if the shell starts elsewhere; it is not a migration and creates no application tables. Existing unsafe or unwritable Storage paths fail instead of being replaced.

In a non-interactive shell, a fresh application needs an explicit environment and database choice. For example, review a local SQLite plan before choosing to apply it:

```bash
php squehub setup --environment=local --database=sqlite --preview
```

`--preview` writes nothing and does not run Doctor. The [Setup guide](Setup.md) explains confirmation and `--yes` for deliberate non-interactive application.

## Verify and run

```bash
php squehub doctor
php squehub route:list
php squehub start
```

Doctor reports required failures and optional warnings separately. A warning about unused Redis or SMTP does not mean the basic application failed. `start` serves **only** `public/`, tries an available port in 8000–8099 when no port is supplied, and prints its chosen local URL. For Apache or Nginx, set the document root to `public/`; never serve the repository root, which contains source and `.env`.

For a coordinated local session after setup, you may instead run `php squehub dev`. [SqueHub Dev](Dev.md) performs Doctor preflight and starts the same development server; an existing persistent Queue worker can be selected explicitly with `--queue`. The standalone `start` workflow remains available. Neither command installs dependencies or runs Migrations or Seeders.

Continue with [First application](FirstApplication.md). For production preparation, use [Deployment](Deployment.md) and [Health](Health.md).
