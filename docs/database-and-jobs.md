# Database, Migrations, and Queued Jobs

## Decisions

- **Relational database:** PostgreSQL. Use the same supported PostgreSQL major version in local development, CI, and production; pin the major version in the development/container and deployment configuration.
- **Schema management:** Laravel's checked-in, timestamped database migrations are the sole source of schema changes. Do not maintain a separately edited schema dump as the migration source of truth.
- **Initial queue backend:** Laravel's `database` queue driver on the same PostgreSQL service. This avoids adding Redis as an initial operational dependency while keeping background work asynchronous. Domain tables remain authoritative; queue rows are operational work records, not domain state.
- **Shared cache:** Laravel's `database` cache store initially, so cache-based worker restart signals and multi-host coordination use shared state; create the cache table if the application scaffold does not include it.
- **Durable event delivery:** Persist domain events in a transactional outbox in PostgreSQL. Outbox records are written in the same transaction as the domain change; a relay queues delivery jobs and retries publication. Consumers must be idempotent because an event may be delivered more than once.

The relational model is normalized for domain entities and foreign-key relationships. Use PostgreSQL `JSONB` only for intentionally flexible metadata or event payloads, not as a substitute for core relational data such as memberships, character/profession/recipe associations, or request status.

## Schema and migration strategy

1. Put application schema changes in `web/database/migrations` and review each migration as production code. Create a new migration for every change; never edit a migration that has already been applied in a shared environment.
2. Use Laravel migrations to create/alter tables, constraints, and indexes. Use foreign keys and unique constraints for invariants that must hold regardless of which client writes through the API. Important uniqueness/consistency constraints include linked Discord identities, namespaced character identity, profession and recipe identity within their parent, and one transition record per emitted domain-event ID.
3. Keep production changes backward-compatible across rolling application deployments: use expand-and-contract changes (add nullable/new fields first, deploy compatible code, backfill, then remove old structures in a later release). Avoid destructive column changes and large table rewrites in the same release as code that still needs the old shape.
4. Run migrations once as a release/deployment step, not independently from every web or worker process. Review the migration SQL and take a verified backup before high-risk schema changes. Production runs use `php artisan migrate --force`; do not automatically run `migrate:fresh` or production rollbacks as a recovery strategy.
5. Make `down()` methods useful for local/test iteration when reversal is safe. For destructive or data-transforming changes, document why rollback is unsafe and use a forward corrective migration plus backup/restore procedures instead of implying data can be recovered by `down()`.
6. Test migrations against PostgreSQL in CI, including a clean database migration and an upgrade from the previous released schema. Use Laravel's `RefreshDatabase` for isolated feature tests. SQLite may be used for fast unit-only tests but is not a substitute for PostgreSQL migration/integration coverage.

The core schema is expected to include accounts and linked identities; guilds and memberships; characters, professions, recipes, outputs, and reagents; dump/ingestion records; carts and cart items; crafting requests and transition history; and notification/outbox records. Laravel's `migrations`, `jobs`, `failed_jobs`, and (if the shared database cache is selected) cache tables are infrastructure tables created by their corresponding migrations.

## Queue and worker configuration

Use Laravel's `database` queue connection with PostgreSQL (`DB_CONNECTION=pgsql`) and configure the queue connection to dispatch after database commits. Supply database credentials through environment-specific secret configuration; do not commit credentials. Create the `jobs` table if the Laravel application scaffold does not already provide its migration:

```sh
php artisan make:queue-table
php artisan migrate
```

Ensure a failed-jobs table migration is present as well; generate it with `php artisan make:queue-failed-table` if it is absent. Configure `QUEUE_CONNECTION=database`. Set the database queue connection's `after_commit` option to `true`, and set `retry_after` to `120` seconds. Jobs that perform external calls should have a worker timeout shorter than `retry_after`; the initial worker timeout is `90` seconds.

Configure the shared cache store as `database` (`CACHE_STORE=database` in current Laravel applications). If no cache-table migration is present, create it with `php artisan make:cache-table`, then run `php artisan migrate`.

Run separate supervised workers so a large dump backlog does not starve notifications:

```sh
php artisan queue:work database --queue=ingestion --sleep=1 --tries=5 --timeout=90
php artisan queue:work database --queue=notifications,default --sleep=1 --tries=5 --timeout=90
```

Use bounded exponential retry delays in the job definitions (initial schedule: 10, 30, 90, and 300 seconds, with a five-attempt ceiling). After the configured attempts, jobs move to `failed_jobs`; alert operators, preserve the associated dump/event state for inspection, and retry only after diagnosing the cause. Never silently mark a failed dump as completed or discard an undelivered notification event.

Laravel defers queued work until a transaction commits when `after_commit` is enabled. To avoid losing a notification if a process stops between committing a domain transition and publishing its job, write a uniquely identified outbox event in the same transaction as each request transition. A scheduled relay claims unpublished events, queues jobs containing the event ID, then records that they were queued. If it crashes between queuing and recording, it may queue a duplicate; handlers must deduplicate by event ID and external delivery identifiers. Mark an outbox event delivered only after the external delivery succeeds; retain undelivered events for retries and operator diagnosis, and dead-letter them only through an explicit recoverable operation.

Run the Laravel scheduler every minute to drive the outbox relay and cleanup of expired temporary upload staging data. The deployment's scheduler entry is:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Operate workers under a process manager that restarts failed workers and run `php artisan queue:restart` during deployment so long-lived workers load the new code. The shared database cache store carries restart signals when web/worker processes run on multiple hosts.

Dump jobs receive a `dumpId` and a private staging-object reference rather than a large serialized file body. Keep staged uploads outside the public web root, restrict access to the ingestion worker, and remove temporary data after a terminal ingestion state plus the configured diagnostic retention period. The source of truth for successfully ingested data is the normalized PostgreSQL domain schema.

## Operating and testing commands

```sh
# Local schema setup
php artisan migrate

# Inspect migration status
php artisan migrate:status

# Run the queue worker locally
php artisan queue:work database --queue=ingestion,notifications,default --tries=5 --timeout=90

# Run tests against a dedicated PostgreSQL test database
php artisan test

# Release step (after backup/review)
php artisan migrate --force
php artisan queue:restart
```

Use `php artisan migrate:rollback` only for a reviewed, safe rollback in development or staging. For production, prefer a forward-fix migration unless the rollback is known to be lossless and has an approved recovery plan.

Laravel command and configuration guidance in this document follows the current [Laravel 13 queue](https://laravel.com/docs/13.x/queues), [migration](https://laravel.com/docs/13.x/migrations), [cache](https://laravel.com/docs/13.x/cache), and [scheduler](https://laravel.com/docs/13.x/scheduling) documentation. Once the web app pins its framework version in `composer.lock`, verify the exact artisan commands and configuration keys against that version.
