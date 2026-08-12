#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <version> [output-directory]" >&2
    exit 2
fi

version="$1"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Version must be a semantic version such as 1.1.0." >&2
    exit 2
fi

default_source_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="${BUILD_SOURCE_DIR:-$default_source_dir}"
source_dir="$(cd -- "$source_dir" && pwd)"
output_dir="${2:-$source_dir/dist}"
stage_dir="$(mktemp -d)"

cleanup() {
    rm -rf -- "$stage_dir"
}
trap cleanup EXIT

mkdir -p -- "$output_dir" "$stage_dir/package/AdminReindex"
output_dir="$(cd -- "$output_dir" && pwd)"
archive="$output_dir/haroone-module-admin-reindex-$version.zip"

tar -C "$source_dir" -cf - \
    --exclude='./.git' \
    --exclude='./.github' \
    --exclude='./.gitattributes' \
    --exclude='./.gitignore' \
    --exclude='./Test' \
    --exclude='./bin' \
    --exclude='./dev' \
    --exclude='./dist' \
    --exclude='./docs' \
    --exclude='./vendor' \
    --exclude='./auth.json' \
    --exclude='./composer.lock' \
    --exclude='./CONTRIBUTING.md' \
    --exclude='./CODE_OF_CONDUCT.md' \
    . | tar -C "$stage_dir/package/AdminReindex" -xf -

find "$stage_dir/package/AdminReindex" -type d -exec chmod 0755 {} +
find "$stage_dir/package/AdminReindex" -type f -exec chmod 0644 {} +

php "$default_source_dir/dev/check-release.php" "$stage_dir/package/AdminReindex"
composer validate "$stage_dir/package/AdminReindex/composer.json" --strict --no-check-publish

rm -f -- "$archive"
(
    cd "$stage_dir/package"
    zip -X -q -r "$archive" AdminReindex
)

unzip -t "$archive" >/dev/null
if zipinfo -1 "$archive" | grep -q '\\'; then
    echo "Archive contains a Windows path separator." >&2
    exit 1
fi

mkdir -p "$stage_dir/extracted"
unzip -q "$archive" -d "$stage_dir/extracted"
php "$default_source_dir/dev/check-release.php" "$stage_dir/extracted/AdminReindex"

printf '%s\n' "$archive"
