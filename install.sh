#!/usr/bin/env bash
# Installs the PHP Data Bridge for every PHP-FPM version on this machine.
#
#   install.sh                  install for all PHP-FPM versions found
#   install.sh --php 8.5        only for PHP 8.5 (repeatable)
#   install.sh --source DIR     use a local checkout instead of downloading a release
#   install.sh --dry-run        show what would happen
#   install.sh --uninstall      remove the ini files, then the bridge files
#
# Per PHP version: checks that PHP-FPM loads the OpenTelemetry extension (installs it with
# apt when missing), then adds `auto_prepend_file` to PHP-FPM's own ini folder and reloads.
# The CLI is not touched. The ini line is only written after the bridge files are in place,
# and removed before them: PHP refuses every request when that file is missing.

set -euo pipefail

REPO="nckrtl/php-data-bridge"
PREFIX="/usr/local/share/php-data-bridge"
INI_NAME="99-php-data-bridge.ini"

versions=()
source_dir=""
dry_run=0
uninstall=0
release="latest"

while [ $# -gt 0 ]; do
    case "$1" in
        --php) versions+=("$2"); shift 2 ;;
        --source) source_dir="$2"; shift 2 ;;
        --version) release="$2"; shift 2 ;;
        --dry-run) dry_run=1; shift ;;
        --uninstall) uninstall=1; shift ;;
        -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

say() { printf '%s\n' "$*"; }
warn() { printf 'warning: %s\n' "$*" >&2; }

# Runs a command as root, or only prints it with --dry-run.
run() {
    if [ "$dry_run" -eq 1 ]; then
        say "  would run: $*"
        return 0
    fi
    if [ "$(id -u)" -eq 0 ]; then "$@"; else sudo "$@"; fi
}

# PHP-FPM binaries: php-fpm8.5 (Debian), php-fpm (Homebrew, others).
find_fpm_binaries() {
    local dir file
    for dir in /usr/sbin /usr/local/sbin /opt/homebrew/sbin /opt/homebrew/opt/php*/sbin /usr/local/opt/php*/sbin; do
        for file in "$dir"/php-fpm*; do
            # Homebrew links one binary from several places: list it once.
            [ -x "$file" ] && [ ! -d "$file" ] && { readlink -f "$file" 2>/dev/null || say "$file"; }
        done
    done | sort -u
}

fpm_version() { "$1" -v 2>/dev/null | head -1 | sed -E 's/^PHP ([0-9]+\.[0-9]+).*/\1/'; }
fpm_has_extension() { "$1" -m 2>/dev/null | grep -qx opentelemetry; }
fpm_scan_dir() { "$1" -i 2>/dev/null | sed -n 's/^Scan this dir for additional .ini files => //p'; }
fpm_ini() { "$1" -i 2>/dev/null | sed -n "s/^$2 => \([^=]*\) => .*/\1/p" | sed 's/ *$//'; }

