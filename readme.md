# demoDBReplicator

Laravel package for importing production MySQL/MariaDB databases into local or
remote staging from a superadmin menu, or using the existing CLI workflows.
PHP coordinates the native database
clients through Symfony Process; no separate shell script is required.

## Installation

```bash
composer require ilbronza/demodbreplicator
php artisan vendor:publish --provider="IlBronza\DemoDBReplicator\DemoDBReplicatorServiceProvider"
```

Configuration lives in `config/dbreplicator.php`. Existing installations can add
the new settings to their published configuration; they are also merged from the
package defaults when Laravel configuration is not cached. After changing
configuration or environment variables, clear/rebuild the application's config
cache as appropriate.

## Import production from Settings (local or remote staging)

The **Settings → Database import → database name** menu is reserved for
`superadmin`. Define any number of `imports` entries: each entry creates one
submenu item. The destination application must have **`APP_ENV=local` or
`APP_ENV=staging`**. Production and all other environments hide this menu and
reject imports in middleware, controllers and the import service, including
when routes are cached. The framework environment and `config('app.env')` must
agree on an allowed environment. These import permissions cannot be broadened
with the legacy `defaultRoles` / `routeRoles` options.

Selecting a database opens a confirmation page showing the production host and
the destination database. Its CSRF-protected POST triggers a server-to-server
HTTPS request, downloads and verifies the complete dump, backs up the destination,
removes its existing objects, and imports the dump. Credentials, URLs and
destination connections come exclusively from server configuration.

### Production setup

Install/update this package **on the production Laravel project too**. Production
needs only an explicit export allowlist and API credentials; all import profiles
and destination settings belong to staging. The export API is disabled by default.

Generate a distinct random secret for each source database, for example:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Set these values in production's `.env`:

```dotenv
APP_ENV=production
IB_REPLICATOR_API_ENABLED=true
IB_REPLICATOR_MAIN_API_TOKEN=<generated-secret>
IB_REPLICATOR_ARCHIVE_API_TOKEN=<another-generated-secret>
IB_REPLICATOR_CATALOG_API_TOKEN=<another-generated-secret>
```

Add the following to production's `config/dbreplicator.php`. Connection names
refer to its own `config/database.php`:

```php
'export' => [
    'enabled' => env('IB_REPLICATOR_API_ENABLED', false),
    'databases' => [
        'main' => ['connection' => 'mysql', 'token' => env('IB_REPLICATOR_MAIN_API_TOKEN')],
        'archive' => ['connection' => 'mysql_archive', 'token' => env('IB_REPLICATOR_ARCHIVE_API_TOKEN')],
        'catalog' => ['connection' => 'mysql_catalog', 'token' => env('IB_REPLICATOR_CATALOG_API_TOKEN')],
    ],
],
```

The endpoint is `POST /dbreplicator/api/exports/{database}`. It requires HTTPS
and `Authorization: Bearer <secret>` matching that specific allowlisted entry.
Tokens must have at least 32 characters. It does not accept database names,
connection settings or shell arguments from the request body. Production only
exports; this endpoint never imports or clears a database. Configure Laravel's
trusted proxies correctly when TLS terminates at a reverse proxy.

### Staging setup

Configure the production token(s) in staging's `.env` too, together with:

```dotenv
APP_ENV=staging
SESSION_DRIVER=file
IB_REPLICATOR_API_ENABLED=false
IB_REPLICATOR_MAIN_API_TOKEN=<same-secret-as-production-main>
IB_REPLICATOR_ARCHIVE_API_TOKEN=<same-secret-as-production-archive>
IB_REPLICATOR_CATALOG_API_TOKEN=<same-secret-as-production-catalog>
```

Use `APP_ENV=local` for a local development installation. File or Redis sessions
keep the request independent of the database being replaced; the web import
rejects `SESSION_DRIVER=database`. The initiating session is invalidated after
an import attempt, including failure, because a partial import may also replace
users with different identities.

Example staging `config/dbreplicator.php` entries for three menu items:

```php
'imports' => [
    'main' => [
        'label' => 'Gestionale',
        'url' => 'https://production.example.com/dbreplicator/api/exports/main',
        'token' => env('IB_REPLICATOR_MAIN_API_TOKEN'),
        'destination' => 'mysql',
        'destinationGuard' => ['database' => 'gestionale_staging', 'host' => '127.0.0.1', 'port' => 3306],
        'migrate' => false,
    ],
    'archive' => [
        'label' => 'Archivio',
        'url' => 'https://production.example.com/dbreplicator/api/exports/archive',
        'token' => env('IB_REPLICATOR_ARCHIVE_API_TOKEN'),
        'destination' => 'mysql_archive',
        'destinationGuard' => ['database' => 'archivio_staging', 'host' => '127.0.0.1', 'port' => 3306],
    ],
    'catalog' => [
        'label' => 'Catalogo',
        'url' => 'https://production.example.com/dbreplicator/api/exports/catalog',
        'token' => env('IB_REPLICATOR_CATALOG_API_TOKEN'),
        'destination' => 'mysql_catalog',
        'destinationGuard' => ['database' => 'catalogo_staging', 'host' => '127.0.0.1', 'port' => 3306],
    ],
],
```

