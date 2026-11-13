default: list

list:
    @just --list

setup: addon-setup web-setup desktop-setup bot-setup

addon-setup:
    luarocks install --local luacheck
    luarocks install --local busted

web-setup:
    cd web && composer install
    cd web && npm ci

desktop-setup:
    cd desktop && cargo fetch --locked

bot-setup:
    cd bot && cargo fetch --locked

test: addon-test web-test desktop-test bot-test

addon-test:
    busted addon/spec

web-test:
    cd web && php artisan test
    cd web && npm test

desktop-test:
    cd desktop && cargo test --workspace

bot-test:
    cd bot && cargo test --workspace

lint: addon-lint web-lint desktop-lint bot-lint

addon-lint:
    luacheck addon

web-lint:
    cd web && ./vendor/bin/pint --test
    cd web && npm run lint

desktop-lint:
    cd desktop && cargo fmt --all -- --check
    cd desktop && cargo clippy --workspace --all-targets --all-features -- -D warnings

bot-lint:
    cd bot && cargo fmt --all -- --check
    cd bot && cargo clippy --workspace --all-targets --all-features -- -D warnings

build: addon-build web-build desktop-build bot-build

addon-build:
    mkdir -p build
    zip -rq build/Flynn.zip addon -x '*/.DS_Store'

web-build:
    cd web && npm run build

desktop-build:
    cd desktop && cargo build --workspace --release

bot-build:
    cd bot && cargo build --workspace --release
