# Flynn Web Application

Laravel API and React + TypeScript web application for Flynn accounts, guilds, character data, and crafting requests.

## Local development

Prerequisites: PHP 8.5, Composer, Node.js/npm, PostgreSQL 18, and `just` (see the repository's `docs/development.md`). From the repository root:

```sh
just web-setup
cp web/.env.example web/.env
```

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `web/.env` for a local PostgreSQL role/database, then run:

```sh
cd web
php artisan key:generate
php artisan migrate
```

The API and SPA are same-origin. For frontend hot reload, run `php artisan serve` and `npm run dev` in separate terminals. Configure Discord linking with `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_REDIRECT_URI`, and the bot's `DISCORD_BOT_TOKEN`. Set `FLYNN_BOT_SERVICE_TOKEN` only for a trusted deployment; production bot credentials should be stored as SHA-256 hashes in `service_credentials` with minimal JSON `abilities` such as `recipes:read`, `cart:read`, `cart:write`, and `requests:write`. Environment-configured bearer and HMAC credentials also require explicit comma-separated `FLYNN_BOT_SERVICE_ABILITIES` or `FLYNN_BOT_HMAC_ABILITIES` scopes.

For deployment-wide HMAC auth, set `FLYNN_BOT_HMAC_SECRET` and a minimal `FLYNN_BOT_HMAC_ABILITIES` list. Sign the newline-joined values `METHOD`, URL path, raw query string, Unix timestamp, unique nonce, SHA-256 hex of the exact request body, Discord user ID, and Discord guild ID with HMAC-SHA256. Send the hex digest in `X-Flynn-Signature`, timestamp in `X-Flynn-Timestamp`, nonce in `X-Flynn-Nonce`, plus both actor headers. Timestamps must be within five minutes and a nonce can only be used once. Set `FLYNN_DESKTOP_REDIRECT_URI` to the registered loopback callback.

Run the workers separately so dump ingestion does not starve notifications:

```sh
php artisan queue:work database --queue=ingestion --sleep=1 --tries=5 --timeout=90
php artisan queue:work database --queue=notifications,default --sleep=1 --tries=5 --timeout=90
php artisan schedule:work
```

Offline catalog snapshots can be imported with `php artisan flynn:catalog-import /path/to/catalog.json --game-flavor=retail` (the supported values are `retail`, `classic_era`, `classic`, and `forever`). The JSON document has `professions`, `spells`, and `items` arrays; rows are assigned to the operator-supplied flavor, but the importer cannot independently verify source provenance. Only import a verified WoW Forever-specific snapshot with `--game-flavor=forever`. No verified Forever catalog source/snapshot is currently supplied; unknown IDs still display as `Recipe #<spellID>` or `Item #<itemID>` rather than borrowing another flavor's metadata.

Quality checks (also wired to the root task runner):

```sh
php artisan test
./vendor/bin/pint --test
npm test
npm run lint
npm run build
```