Create the destination databases and Laravel connections beforehand. Profile
keys use letters, digits, underscores or hyphens. Each profile can point to a
different production application. `destinationGuard` is mandatory and must match
the effective destination database, host and port exactly, including resolved
connection URLs. For sockets replace `host` and `port` with
`'unix_socket' => '/path/to/mysql.sock'`. A staging database on a different host
is supported: put its explicit host/port in the guard. Use destination credentials
scoped to the staging databases.

The menu hooks into the host application's existing `manageMenuButtons()` flow
and reuses its `settings` section. With the Italian locale the example produces
**Impostazioni → Importazione database → Gestionale / Archivio / Catalogo**.

### Transfer, replacement and recovery

- Both servers need `mysqldump`; staging also needs `mysql`. SSH is not used by
  this flow. Source and destination must be MySQL/MariaDB versions with compatible
  dump SQL, including routine/event definers and required privileges.
- Production finishes the dump before returning it. Staging streams it into a
  private file and checks the byte count and SHA-256 before any replacement.
  HTTP errors, redirects, truncated responses and invalid metadata stop the import.
  TLS certificate verification cannot be disabled in the profile.
- The server hostname, port and database identity sent by production are compared
  with the destination as an additional check against copying a database onto
  itself through a connection alias.
- A successful destination dump is mandatory before deletion. Replacement drops
  events, views, tables (and their triggers), functions and procedures, including
  objects absent from the source. It leaves the existing database/schema itself
  and its grants in place. MySQL DDL/import is not an atomic transaction.
- File locks and a MySQL advisory lock serialize API imports to a destination,
  including from different staging application hosts. They do not stop ordinary
  application writes: use an idle staging instance and pause its workers during
  replacement. Production's normal transactional dump consistency requirements
  still apply.
- Optional migrations are off by default for this flow. Set `migrate => true`
  on the individual profile to run the current application's migrations on its
  destination connection after import. The legacy global `migrate` setting does
  not override this choice.
- SQL files are private (`0600`) in `dumpFilePath`, which must be outside the
  public web root. Production deletes its dump after streaming; staging removes
  the downloaded dump after success and retains `staging-backup-*.sql`. A failed
  replacement/migration retains the backup and downloaded dump, with their paths
  in the application log. A failed transfer removes its partial file.
- After a partial replacement, stop application writes and restore the recorded
  backup to the same staging connection after clearing its objects. No automatic
  rollback is attempted. Manage backup retention and remove stale export files
  left by hard process/server termination.

This web flow runs synchronously, with SQL streamed to disk rather than kept in
PHP memory. Configure PHP-FPM, the web server and any proxies on **both** servers
to permit the full operation; `dbreplicator.timeout` (default 1800 seconds) bounds
each client process and the download separately, not the overall web request.
Allow enough disk space for production's export and staging's download plus
backup. A proxy timeout does not prove the server stopped: inspect logs before
retrying. Applications needing jobs longer than their hosting's request limits
must use a worker-based workflow instead of this synchronous endpoint.

The full database includes production accounts, sessions, settings and scheduled
database events. Keep staging's application workers/integrations configured for
staging; this package does not anonymize data or copy uploads and environment files.

## Copy to staging

Configure `connections.source` and `connections.destination` with the names of
connections in the host application's `config/database.php`, then run:

```bash
php artisan dbreplicator:replicate
```

The source is exported, destination tables/views are replaced, and migrations run
in `stagingPath` when `migrate` is enabled. Source and destination must differ.
As before, this operation replaces the destination database contents.

The old **Pacchetti → Replica database** menu has been replaced by the Settings
menu described above. The legacy web route `dbreplicator/replicate-db` now only
accepts **POST with CSRF**, requires `APP_ENV=staging` and `superadmin`, and still
applies `defaultRoles`/`routeRoles` as an additional restriction. Existing callers
using a GET link must be updated; visiting a GET URL no longer replaces data.
The staging environment restriction applies to the web action; the existing
console replication command remains available for established server workflows.

The native clients now use each Laravel connection's effective host, port,
socket, username, password and database, including connection URLs. The old
global `tcp_socketString`/`IB_REPLICATOR_SOCKET_TCP` setting is no longer used.
Source and destination settings must be present in their database connections.

## Pull production into local development

Run this command **from the local Laravel project with `APP_ENV=local`**:

```bash
php artisan dbreplicator:pull
```

It performs these steps in order:

1. Runs `mysqldump` on production over SSH and streams the SQL into a local file.
2. Verifies successful completion and a nonempty dump.
3. Exports the current local database to a separate backup.
4. Removes local tables/views and imports the production dump.
5. Runs migrations in the current local project on the destination connection.

The original production database is only exported. A failed transfer or failed
backup stops the operation before local tables are removed. A failed import can
leave the local database partially populated; both the production dump and the
local backup are retained for recovery. The backup path is printed before the
replacement starts. Successful runs remove the downloaded production dump and
retain the local backup in `dumpFilePath` (default: `storage/app/tmp`).
Backup retention/cleanup is managed by the developer.

