#!/usr/bin/env bash
# Run once as root on the inspected Debian host; does not restart production.
set -Eeuo pipefail
[[ $EUID == 0 ]] || { echo 'Run as root' >&2; exit 1; }
BASE=/var/www/gym-deploy
LEGACY=/var/www/gym_management_system
[[ -f "$LEGACY/.env" && -d "$LEGACY/storage" ]]
if ! id gym-deploy > /dev/null 2>&1; then
    useradd --create-home --shell /bin/bash --gid www-data gym-deploy
fi
install -d -m 750 -o gym-deploy -g www-data "$BASE" "$BASE/incoming" "$BASE/backups"
chmod 755 "$BASE"
install -d -m 755 -o gym-deploy -g www-data "$BASE/releases"
if [[ ! -e "$BASE/current" ]]; then
    ln -s "$LEGACY" "$BASE/current"
    chown -h gym-deploy:www-data "$BASE/current"
fi
chgrp www-data "$LEGACY/.env"
chmod 640 "$LEGACY/.env"
chgrp -R www-data "$LEGACY/storage"
chmod -R g+rwX "$LEGACY/storage"
find "$LEGACY/storage" -type d -exec chmod g+s {} +
install -d -m 755 /etc/systemd/system/gym_laravel.service.d
cat > /etc/systemd/system/gym_laravel.service.d/deployment.conf <<'UNIT'
[Service]
WorkingDirectory=/var/www/gym-deploy/current
UMask=0002
UNIT
cat > /etc/sudoers.d/gym-deploy <<'SUDOERS'
gym-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl restart gym_laravel.service
SUDOERS
chmod 440 /etc/sudoers.d/gym-deploy
visudo -cf /etc/sudoers.d/gym-deploy
systemctl daemon-reload
echo 'Prepared without restarting the service. Install the dedicated public SSH key next.'