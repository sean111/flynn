#!/bin/sh
set -eu
rocks=${FLYNN_LUAROCKS_TREE:-"$HOME/.cache/flynn-lua-tools"}
command -v luarocks >/dev/null 2>&1 || { echo 'Install LuaRocks first.' >&2; exit 1; }
luarocks --lua-version=5.1 --tree="$rocks" install busted
luarocks --lua-version=5.1 --tree="$rocks" install luacheck
printf 'Lua dev tools installed under %s\n' "$rocks"
printf 'Run with: eval "$(luarocks --lua-version=5.1 --tree="%s" path)" && just addon-test\n' "$rocks"
