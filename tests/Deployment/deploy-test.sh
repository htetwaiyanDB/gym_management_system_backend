#!/usr/bin/env bash
# Isolated deployment integration tests. No real service, network, or database.
set -Eeuo pipefail
SOURCE=$(cd "$(dirname "$0")/../.." && pwd)
TEMP=$(mktemp -d)
trap 'rm -rf "$TEMP"' EXIT
export TEST_ROOT="$TEMP"
export PATH="$TEMP/bin:$PATH"
mkdir -p "$TEMP/bin"

cat > "$TEMP/bin/php" <<'MOCK'
#!/usr/bin/env bash
echo "php $*" >> "$TEST_ROOT/events"
if [[ "$*" == 'artisan migrate --force --no-interaction' && -f "$TEST_ROOT/fail-migration" ]]; then exit 1; fi
if [[ "$1" == scripts/backup-database.php && -f "$TEST_ROOT/fail-backup" ]]; then exit 1; fi
MOCK
cat > "$TEMP/bin/sudo" <<'MOCK'
#!/usr/bin/env bash
echo "restart $(readlink "$TEST_ROOT/base/current")" >> "$TEST_ROOT/events"
MOCK
cat > "$TEMP/bin/curl" <<'MOCK'
#!/usr/bin/env bash
if [[ -f "$TEST_ROOT/fail-health" && "$(readlink "$TEST_ROOT/base/current")" != "$TEST_ROOT/legacy" ]]; then exit 22; fi
MOCK
printf '#!/usr/bin/env bash\nexit 0\n' > "$TEMP/bin/sleep"
chmod +x "$TEMP/bin/"*

setup_case() {
    rm -rf "$TEMP/base" "$TEMP/legacy" "$TEMP/package"
    rm -f "$TEMP/events" "$TEMP/fail-health" "$TEMP/fail-migration" "$TEMP/fail-backup"
    mkdir -p "$TEMP/base/"{releases,incoming,backups} "$TEMP/legacy/storage/app/public" "$TEMP/package/"{bootstrap/cache,public}
    touch "$TEMP/legacy/.env"
    ln -s "$TEMP/legacy" "$TEMP/base/current"
    ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-1-1
    mkdir "$TEMP/base/incoming/$ID"
    printf '%s\n' "${ID:0:40}" > "$TEMP/package/REVISION"
    tar -czf "$TEMP/base/incoming/$ID/release.tar.gz" -C "$TEMP/package" .
    sed -e "s|^BASE=.*|BASE=$TEMP/base|" -e "s|^LEGACY=.*|LEGACY=$TEMP/legacy|" "$SOURCE/scripts/deploy.sh" > "$TEMP/deploy.sh"
}

setup_case
if bash "$TEMP/deploy.sh" '../../invalid' > /dev/null 2>&1; then echo 'Invalid release ID accepted'; exit 1; fi
[[ "$(readlink "$TEMP/base/current")" == "$TEMP/legacy" ]]
echo 'PASS: invalid release rejected'

setup_case
bash "$TEMP/deploy.sh" "$ID"
[[ "$(readlink "$TEMP/base/current")" == "$TEMP/base/releases/$ID" ]]
[[ "$(readlink "$TEMP/base/releases/$ID/.env")" == "$TEMP/legacy/.env" ]]
[[ "$(readlink "$TEMP/base/releases/$ID/storage")" == "$TEMP/legacy/storage" ]]
grep -q 'artisan queue:restart' "$TEMP/events"
echo 'PASS: successful deployment preserves environment and uploads'

for failure in backup migration health; do
    setup_case
    touch "$TEMP/fail-$failure"
    if bash "$TEMP/deploy.sh" "$ID" > "$TEMP/output" 2>&1; then
        echo "Expected $failure failure"; exit 1
    fi
    [[ "$(readlink "$TEMP/base/current")" == "$TEMP/legacy" ]]
    if [[ "$failure" == backup ]]; then
        if grep -q 'artisan migrate' "$TEMP/events"; then
            echo 'Migration ran after a failed backup'; exit 1
        fi
    fi
    if [[ "$failure" == health ]]; then
        [[ "$(grep -c '^restart ' "$TEMP/events")" == 2 ]]
        grep -q 'Previous application restored' "$TEMP/output"
    else
        if grep -q '^restart ' "$TEMP/events"; then
            echo 'Service restarted before successful activation'; exit 1
        fi
    fi
    echo "PASS: $failure failure leaves/restores previous release"
done