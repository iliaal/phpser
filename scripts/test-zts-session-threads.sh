#!/usr/bin/env bash
set -euo pipefail

# Linux-only embed regression. PHP must be built with --enable-zts
# --enable-embed=shared and --enable-session.
php_config=${PHP_CONFIG:-php-config}
module=${1:-modules/phpser.so}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
module=$(realpath "$module")
[[ -f "$module" ]] || { echo "module not found: $module" >&2; exit 1; }
prefix=$("$php_config" --prefix)
read -r -a includes <<<"$("$php_config" --includes)"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"${CC:-cc}" -D_GNU_SOURCE -Wall -Wextra -Werror -pthread ${CFLAGS:-} "${includes[@]}" \
    "$root/scripts/test-zts-session-threads.c" \
    -L"$prefix/lib" -Wl,-rpath,"$prefix/lib" -lphp -ldl ${LDFLAGS:-} -o "$work/test-zts-session-threads"
mkdir "$work/sessions"
key=phpser-zts-thread-regression-key-0123456789abcdef
cat >"$work/php.ini" <<EOF
extension="$module"
session.auto_start=1
session.serialize_handler=phpser
session.save_path="$work/sessions"
session.use_strict_mode=0
session.use_cookies=1
phpser.key=$key
EOF
# Never inherit a system scan directory and accidentally load other modules.
PHP_INI_SCAN_DIR= timeout 60 "$work/test-zts-session-threads" "$work/php.ini" "$module" "$key"
