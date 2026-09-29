#!/usr/bin/env bash
# Run only disposable local images: no pull, published ports, or production DB.
# Usage: FastLookupIntegration.sh CAKE_DIR [integration|contract|scale]
# MISP_FASTLOOKUP_BACKEND=redis|valkey picks the server; MISP_REDIS_IMAGE
# overrides its image. FL_* variables reach the scale runner.
set -euo pipefail
usage='Usage: FastLookupIntegration.sh /path/to/cakephp/lib/Cake'
usage+=' [integration|contract|scale]'
test_checkout=$(cd "$(dirname "$0")/../.." && pwd)
cake_source=$(cd "${1:?$usage}" && pwd)
mode=${2:-integration}
case "$mode" in
    integration)
        runner=(tests/benchmarks/FastLookupIntegration.php
            /cake /mysql/mysql.sock /redis/redis.sock)
        memory=512M ;;
    contract)
        runner=(tests/benchmarks/FastLookupFilterRedisContract.php
            /redis/redis.sock)
        memory=512M ;;
    scale)
        runner=(tests/benchmarks/FastLookupScale.php
            /cake /mysql/mysql.sock /redis/redis.sock)
        memory=6G ;;
    *) echo "$usage" >&2; exit 2 ;;
esac
backend=${MISP_FASTLOOKUP_BACKEND:-redis}
case "$backend" in
    redis) default_redis_image=docker.io/library/redis:8.2 ;;
    valkey) default_redis_image=docker.io/valkey/valkey-bundle:8.1 ;;
    *) echo 'MISP_FASTLOOKUP_BACKEND must be redis or valkey.' >&2; exit 2 ;;
esac
php_image=${MISP_PHP_IMAGE:-localhost/misp-live:tmp}
db_image=${MISP_MARIADB_IMAGE:-docker.io/library/mariadb:10.11}
redis_image=${MISP_REDIS_IMAGE:-$default_redis_image}
test_dir=$(mktemp -d "${TMPDIR:-/tmp}/misp-fastlookup.XXXXXXXX")
db_container="misp-fastlookup-db-${test_dir##*.}"
redis_container="misp-fastlookup-redis-${test_dir##*.}"
php_container="misp-fastlookup-php-${test_dir##*.}"
cleanup() {
    podman rm --force "$php_container" "$redis_container" "$db_container" \
        >/dev/null 2>&1 || true
    rm -rf "$test_dir"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 755 "$test_dir"
mkdir "$test_dir/mysql" "$test_dir/redis"
chmod 777 "$test_dir/mysql" "$test_dir/redis"
podman run -d --pull=never --name "$db_container" --network=none \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
    --tmpfs "/var/lib/mysql:rw,size=${MISP_MARIADB_TMPFS:-512m}" \
    -v "$test_dir/mysql:/run/mysqld" "$db_image" \
    --skip-networking --socket=/run/mysqld/mysql.sock >/dev/null
# Given options only, both images' entrypoints start the server with their
# bundled modules; an explicit --loadmodule would load one twice and abort.
podman run -d --pull=never --name "$redis_container" --network=none \
    -v "$test_dir/redis:/socket" "$redis_image" \
    --port 0 --unixsocket /socket/redis.sock --unixsocketperm 777 \
    --save '' --appendonly no >/dev/null
cli='cli=$(command -v valkey-cli || command -v redis-cli) && "$cli"'
cli+=' -s /socket/redis.sock'
ready=false
for attempt in {1..45}; do
    # Ignore the temporary server used during MariaDB initialization.
    if podman exec "$db_container" sh -c 'test "$(cat /proc/1/comm)" = mariadbd && mariadb-admin --socket=/run/mysqld/mysql.sock ping --silent' >/dev/null 2>&1 && \
        podman exec "$redis_container" sh -c "$cli ping" >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [[ "$ready" != true ]]; then
    podman logs "$db_container"
    podman logs "$redis_container"
    exit 1
fi
echo "fastLookup backend: $backend ($redis_image)" >&2
podman exec "$redis_container" sh -c "$cli INFO server" \
    | grep -E '^(redis|valkey)_version:' >&2 || true
env_args=()
while IFS= read -r name; do
    case "$name" in FL_OUT|FL_CPU_STAT) continue ;; esac
    env_args+=(-e "$name=${!name}")
done < <(compgen -e | grep '^FL_' || true)
podman run --rm --pull=never --name "$php_container" --network=none \
    --entrypoint php "${env_args[@]}" \
    -v "$test_checkout:/work:ro" -v "$cake_source:/cake:ro" \
    -v "$test_dir/mysql:/mysql" -v "$test_dir/redis:/redis" -w /work \
    "$php_image" -d auto_prepend_file= -d pcov.enabled=0 \
    -d memory_limit="$memory" "${runner[@]}"
