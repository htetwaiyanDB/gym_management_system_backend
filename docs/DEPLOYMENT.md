# Production CI/CD

## What runs

The workflow in `.github/workflows/ci-cd.yml` verifies pull requests to `main`.
Pushes to `main` (or a manual run on `main`) deploy **only after verification passes**.
No production credentials are supplied to pull-request verification jobs.

Verification uses PHP 8.2, Node 22, locked Composer/npm dependencies, PHP lint,
ShellCheck, isolated deployment integration tests, the complete Pest suite,
frontend compilation, MySQL 8 migration checks, and Laravel cache compilation.
The deployment artifact contains built assets and production-only PHP dependencies,
but no `.env`, uploads, Git metadata, or SSH credentials.

Actions are pinned to commit SHAs. Update those pins deliberately through reviewed
changes. Production jobs are serialized, superseded builds are rejected, and the
server takes an additional `flock` lock.

## Inspected production configuration

- Host: `47.79.35.105`, Debian 12, PHP 8.2, MariaDB.
- HTTPS: `https://api.unityfitnessmyanmar.online`.
- Nginx proxies to `127.0.0.1:6060`.
- Service: `gym_laravel.service`, application user `admin`, group `www-data`.
- Original checkout: `/var/www/gym_management_system`.
- Deployment root: `/var/www/gym-deploy`.
- Active application: `/var/www/gym-deploy/current`.
- Persistent environment: `/var/www/gym_management_system/.env`.
- Persistent files: `/var/www/gym_management_system/storage`.

The original checkout has local changes and is intentionally retained, not reset.
Do **not** remove that checkout: it still owns the shared environment and uploads.
Application releases live under `/var/www/gym-deploy/releases/COMMIT-RUN-ATTEMPT`.

The existing `artisan serve` architecture is preserved to avoid silently replacing
the hosting stack. It restarts briefly per deployment; this is **not zero downtime**.
Migrating the gym vhost to Nginx/PHP-FPM should be a separate, tested change.

## Access configured during implementation

`scripts/prepare-production.sh` was executed on the server as root. It:

- Created `gym-deploy` with `www-data` group membership.
- Prepared release/incoming/backup directories and an initial `current` link to
  the original checkout.
- Restricted the production `.env` to owner/group access.
- Enabled shared group writes to persistent storage, with setgid directories.
- Added a systemd drop-in to use the `current` link, with shared-write umask.
- Granted only `sudo systemctl restart gym_laravel.service`, not unrestricted sudo.
- Reloaded systemd configuration **without restarting the application**.

A dedicated Ed25519 public key was installed with OpenSSH's `restrict` option.
The private key was encrypted directly into the GitHub Actions repository secret
`PRODUCTION_SSH_KEY`; the verified server host key was saved as
`PRODUCTION_KNOWN_HOSTS`. Neither private key nor root password belongs in Git.
The canonical GitHub repository is `htetwaiyanDB/gym_management_system_backend`;
the older `gym_management_system` URL redirects there.

**Rotate the root password shared in the setup request.** No automatic root
password rotation was performed, to avoid locking the operator out.

The available account cannot administer repository protection settings. An owner
should enable branch protection, require `Test and build`, restrict the `production`
environment to `main`, and optionally move the two deployment secrets into that
environment. Required reviewers are optional: enabling them pauses automatic
deployment until approval. Do not grant untrusted contributors workflow-editing
access; repository-level secrets are available to trusted workflow runs.

## Deployment and rollback

1. Download the exact verified artifact and verify SHA-256 before and after upload.
2. Extract a new release; attach shared `.env`, storage, and public storage links.
3. Compile release-local route/config caches.
4. Create a private, compressed MySQL/MariaDB backup. Credentials use a private
   temporary option file, not command arguments. Abort if backup fails.
5. Run `php artisan migrate --force`.
6. Atomically change `current`, restart only the gym service, and check `/up`
   using both loopback HTTPS with the correct hostname and public DNS.
7. Restore the previous application release and restart if activation/health fails.
8. Signal queue workers to restart; the inspected production queue driver is `sync`.

The runner performs an additional external HTTPS smoke check. If this last check
fails after the server-side checks passed, investigate DNS/network reachability;
this outside-runner failure does not automatically undo a finalized deployment.

`/up` verifies Laravel boot, **not full database/business-function readiness**.
The backup and migration steps independently check database access. Add application
readiness checks separately if stronger traffic-admission guarantees are needed.

### Database safety

Backups are stored in `/var/www/gym-deploy/backups` with private permissions.
Only backward-compatible expand/contract migrations should ship automatically.
MySQL DDL may commit partially: an application rollback does **not** reverse schema
changes, and a failing migration may already have changed the database.
Never run automatic `migrate:rollback` or automatically restore a backup over live
customer writes. Review destructive migrations and take a maintenance window.

To recover the database, first stop writes, inspect the migration error, preserve a
fresh incident backup, and select the correct pre-deployment archive. Validate it
with `gzip -t`, restore it into a **separate recovery database**, verify the data and
matching application revision, then have an operator perform the planned cutover.
The pipeline does not claim that an archive alone constitutes a tested restore.

### Manual application rollback (root)

Choose an existing known-good release or the original checkout. Preserve the
current target in the incident notes, and ensure its code still supports the schema.

```bash
readlink -f /var/www/gym-deploy/current
ls -lt /var/www/gym-deploy/releases
# Set PREVIOUS to the reviewed absolute release path before running these commands.
test -n "$PREVIOUS" && test -f "$PREVIOUS/artisan"
ln -s "$PREVIOUS" /var/www/gym-deploy/current.manual
mv -Tf /var/www/gym-deploy/current.manual /var/www/gym-deploy/current
systemctl restart gym_laravel.service
curl --fail https://api.unityfitnessmyanmar.online/up
journalctl -u gym_laravel.service -n 100 --no-pager
```

Monitor disk space and deliberately prune old releases, failed incoming uploads,
and backups after retention requirements are agreed. Automated cleanup is omitted
to avoid deleting the only known-good rollback or recovery archive. Keep encrypted
off-host backups as a separate operational control.

## Validation

Local frontend compilation and the new `/up` feature test pass. Isolated Linux
deployment tests cover invalid IDs, successful activation, backup failure,
migration failure, and failed-health rollback. Tests mock PHP, services and network;
they do not restart production or restore a real database.
The backup helper was also run against the real production database as `gym-deploy`:
it produced a private compressed archive and passed `gzip -t`. No restore or schema
change was performed. ShellCheck is configured in CI but could not be executed
locally because downloading its binary timed out.

The initial application suite exposed four existing authentication/profile failures.
The follow-up fix aligns web login with the `identifier` field, imports registration's
password rule, restores the signed-link verification notice, and asserts soft deletion.
Registration tests now exercise the existing CAPTCHA and email OTP flow rather than
assuming immediate authentication. The OTP form no longer displays the secret code.
Additional tests cover role-specific login identifiers, missing/incorrect CAPTCHA,
and incorrect/expired verification codes. CAPTCHA is not disabled to make CI green.
Web login also rejects unverified member/trainer accounts so it cannot bypass the
registration OTP flow. Administrator login retains its existing behavior.

Use the Actions run logs to confirm verification and activation. A push does not
guarantee deployment: any failed validation or health check blocks success.