To skip migrations for one run:

```bash
php artisan dbreplicator:pull --no-migrate
```

Set these variables **in the local project's environment**:

```dotenv
APP_ENV=local
IB_REPLICATOR_SSH_HOST=production.example.com
IB_REPLICATOR_SSH_USER=deploy
IB_REPLICATOR_SSH_PORT=22
IB_REPLICATOR_SSH_IDENTITY_FILE=/Users/your-user/.ssh/id_ed25519
IB_REPLICATOR_REMOTE_PROJECT_PATH=/var/www/production-project
IB_REPLICATOR_LOCAL_CONNECTION=mysql
```

SSH host aliases are supported. SSH user and identity file are optional when they
are already provided by SSH configuration or an SSH agent. Authentication uses
`BatchMode=yes`: key authentication and the server's trusted host key must already
be set up. Password prompts are not supported.

`IB_REPLICATOR_REMOTE_PROJECT_PATH` is the Laravel project's **root on production**,
containing `vendor/autoload.php` and `bootstrap/app.php`. Remote PHP loads Laravel's
environment and configuration, using the configuration cache when present. The
database credentials and name are taken from the project's default database
connection. To select another named connection, set:

```dotenv
IB_REPLICATOR_REMOTE_CONNECTION=mysql_production
```

The export helper is sent over SSH: this version of the package does not need to
be installed or deployed on production. It loads configuration without booting
application service providers or running Artisan commands. Remote credentials
stay on the server in a temporary `0600` file, removed after the dump process
finishes; only SQL is streamed back. It uses the database account configured in
the remote project, including its existing permissions.

PHP CLI and `mysqldump` must be available to the SSH user on production. Set
`IB_REPLICATOR_REMOTE_PHP` when PHP is not on `PATH` or a specific PHP version is
required. The SSH user must be able to read the project's environment/config,
autoload and bootstrap files and create a temporary credentials file. A configured
project path always takes precedence; an invalid project does not silently switch
to another database source.

Alternatively, leave `IB_REPLICATOR_REMOTE_PROJECT_PATH` unset and configure:

```dotenv
IB_REPLICATOR_REMOTE_DEFAULTS_FILE=/home/deploy/.dbreplicator.cnf
IB_REPLICATOR_PRODUCTION_DATABASE=production_database
```

The defaults-file mode reads an **existing file on production**, readable only by
the SSH user, containing the remote database access settings:

```ini
[client]
user="replication_reader"
password="your-database-password"
host="127.0.0.1"
port=3306
```

Use file permissions `0600`. Neither mode requires a production web endpoint.
The account needs the permissions required by the chosen dump options, including
export of routines/events. SSL settings for the remote MySQL client can also be
configured in this file; project mode forwards the Laravel PDO SSL CA, certificate
and key options.

The destination database must already exist and is selected using the named
Laravel connection. TCP hosts are restricted to `pull.localHosts`: localhost,
loopback addresses and common Docker hosts (`mysql`, `mariadb`,
`host.docker.internal`) by default. Add a custom Docker service name there if
needed. Unix sockets are also supported.

## Required tools and options

- Local environment: PHP, `ssh`, `mysqldump` and `mysql` accessible by the process.
- Production: `mysqldump` accessible by the SSH user, plus PHP CLI (7.4+) for
  project-root mode, compatible with the remote project's Laravel installation.
- Local binaries: `IB_REPLICATOR_MYSQL_DUMP`, `IB_REPLICATOR_MYSQL`,
  `IB_REPLICATOR_SSH` (default to executable names on `PATH`).
- Remote binary: `IB_REPLICATOR_REMOTE_MYSQL_DUMP` (default: `mysqldump`).
- Remote PHP: `IB_REPLICATOR_REMOTE_PHP` (default: `php`).
- `timeout`: seconds per process (default: 1800).
- `dumpOptions`: defaults to `--single-transaction`, `--quick`, `--routines`,
  `--events`, `--triggers`. Adjust for the database engine/version and privileges.
  Transactional consistency requires InnoDB tables and no concurrent schema
  changes; source and destination database versions must accept the dump's SQL.

For Homebrew, binary paths can be set explicitly, for example:

```dotenv
IB_REPLICATOR_MYSQL_DUMP=/opt/homebrew/opt/mysql-client/bin/mysqldump
IB_REPLICATOR_MYSQL=/opt/homebrew/opt/mysql-client/bin/mysql
```

With Docker/Sail, either run Artisan on the host against the published local
database port, or run it in the application container with the required clients,
SSH key/agent and known-hosts configuration available there.

SQL is streamed through files rather than retained in PHP memory. Local password
files and dumps are created with permissions `0600`; temporary credentials are
removed after each process. Destination file locks prevent concurrent copies
from the same project. This package copies the database; application files,
uploads and environment configuration are managed separately.

## Tests

The test suite uses Laravel Testbench and simulated database/SSH processes. It
never connects to production. With the shared workspace test environment:

```bash
python3 ../.ibtest/bin/ibtest.py demoDBReplicator
```

With a project/vendor installation providing PHPUnit and Testbench, run
`vendor/bin/phpunit`.
