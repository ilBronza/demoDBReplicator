# demoDBReplicator

Laravel package for copying a MySQL/MariaDB database to staging or pulling a
production database into local development. PHP coordinates the native database
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

## Copy to staging

Configure `connections.source` and `connections.destination` with the names of
connections in the host application's `config/database.php`, then run:

```bash
php artisan dbreplicator:replicate
```

The source is exported, destination tables/views are replaced, and migrations run
in `stagingPath` when `migrate` is enabled. Source and destination must differ.
As before, this operation replaces the destination database contents.

The **Pacchetti → Replica database** menu entry is available only with
`APP_ENV=staging`. It reuses a menu section named `packages` if one already exists.
The web route `dbreplicator/replicate-db` also requires the staging environment,
authentication and a role from `defaultRoles`/`routeRoles` (default: `superadmin`).
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
IB_REPLICATOR_REMOTE_DEFAULTS_FILE=/home/deploy/.dbreplicator.cnf
IB_REPLICATOR_PRODUCTION_DATABASE=production_database
IB_REPLICATOR_LOCAL_CONNECTION=mysql
```

SSH host aliases are supported. SSH user and identity file are optional when they
are already provided by SSH configuration or an SSH agent. Authentication uses
`BatchMode=yes`: key authentication and the server's trusted host key must already
be set up. Password prompts are not supported.

`IB_REPLICATOR_REMOTE_DEFAULTS_FILE` points to an **existing file on production**,
readable only by the SSH user, containing the remote database access settings:

```ini
[client]
user="replication_reader"
password="your-database-password"
host="127.0.0.1"
port=3306
```

Use file permissions `0600`. Remote database credentials remain on the server;
the package does not read the production `.env` or require a production web
endpoint. The account needs the permissions required by the chosen dump options,
including export of routines/events. SSL settings for the remote MySQL client can
also be configured in this file.

The destination database must already exist and is selected using the named
Laravel connection. TCP hosts are restricted to `pull.localHosts`: localhost,
loopback addresses and common Docker hosts (`mysql`, `mariadb`,
`host.docker.internal`) by default. Add a custom Docker service name there if
needed. Unix sockets are also supported.

## Required tools and options

- Local environment: PHP, `ssh`, `mysqldump` and `mysql` accessible by the process.
- Production: `mysqldump` accessible by the SSH user.
- Local binaries: `IB_REPLICATOR_MYSQL_DUMP`, `IB_REPLICATOR_MYSQL`,
  `IB_REPLICATOR_SSH` (default to executable names on `PATH`).
- Remote binary: `IB_REPLICATOR_REMOTE_MYSQL_DUMP` (default: `mysqldump`).
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
