#!/usr/bin/env bash
set -Eeuo pipefail

deploy_path="${1:?Deployment path is required.}"

test -f "$deploy_path/.env"
test -f "$deploy_path/compose.deploy.yml"
test -n "${IMAGE_NAME:-}"
test -n "${IMAGE_DIGEST:-}"
alloy_nginx_log_gid="$(getent group adm | cut -d: -f3)"

case "$alloy_nginx_log_gid" in
    ''|*[!0-9]*)
        echo 'The host adm group is required to read Nginx logs.' >&2
        exit 1
        ;;
esac

export ALLOY_NGINX_LOG_GID="$alloy_nginx_log_gid"


cd "$deploy_path"
export IMAGE_NAME IMAGE_DIGEST

docker compose -f compose.deploy.yml pull
docker compose -f compose.deploy.yml up -d --remove-orphans
docker compose -f compose.deploy.yml exec -T app php artisan migrate --force

for attempt in $(seq 1 12); do
    if curl --fail --silent --max-time 10 http://127.0.0.1:8000/up >/dev/null; then
        exit 0
    fi

    sleep 5
done

docker compose -f compose.deploy.yml ps
docker compose -f compose.deploy.yml logs --tail=100 app
exit 1