# The OpenTelemetry extension turns PHP's JIT off. Development machines rarely use JIT;
# a server that enabled it on purpose should not lose it to a profiler.
fpm_jit_enabled() {
    local fpm="$1" scan_dir="$2" jit buffer
    jit="$(fpm_ini "$fpm" opcache.jit | tr '[:upper:]' '[:lower:]')"
    buffer="$(fpm_ini "$fpm" opcache.jit_buffer_size)"
    [ "$(fpm_ini "$fpm" opcache.enable)" = "On" ] || return 1
    case "$jit" in ""|disable|off|0|"no value") ;; *) [ "$buffer" != "0" ] && return 0 ;; esac

    # A pool can enable JIT on its own (php_admin_value[opcache.jit]); check pool files next to conf.d.
    local pools="${scan_dir%/conf.d}/pool.d"
    [ -d "$pools" ] && grep -hE '^[[:space:]]*php(_admin)?_value\[opcache\.jit\][[:space:]]*=' "$pools"/*.conf 2>/dev/null \
        | grep -qviE '=[[:space:]]*(disable|off|0)[[:space:]]*$'
}

wanted() {
    [ -z "${versions[*]+x}" ] && return 0
    local version
    for version in ${versions[@]+"${versions[@]}"}; do [ "$version" = "$1" ] && return 0; done
    return 1
}

reload_fpm() {
    local fpm="$1" version="$2"
    if ! run "$fpm" -t >/dev/null 2>&1; then
        warn "PHP $version: PHP-FPM config test failed; not reloading"
        return 1
    fi
    if command -v systemctl >/dev/null && systemctl list-unit-files "php$version-fpm.service" >/dev/null 2>&1 \
        && systemctl is-active --quiet "php$version-fpm"; then
        run systemctl reload "php$version-fpm"
    elif command -v brew >/dev/null && brew services list 2>/dev/null | grep -qE "^php(@$version)? .*started"; then
        run brew services restart "php@$version"
    else
        say "  reload PHP-FPM $version yourself to apply the change"
    fi
}

install_extension() {
    local fpm="$1" version="$2"
    if command -v apt-get >/dev/null && apt-cache show "php$version-opentelemetry" >/dev/null 2>&1; then
        say "  installing php$version-opentelemetry with apt"
        run env NEEDRESTART_MODE=l DEBIAN_FRONTEND=noninteractive apt-get install -y -q "php$version-opentelemetry" >/dev/null
        # The bridge only runs under PHP-FPM; keep the CLI as it was.
        command -v phpdismod >/dev/null && run phpdismod -v "$version" -s cli opentelemetry
        [ "$dry_run" -eq 1 ] || fpm_has_extension "$fpm"
        return
    fi
    warn "PHP $version: cannot install the OpenTelemetry extension automatically."
    warn "  Install it with PECL or PIE (open-telemetry/ext-opentelemetry), enable it for PHP-FPM, then run this script again."
    return 1
}

# Puts the bridge in $PREFIX/<version> and points $PREFIX/current at it.
install_files() {
    local work version
    work="$WORK"

    if [ -n "$source_dir" ]; then
        [ -f "$source_dir/bridge/prepend.php" ] || { echo "No bridge in $source_dir" >&2; exit 1; }
        cp -R "$source_dir/bridge" "$work/bridge"
    else
        local base="https://github.com/$REPO/releases"
        local url="$base/latest/download/php-data-bridge.tar.gz"
        [ "$release" != "latest" ] && url="$base/download/v$release/php-data-bridge.tar.gz"
        say "downloading $url"
        curl -fsSL "$url" -o "$work/bridge.tar.gz"
        curl -fsSL "$url.sha256" -o "$work/bridge.tar.gz.sha256"
        (cd "$work" && echo "$(cut -d' ' -f1 bridge.tar.gz.sha256)  bridge.tar.gz" | sha256sum -c --quiet -)
        tar -xzf "$work/bridge.tar.gz" -C "$work"
    fi

    version="$(tr -d '[:space:]' < "$work/bridge/VERSION")"
    say "bridge $version -> $PREFIX/$version"
    run mkdir -p "$PREFIX/$version"
    run cp -R "$work/bridge" "$PREFIX/$version/"
    run ln -sfn "$PREFIX/$version" "$PREFIX/current"
}

# Stops before changing anything when a selected PHP-FPM version runs with JIT.
check_jit() {
    local fpm version blocked=0
    for fpm in ${FPM_BINARIES[@]+"${FPM_BINARIES[@]}"}; do
        version="$(fpm_version "$fpm")"
        [ -n "$version" ] && wanted "$version" || continue
        if fpm_jit_enabled "$fpm" "$(fpm_scan_dir "$fpm")"; then
            echo "error: PHP $version has JIT enabled. The OpenTelemetry extension would turn it off." >&2
            blocked=1
        fi
    done
    if [ "$blocked" -eq 1 ]; then
        echo "Disable JIT first (opcache.jit=disable), or leave this PHP version out with --php." >&2
        echo "The bridge is meant for development machines, not production servers." >&2
        exit 1
    fi
}

do_install() {
    local fpm version scan_dir current found=0
    check_jit
    install_files

    for fpm in ${FPM_BINARIES[@]+"${FPM_BINARIES[@]}"}; do
        version="$(fpm_version "$fpm")"
        [ -n "$version" ] && wanted "$version" || continue
        found=1
        say "PHP $version ($fpm)"

        if fpm_has_extension "$fpm"; then
            say "  OpenTelemetry extension: loaded"
        else
            install_extension "$fpm" "$version" || continue
        fi

        scan_dir="$(fpm_scan_dir "$fpm")"
        if [ -z "$scan_dir" ]; then
            warn "PHP $version: PHP-FPM has no ini folder to add to; skipped"
            continue
        fi

        # PHP takes one auto_prepend_file: leave another one alone.
        current="$(fpm_ini "$fpm" auto_prepend_file)"
        if [ -n "$current" ] && [ "$current" != "no value" ] && [ "$current" != "$PREFIX/current/bridge/prepend.php" ]; then
            warn "PHP $version: auto_prepend_file is already $current; skipped"
            continue
        fi

        say "  writing $scan_dir/$INI_NAME"
        if [ "$dry_run" -eq 0 ]; then
            printf '; PHP Data Bridge: https://github.com/%s\nauto_prepend_file = %s\n' \
                "$REPO" "$PREFIX/current/bridge/prepend.php" | run tee "$scan_dir/$INI_NAME" >/dev/null
        fi
        reload_fpm "$fpm" "$version" || true
    done

    [ "$found" -eq 1 ] || warn "no matching PHP-FPM found"
}

do_uninstall() {
    local fpm version scan_dir
    for fpm in ${FPM_BINARIES[@]+"${FPM_BINARIES[@]}"}; do
        version="$(fpm_version "$fpm")"
        [ -n "$version" ] && wanted "$version" || continue
        scan_dir="$(fpm_scan_dir "$fpm")"
        if [ -n "$scan_dir" ] && [ -f "$scan_dir/$INI_NAME" ]; then
            say "PHP $version: removing $scan_dir/$INI_NAME"
            run rm "$scan_dir/$INI_NAME"
            reload_fpm "$fpm" "$version" || true
        fi
    done

    # Only once no PHP version points at them any more.
    if [ -z "${versions[*]+x}" ]; then
        say "removing $PREFIX"
        run rm -rf "$PREFIX"
    else
        say "kept $PREFIX: other PHP versions may still use it"
    fi
    say "The OpenTelemetry extension stays installed; remove it with your package manager if nothing else uses it."
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FPM_BINARIES=()
while IFS= read -r line; do FPM_BINARIES+=("$line"); done < <(find_fpm_binaries)

if [ "$uninstall" -eq 1 ]; then do_uninstall; else do_install; fi
