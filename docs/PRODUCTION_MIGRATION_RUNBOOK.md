# Production Migration Runbook — eBiz Earn Backend

**Status:** NOT YET RUN. No production operation has been performed.
**Branch:** `quinn/spec-evolution-2026-09-22` (do NOT deploy from `main` until Dawood approves).

This runbook covers applying the Phase 2 migrations to the production MySQL
database. Read it fully before touching production.

## 0. Preconditions

- [ ] Dawood has reviewed and approved the Phase 2 backend report.
- [ ] Verified cPanel/server SSH access works.
- [ ] Production `.env` is backed up (`cp .env .env.bak.YYYY-MM-DD`).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] PHP version on server matches `composer.json` requirement (>= 8.1 for Laravel 10).
- [ ] `composer install --no-dev --optimize-autoloader` completes cleanly.

## 1. Database Backup (MANDATORY, first)

```bash
# Timestamped logical backup — keep it until the release is verified.
mysqldump -u <user> -p <database> \
  --single-transaction --routines --triggers \
  > /backups/ebizearn_pre_phase2_$(date +%F_%H%M).sql

# Verify the dump is non-empty and restorable (header check).
head -5 /backups/ebizearn_pre_phase2_*.sql
ls -lh /backups/ebizearn_pre_phase2_*.sql
```

Do NOT proceed if the backup is missing or zero bytes.

## 2. Maintenance Window

```bash
php artisan down --message="Scheduled upgrade — back shortly." --retry=60
```

Confirm the app serves the maintenance page.

## 3. Code Deploy

```bash
git fetch origin
git checkout quinn/spec-evolution-2026-09-22   # or the approved release tag
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan route:clear
```

## 4. Migration Dry Check

```bash
php artisan migrate:status
```

Review pending migrations. Phase 2 adds:

| Migration | Effect (MySQL) |
|-----------|----------------|
| `2026_09_22_000017_add_login_tracking_to_users_table` | Adds `users.last_login_ip`, `users.last_login_at`, `users.registration_ip` (nullable). |
| `2026_09_22_000018_add_pending_review_to_campaign_status` | Extends `campaigns.status` ENUM with `pending_review` (in-place `MODIFY COLUMN`). |
| `2026_09_22_000019_add_branding_to_campaigns_table` | Adds `campaigns.company_name`, `campaigns.logo_path` (nullable). |

Also note the repaired `down()` methods on `000013` and `000014` (SQLite-only
paths; MySQL behavior unchanged).

## 5. Run Migrations

```bash
php artisan migrate --force
```

Expected: all `DONE`, no exceptions. The `pending_review` ENUM alter is
instant on MySQL (metadata-only change).

## 6. Verification

```bash
# 1. Columns exist.
php artisan tinker --execute="
echo implode(',', Schema::getColumnListing('users')).PHP_EOL;
echo implode(',', Schema::getColumnListing('campaigns')).PHP_EOL;
"

# 2. Enum accepts pending_review.
php artisan tinker --execute="
DB::statement(\"SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaigns' AND COLUMN_NAME='status'\");
"

# 3. App boots.
php artisan up
curl -sf https://<prod-domain>/api/v1/config/brand | head -c 200
```

Functional smoke test (in order):
1. Register a contributor → 201.
2. Login with correct portal → 200 + token.
3. Login with wrong portal → 403.
4. Business creates + launches campaign → `pending_review`.
5. Contributor feed does NOT show the task.
6. Staff approves → `active`; feed shows the task.
7. Submit proof → 201; moderator approves → wallet credited.
8. Withdrawal below active threshold → 422.

## 7. Rollback Procedure

If migration fails or verification fails:

```bash
php artisan down
php artisan migrate:rollback --force   # rolls back the last batch
# — or, for a full restore from backup: —
mysql -u <user> -p <database> < /backups/ebizearn_pre_phase2_<ts>.sql
git checkout <previous-release-tag>
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan up
```

Then diagnose on a staging copy — never re-attempt blindly on production.

## 8. Post-Deploy

- [ ] `php artisan up` confirmed.
- [ ] Error logs clean for 15 minutes (`storage/logs/laravel.log`).
- [ ] Queue workers restarted if used (`php artisan queue:restart`).
- [ ] Scheduler running (`crontab -l` shows `schedule:run`).
- [ ] Backup retained for 30 days.

## Notes

- Never run `migrate:fresh` or `db:seed` on production.
- The Super Admin account is created manually via tinker/CLI — never seeded
  with a known password.
- Frontend and backend deploy together only after Dawood's explicit approval.
