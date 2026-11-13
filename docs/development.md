# Flynn Development Setup

Flynn is a polyglot monorepo with independent addon, web, desktop, and bot applications. Keep each application's native dependency manager and scripts as the source of truth; use the root [`justfile`](../justfile) only to provide convenient cross-app entry points.

## Why `just` instead of Moon

Use [`just`](https://just.systems) as the initial root task runner. It provides readable recipes for running the existing ecosystem commands without imposing another project graph or package manager. There are currently no build dependencies between the four applications, so Moon's project graph, affected-task selection, and caching would add configuration without removing meaningful work. Moon can be reconsidered if CI time becomes significant or shared build dependencies emerge. It would not replace Composer, npm, Cargo, or Lua tooling.

## Prerequisites

Install the tools required by the applications you are working on:

- `just` for root-level commands.
- PHP CLI, Composer, Node.js/npm, and PostgreSQL for `web/`.
- A Lua 5.1-compatible interpreter and LuaRocks for `addon/` tests/linting; a WoW client is needed for in-game verification.
- Rust stable via `rustup` for `desktop/` and `bot/`. Tauri desktop builds also require the platform-specific system libraries/tooling documented by Tauri.
- `zip` for the addon source package.

Keep database credentials and API/Discord secrets in local environment configuration or a secrets manager; do not commit them. Configure the web app to use PostgreSQL as described in [database-and-jobs.md](database-and-jobs.md).

## Setup

From the repository root, install each app's dependencies with:

```sh
just setup
```

Or set up only one application:

```sh
just addon-setup
just web-setup
just desktop-setup
just bot-setup
```

The web application also requires its local environment file, application key, PostgreSQL database, and migrations. Once the Laravel scaffold exists, follow its `.env.example`, then run `php artisan key:generate` and `php artisan migrate` from `web/`.

## Test, lint, and build

Run the full suite from the repository root:

```sh
just test
just lint
just build
```

Each application can also be run independently:

| Application | Test | Lint / format | Build |
| --- | --- | --- | --- |
| Addon | `busted addon/spec` | `luacheck addon` | `zip -rq build/Flynn.zip addon -x '*/.DS_Store'` |
| Web | `cd web && php artisan test && npm test` | Laravel Pint and `npm run lint` | `cd web && npm run build` |
| Desktop | `cd desktop && cargo test --workspace` | `cargo fmt --all -- --check` and `cargo clippy --workspace --all-targets --all-features -- -D warnings` | `cargo build --workspace --release` |
| Bot | `cd bot && cargo test --workspace` | `cargo fmt --all -- --check` and `cargo clippy --workspace --all-targets --all-features -- -D warnings` | `cargo build --workspace --release` |

The web frontend package must expose `test`, `lint`, and `build` npm scripts; the Laravel application must include Pint as a development dependency. Lua tests live under `addon/spec`. Rust commands run from the corresponding app directory and use each app's checked-in Cargo workspace/lockfile.

## Current scaffold status

The current app directories are placeholders and do not yet contain `composer.json`, `package.json`, Cargo manifests, addon Lua files, or test suites. Root recipes define the intended interface but cannot pass until each application is scaffolded. Keep the setup/test/lint/build TODO open until the matching native commands exist and have been run successfully; update this document and the `justfile` if a scaffold chooses different native commands.
