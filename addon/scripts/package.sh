#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
flavor=${1:-}
destination=${2:-"$root/dist/$flavor"}
case "$flavor" in
  retail|classic_era|classic|forever) ;;
  *) echo "usage: $0 retail|classic_era|classic|forever [destination]" >&2; exit 2 ;;
esac
mkdir -p "$destination/Flynn"
cp -R "$root/Flynn/Core" "$root/Flynn/Libs" "$destination/Flynn/"
cp "$root/Flynn/package/$flavor.toc" "$destination/Flynn/Flynn.toc"
printf 'Packaged Flynn for %s at %s\n' "$flavor" "$destination/Flynn"
