# Flynn Development Setup

Flynn is a polyglot monorepo with independent addon, web, desktop, and bot applications. Keep each application's native dependency manager and scripts as the source of truth; use the root [`justfile`](../justfile) only to provide convenient cross-app entry points.

## Why `just` instead of Moon

Use [`just`](https://just.systems) as the initial root task runner. It provides readable recipes for running the existing ecosystem commands without imposing another project graph or package manager. There are currently no build dependencies between the four applications, so Moon's project graph, affected-task selection, and caching would add configuration without removing meaningful work. Moon can be reconsidered if CI time becomes significant or shared build dependencies emerge. It would not replace Composer, npm, Cargo, or Lua tooling.

## Prerequisites

Install the tools required by the applications you are working on:

- `just` for root-level commands.
- PHP CLI, Composer, Node.js/npm, and PostgreSQL for `web/`.
- A Lua 5.1-compatible interpreter and LuaRocks for `addon/` tests/linting; a WoW client is needed for in-game verification.
- Rust stable via `rustup` and Node.js 20+/npm for `desktop/`; Rust stable for `bot/`. Tauri desktop builds also require the platform-specific system libraries/tooling documented by Tauri.
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

The web application also requires its local environment file, application key, PostgreSQL database, and migrations. Follow its `.env.example`, then run `php artisan key:generate` and `php artisan migrate` from `web/`. For desktop sign-in, configure the API's `FLYNN_DESKTOP_REDIRECT_URI` to `http://127.0.0.1:51337/oauth/callback`.

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
| Desktop | `just desktop-test` (`cargo test --workspace`, `cargo build --workspace`, `npm test`, and `npm run build`) | `cargo fmt --all -- --check` and `cargo clippy --workspace --all-targets --all-features -- -D warnings` | `cd desktop && npm run tauri build` |
| Bot | `cd bot && cargo test --workspace` | `cargo fmt --all -- --check` and `cargo clippy --workspace --all-targets --all-features -- -D warnings` | `cargo build --workspace --release` |

The web frontend package must expose `test`, `lint`, and `build` npm scripts; the Laravel application must include Pint as a development dependency. Lua tests live under `addon/spec`. Rust commands run from the corresponding app directory and use each app's checked-in Cargo workspace/lockfile.

## Current development status

- The addon, Laravel/React web app, and Rust Discord bot have native manifests and root `just` recipes.
- The desktop app uses Tauri 2 with a React/TypeScript frontend and Rust native services. `just desktop-setup` installs npm packages and fetches Cargo dependencies; `just desktop-test` runs Rust tests/build and frontend tests/build, while `just desktop-lint` and `just desktop-build` run lint and bundle checks. Native upload logic persists POST attempts before sending and blocks automatic retries after an unknown result. A persisted attempt is presented for action only while its exact hash matches the current enabled file; older ambiguous attempts remain persisted as duplicate-risk history but are not shown as actionable for changed content. Explicit retry is exercised through the retry command against a mock HTTP server and is not dispatched before confirmation. Pre-POST file failures are also persisted to suppress repeated reconciliation of unchanged content. Accepted pending dumps resume polling from their persisted server/user/dump ID even when the source file has moved or disappeared. This is not exactly-once delivery. Config writers use a single mutation fence from snapshot through commit, while disk saves serialize snapshots; lock ordering is mutation lock → config lock, with config released before serialized save acquires it for the persistence snapshot. Session generations fence stale auth responses and cancel/fence in-flight upload and polling requests on logout, API changes, or account switches; the frontend applies one shared ordering/generation fence to refresh and every whole-status command response. Cross-server credential cleanup is fail-closed and rolls back a new credential if removal of the prior server key fails. An expired-token 401 commits the signed-out local binding before attempting credential cleanup; if deletion fails, status remains signed out immediately and presents a persisted sanitized cleanup warning, while the leftover unbound keychain item is not used. Successful relogin clears that stale warning. Config writes flush the temporary file before atomic rename; Unix then attempts a parent-directory sync, while non-Unix currently treats that sync as a no-op. A failed Unix directory sync happens after the rename has committed and triggers a best-effort second write of the warning without retrying the directory sync, so neither the latest rename nor its warning is guaranteed to survive sudden power loss; the app does not roll back the committed settings. On macOS/Linux, uploads use a descriptor-relative no-follow walk from the selected root. Windows uses handle-relative `NtCreateFile` traversal from the selected volume root and rejects reparse points at each component. Windows runtime execution of the target tests and OS smoke checks remain required before release verification; Windows acceptance is unverified until the tests are run successfully on Windows.
- Desktop API sign-in opens the system browser and uses PKCE with a bounded local callback listener. Explicit macOS Keychain, Windows Credential Manager, and Linux Secret Service backends are selected per target; the access token never falls back to plaintext settings. WoW discovery, loss-resistant filesystem event processing, stable-file upload, user/server/path-scoped SHA-256 dedupe, and persisted asynchronous dump polling are implemented in the native layer.
- Live server authorization, actual OS keychain/tray/autostart behavior, and installed Tauri bundles still require platform smoke tests. Local test/build checks on macOS do not prove runtime behavior on Windows or Linux.
