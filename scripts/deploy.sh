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

nginx_source="$deploy_path/deploy/nginx/motolotz.com.conf"
nginx_target="/etc/nginx/sites-available/motolotz.com.conf"
nginx_link="/etc/nginx/sites-enabled/motolotz.com.conf"
nginx_backup="$(mktemp)"
nginx_target_existed=false
nginx_link_existed=false
nginx_link_target=""

cleanup_nginx_backup() {
    rm -f "$nginx_backup"
}

trap cleanup_nginx_backup EXIT

restore_nginx_configuration() {
    if [ "$nginx_target_existed" = true ]; then
        sudo -n install -m 644 "$nginx_backup" "$nginx_target"
    else
        sudo -n rm -f "$nginx_target"
    fi

    if [ "$nginx_link_existed" = true ]; then
        sudo -n ln -sfn "$nginx_link_target" "$nginx_link"
    else
        sudo -n rm -f "$nginx_link"
    fi
}

test -f "$nginx_source"
sudo -n true

if sudo -n test -f "$nginx_target"; then
    sudo -n cp "$nginx_target" "$nginx_backup"
    nginx_target_existed=true
fi

if sudo -n test -e "$nginx_link" && ! sudo -n test -L "$nginx_link"; then
    echo "$nginx_link must be a symbolic link." >&2
    exit 1
fi

if sudo -n test -L "$nginx_link"; then
    nginx_link_target="$(sudo -n readlink "$nginx_link")"
    nginx_link_existed=true
fi

sudo -n install -m 644 "$nginx_source" "$nginx_target"
sudo -n ln -sfn "$nginx_target" "$nginx_link"

if ! sudo -n nginx -t; then
    restore_nginx_configuration
    sudo -n nginx -t || true
    echo "Nginx configuration validation failed; the previous configuration was restored." >&2
    exit 1
fi

if ! sudo -n systemctl reload nginx; then
    restore_nginx_configuration
    sudo -n nginx -t && sudo -n systemctl reload nginx || true
    echo "Nginx reload failed; the previous configuration was restored." >&2
    exit 1
fi

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
