#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
rocks=${FLYNN_LUAROCKS_TREE:-"$HOME/.cache/flynn-lua-tools"}
if [ -x "$rocks/bin/busted" ] && [ -x "$rocks/bin/luacheck" ] && command -v luarocks >/dev/null 2>&1; then
  PATH="$rocks/bin:$PATH"
  export PATH
  eval "$(luarocks --lua-version=5.1 --tree="$rocks" path)"
fi
command -v busted >/dev/null 2>&1 || { echo 'busted is required (LuaJIT-compatible Busted).' >&2; exit 1; }
command -v luacheck >/dev/null 2>&1 || { echo 'luacheck is required.' >&2; exit 1; }
command -v luajit >/dev/null 2>&1 || { echo 'luajit is required for the Lua 5.1 export fixture.' >&2; exit 1; }
busted addon/spec
luacheck --config addon/.luacheckrc addon/Flynn/Core

tmp=$(mktemp "${TMPDIR:-/private/var/folders/j5/fn4cv8yd28z41zz6w2q0qqxc0000gn/T/opencode}/flynn-export-test.XXXXXX")
trap 'rm -f "$tmp"' EXIT HUP INT TERM
luajit addon/scripts/create-export-fixture.lua "$tmp"
if command -v php >/dev/null 2>&1; then php addon/scripts/verify-export.php "$tmp"; else echo 'PHP not found; skipped cross-runtime gzinflate test.'; fi

for flavor in retail classic_era classic forever; do
  destination="$root/addon/.test-package/$flavor"
  addon/scripts/package.sh "$flavor" "$destination" >/dev/null
  test -f "$destination/Flynn/Flynn.toc"
  test -f "$destination/Flynn/Core/Flynn.lua"
  test ! -d "$destination/Flynn/spec"
  if [ "$flavor" = forever ]; then grep -q '^## Interface: 16001$' "$destination/Flynn/Flynn.toc"; fi
done
rm -rf addon/.test-package
echo 'Addon package smoke checks passed.'
