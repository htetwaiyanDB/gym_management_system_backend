#!/usr/bin/env bash
set -Eeuo pipefail
umask 0002

# These paths match the inspected production host, not an in-place git checkout.
BASE=/var/www/gym-deploy
LEGACY=/var/www/gym_management_system
HEALTH_URL=https://api.unityfitnessmyanmar.online/up
SERVICE=gym_laravel.service
RELEASE_ID=${1:?Usage: deploy.sh COMMIT-RUN-ATTEMPT}
[[ "$RELEASE_ID" =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || { echo 'Invalid release ID' >&2; exit 1; }

exec 9>"$BASE/deploy.lock"
flock -w 600 9 || { echo 'Another deployment holds the lock' >&2; exit 1; }

RELEASE="$BASE/releases/$RELEASE_ID"
INCOMING="$BASE/incoming/$RELEASE_ID"
PREVIOUS=$(readlink -f "$BASE/current")
[[ -d "$PREVIOUS" && -f "$LEGACY/.env" && -d "$LEGACY/storage" ]]
[[ ! -e "$RELEASE" ]] || { echo 'Release already exists' >&2; exit 1; }
activated=false

switch_release() {
    local link="$BASE/current.$RELEASE_ID.$RANDOM"
    ln -s "$1" "$link" || return 1
    mv -Tf "$link" "$BASE/current" || return 1
}

check_health() {
    local attempt
    for attempt in {1..12}; do
        echo "Checking application health ($attempt/12)"
        if curl --fail --silent --show-error --connect-timeout 5 --max-time 10 \
            --resolve api.unityfitnessmyanmar.online:443:127.0.0.1 "$HEALTH_URL" > /dev/null \
            && curl --fail --silent --show-error --connect-timeout 5 --max-time 10 "$HEALTH_URL" > /dev/null; then
            return 0
        fi
        sleep 3
    done
    return 1
}

rollback() {
    local status=${1:-1}
    trap - ERR EXIT HUP INT TERM
    if [[ "$activated" == true ]]; then
        echo 'Activation failed; restoring previous application release.' >&2
        if switch_release "$PREVIOUS" && sudo -n /usr/bin/systemctl restart "$SERVICE" && check_health; then
            echo 'Previous application restored. Database migrations were NOT reversed.' >&2
        else
            echo 'CRITICAL: rollback failed; inspect gym_laravel.service immediately.' >&2
        fi
    fi
    exit "$status"
}
trap 'rollback "$?"' ERR
trap 'exit 130' INT
trap 'exit 143' HUP TERM
# EXIT also catches a lost SSH connection after activation.
trap 'status=$?; if (( status != 0 )) && [[ "$activated" == true ]]; then rollback "$status"; fi' EXIT

mkdir -m 755 "$RELEASE"
tar -xzf "$INCOMING/release.tar.gz" -C "$RELEASE" --no-same-owner
[[ "$(cat "$RELEASE/REVISION")" == "${RELEASE_ID:0:40}" ]]
ln -s "$LEGACY/.env" "$RELEASE/.env"
ln -s "$LEGACY/storage" "$RELEASE/storage"
ln -s "$LEGACY/storage/app/public" "$RELEASE/public/storage"
chmod -R u=rwX,g=rX,o=rX "$RELEASE"
chmod -R ug=rwX,o= "$RELEASE/bootstrap/cache"

cd "$RELEASE"
php artisan config:cache
php artisan route:cache
# Blade cache is shared with the running application; don't clear it here.
# Backup must succeed before any production schema changes.
php scripts/backup-database.php "$BASE/backups/$RELEASE_ID.sql.gz"
php artisan migrate --force --no-interaction

activated=true
switch_release "$RELEASE"
sudo -n /usr/bin/systemctl restart "$SERVICE"
check_health
php artisan queue:restart
activated=false
trap - ERR EXIT HUP INT TERM
rm -f "$INCOMING/release.tar.gz" "$INCOMING/release.tar.gz.sha256"
echo "Deployed $RELEASE_ID successfully. Previous release: $PREVIOUS"
# Retain releases and backups for deliberate operator-controlled recovery.