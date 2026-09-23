# eBiz Earn API — v1 Reference

Base URL: `/api/v1` (113 routes — includes new `POST /auth/otp/send` + `POST /auth/otp/verify`).
Auth: Laravel Sanctum Bearer tokens (`Authorization: Bearer <token>`).
All responses are JSON with `success: true|false`. Error responses also carry a stable machine-readable `code` (`validation_error`, `unauthenticated`, `forbidden`, `not_found`, `rate_limited`, `server_error`, …).

## Authentication & Portals

| Method | Endpoint | Auth | Notes |
|--------|----------|------|-------|
| POST | `/auth/register` | Public | `role: contributor|business`. Business requires `company_name`. **Requires `phone_country_code` (real dial code, allow-list in `config/phone.php`) + `phone_number` (4–15 digits)** — stored as E.164 `users.phone`. Throttled 5/min per IP. Strong password: min 10 chars + upper/lower/digit/symbol. Accounts start **`pending_verification`** — NO token is issued; a 6-digit OTP is emailed. Verify via `/auth/otp/verify` to activate and get a token. |
| POST | `/auth/login` | Public | `email`, `password`, `portal: contributor|business|moderator|superadmin`. Wrong portal → 403 before token issuance. Accounts still `pending_verification` → 403 `email_unverified` (pre-existing `active` accounts, even if unverified, are unaffected). Throttled 5/min per email+IP. Failed attempts logged to `fraud_events`; 5+ failures from one IP in 10 min flags `rapid_failed_logins`. |
| POST | `/auth/social/{provider}` | Public | `provider: google|apple`. Body: `id_token` (+ optional `name`, `email` fallback). ID token verified server-side (JWKS signature, `aud`, `exp`, `iss`). Find-or-create by `(provider, sub)`; verified provider email links to an existing account. New accounts are contributors. Throttled 10/min per IP. Requires real `GOOGLE_CLIENT_ID` / `APPLE_CLIENT_ID` in env (Dawood-side setup) — placeholder values fail closed with 401. |
| POST | `/auth/logout` | Bearer | Revokes current token. |
| GET | `/auth/me` | Bearer | Current user with profile, wallet, business. Includes `email_verified` boolean. |
| POST | `/auth/forgot-password` | Public | Sends reset link. Throttled. |
| POST | `/auth/reset-password` | Public | Resets with token. New password must meet the strong-password policy. |
| POST | `/auth/email/verify` | Public | Body: `token` (from the verification email URL). Marks `email_verified_at`; single-use, 24h expiry. Legacy flow — kept for pre-existing accounts; new signups use OTP below. |
| POST | `/auth/email/resend` | Bearer | Re-sends the verification email (regenerates token). Unverified users only; throttled 3/min. |
| POST | `/auth/otp/send` | Public | Body: `email`. Sends a fresh 6-digit code (invalidates older ones). 60s resend cooldown, max 5 sends/hour per email AND per IP. Machine codes: `not_found` (404), `already_verified` (409), `cooldown`/`rate_limited` (429), `email_failed` (503). |
| POST | `/auth/otp/verify` | Public | Body: `email`, `code` (6 digits). On success: sets `email_verified_at`, flips `pending_verification` → `active`, issues Sanctum token (user logged in). Code is bcrypt-hashed at rest, 10-min expiry, max 5 attempts then invalidated. Machine codes: `invalid` (422), `expired` (410), `too_many_attempts` (429), `not_found` (404), `already_verified` (409). |

**Email verification (signup hardening):** new email signups collect a phone number and verify via OTP before the account activates — no token at registration, and password login answers `403 { code: "email_unverified" }` while pending. The legacy token-link flow (`/auth/email/verify`, 24h) is kept for pre-existing accounts. Google OAuth users are unaffected (provider-verified emails need no OTP). Gated routes — contributor dashboard, wallet actions, task start/submit, campaign create/fund/draft/launch, business task writes — answer `403 { code: "email_not_verified" }` for unverified users.

**SMTP requirement:** OTP emails go through Laravel's mailer. If mail cannot be delivered, registration FAILS LOUDLY with `503 { code: "email_failed" }` and rolls back (no half-created account). Production MUST set `MAIL_MAILER=smtp` + `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION` + `MAIL_FROM_ADDRESS/NAME` (see `.env.example`) — without working SMTP, signup cannot complete.

**Email verification (Round 2, legacy):** pre-existing unverified accounts can still use the branded HTML + plain-text verification email with a 24h token link to `{FRONTEND_URL}/verify-email?token=…`. Only the token's SHA-256 digest is stored. Password login is gated ONLY for accounts created via the new OTP signup flow while they are still `pending_verification` (403 `email_unverified`); older `active` accounts log in regardless of verification state (money/task write paths stay gated until verified).

**Risk telemetry (Phase 2 + Round 2):** every login writes a `fraud_events` row. A device fingerprint never seen for the user is flagged `new_device_login` (medium/flagged) for moderator review; repeat devices log as `login` (low/reviewed). Registration records `registration_ip`; 3+ accounts from one IP in 30 days flags `multi_account` (medium). Round 2 adds: `failed_login` rows on every bad credential (low/reviewed), `rapid_failed_logins` when 5+ failures come from one IP in 10 minutes (medium/flagged), and `country_mismatch` when the Cloudflare country header disagrees with the profile country (medium/flagged). Telemetry never blocks auth and never claims automated verdicts.

## Public

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/tasks` | Published task feed. Only `available` tasks of `active` campaigns (approval gate). Filters: `category`, `search`, `difficulty`, `sort`. |
| GET | `/tasks/{id}` | Task detail with campaign guidelines + proof requirements. |
| GET | `/task-categories` | Active categories. |
| GET | `/task-types` | Task types with reward bands + proof requirements. |
| GET | `/config/brand` | Public brand config. |
| POST | `/demo-requests` | Public demo request form. |

## Contributor (`role:contributor`)

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/contributor/dashboard` | Earnings summary, tier, stats. |
| GET | `/contributor/my-tasks` | Assignments + submissions (`?status=`). |
| GET | `/contributor/referrals` | Referral code + list. |
| GET | `/contributor/referrals/earnings` | Referral earnings breakdown. |
| GET | `/contributor/referrals/tree` | 3-level referral tree. |
| POST | `/tasks/{id}/start` | Reserve a slot (idempotent per user). |
| POST | `/tasks/{id}/submit` | Submit proof: `proof_url`, `proof_screenshot` (base64 → stored to disk; URL → kept), `text_answer`. Returns 201. Duplicate → 409. |
| GET | `/wallet` | Balances. |
| GET | `/wallet/breakdown` | Pending / Under Review / Available / Paid / Rejected. |
| GET | `/wallet/transactions` | Immutable ledger history. |
| POST | `/wallet/withdraw` | `amount_cents`, `payout_method`, `payout_details`. Enforces active Super-Admin withdrawal rule ($10/$25/$50/$100). Below threshold → 422. |
| POST | `/profile/avatar` | Upload avatar image. |
| DELETE | `/profile/avatar` | Remove avatar. |

## Business (`role:business`)

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/business/dashboard` | Spend, campaigns, submissions overview. |
| GET | `/business/analytics` | Campaign performance. |
| GET | `/business/campaigns` | Own campaigns. |
| POST | `/business/campaigns` | Create + fund in one step (escrow hold + platform fee debit). Lands in `pending_review`. |
| GET | `/business/campaigns/{id}` | Campaign detail. |
| PATCH | `/business/campaigns/{id}/status` | Pause/resume own campaign. |
| POST | `/business/campaigns/{id}/fund` | Add funds to a draft. |
| POST | `/business/campaigns/{id}/logo` | Upload campaign logo (png/jpg/webp/svg ≤2MB) + `company_name`. Stored on public disk. |
| POST | `/business/campaigns/wizard/preview` | Honest cost preview: `reward_cents`, `contributors`, `task_type_key`. Moves no money. |
| POST | `/business/campaigns/wizard/draft` | Persist draft. Fields: `title`, `task_title`, `objective`, `description`, `category_id`, `task_type_key`, `platform`, `country_code`, `instructions`, `proof_requirements[]`, `reward_cents` (reward-band enforced), `contributors`, `retention_days`, `estimated_minutes`, `difficulty`, `countries[]`, `languages[]`, `min_contributor_level`. |
| POST | `/business/campaigns/{id}/launch` | Atomic: escrow hold + fee debit + task pool. → `pending_review`. Double launch → 409. |
| GET | `/business/tasks` | Own tasks. |
| POST | `/business/tasks` | Create task (legacy single-task flow). |
| GET/PATCH/DELETE | `/business/tasks/{id}` | Manage own tasks. |
| GET | `/business/submissions` | Submissions on own campaigns. |

**Campaign lifecycle:** `draft` → `launch` → `pending_review` → staff `active` (tasks visible) / `cancelled` (escrow released). `active` ↔ `paused`. Tasks of non-`active` campaigns never appear in the contributor feed.

## Moderator (`role:moderator,admin,superadmin` + `permission:review_submissions`)

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/moderator/verification-queue` | Submissions awaiting review. |
| GET | `/moderator/submissions/{id}` | Submission detail with files + AI result. |
| POST | `/moderator/submissions/{id}/decision` | `decision: approved|rejected|action_required` ("Request revision"), `reason_code` (from catalog per decision), `notes` (3–1000 chars). Writes `reviewer_id`, `reviewed_at`, `review_reason_code`, `review_notes` + `audit_logs` (`submission.{decision}`). |
| GET | `/moderator/fraud-alerts` | Flagged `fraud_events`. |

**Reason codes:**
- `approved`: `verified`, `meets_requirements`
- `rejected`: `duplicate_proof`, `fake_submission`, `wrong_url`, `missing_requirements`, `multiple_accounts`, `policy_violation`, `other`
- `action_required`: `needs_better_proof`, `needs_clarification`

Decisions are idempotent (repeat same decision = no-op). Reject-after-approve reverses the ledger credit with compensating entries — never double-credits.

## Staff Campaign Management (`role:moderator,admin,superadmin` + `permission:manage_task_templates`)

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/staff/campaigns` | Cross-tenant campaign list (`?status=`). |
| GET | `/staff/campaigns/{id}` | Campaign detail. |
| PATCH | `/staff/campaigns/{id}/status` | `active|paused|cancelled`. Also `pending_review` → `active` (approve, publishes tasks) or `cancelled` (reject, releases escrow). Writes `audit_logs` (`campaign.status_changed`). |
| DELETE | `/staff/campaigns/{id}` | Drafts only, never after money moved. |
| GET/POST | `/staff/tasks` | Cross-tenant task list / create. |
| PATCH/DELETE | `/staff/tasks/{id}` | Manage any task. |

## Admin (`role:admin,superadmin`)

Verification queue, submission decisions, fraud alerts, payouts, referral overview — same controllers as moderator routes (see above), plus:

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/admin/dashboard` | Platform overview. |
| GET | `/admin/users` | User list. |
| PATCH | `/admin/users/{id}/status` | Activate/suspend. |
| GET | `/admin/audit-logs` | Audit trail. |
| GET | `/admin/feature-flags` / PATCH | Feature flags. |
| GET/PATCH | `/admin/system-settings` | System settings. |
| GET | `/admin/health` | Service health. |
| GET | `/admin/demo-requests` | Demo request triage. |
| GET | `/admin/referrals/overview` | Referral ledger overview (totals, per-level, recent). |
| GET | `/admin/referral-rules` | Affiliate rules per level (flat cents + percent bps, 10%/5%/2% defaults). |
| PATCH | `/admin/referral-rules` | Update rules: `levels: [{level, reward_mode: flat\|percent, reward_cents?, percent_bps?, is_enabled?}]`. Audited; future qualifications only. |
| Email providers/templates, payment gateways/logs | | Standard CRUD + test/activate. |

**Idempotency:** every money-moving endpoint (`/wallet/withdraw`, `/admin/payouts/{id}/process`, `/business/campaigns`, `/business/campaigns/{id}/fund`, `/business/campaigns/{id}/launch`) accepts an `Idempotency-Key` header (or `idempotency_key` body field). A retry with the same key + identical parameters returns the original result; the same key with different parameters is rejected with 400.

## Super Admin (`role:superadmin`, `/ops`)

Private, manually created (no public registration, no seeded password).

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET/POST | `/ops/admins` | Manage admin users. |
| PATCH | `/ops/admins/{id}/permissions` | Grant/revoke permissions. |
| GET | `/ops/permissions` | Permission catalog. |
| GET | `/ops/audit-logs` | Full audit trail. |
| GET/PATCH | `/ops/platform-settings` | Platform settings. |
| GET/POST/PATCH | `/ops/countries` | Country allow-list. |
| GET/POST/PATCH | `/ops/task-categories` | Task categories. |
| GET/PATCH | `/ops/task-types` | Task types + reward bands. |
| GET/POST | `/ops/withdrawal-rules` | Withdrawal thresholds ($10/$25/$50/$100). |
| POST | `/ops/withdrawal-rules/{id}/activate` | Activate one rule (single active). |

## Roles & Permissions

- **Roles:** `contributor`, `business`, `moderator`, `admin`, `superadmin` (column `users.role`).
- **Middleware:** `auth:sanctum` → `EnsureRole` → `EnsurePermission` (granular, on moderator/staff routes).
- **Portal gate:** login `portal` must match `users.role` or one of the user's portal contexts; mismatch → 403, no token.
- **Super Admin** implicitly holds every permission; manually created only.

## Money Rules

- All money in integer cents; ledger is immutable (`wallet_transactions`).
- Approval credits `task_reward`; referral rewards pay 3 levels on first approval only.
- Referral rewards are admin-controllable per level (`/admin/referral-rules`): flat amounts (default L1 $1.00 / L2 $0.50 / L3 $0.25) or a percent of the referee's first approved task reward (10% / 5% / 2% ready). Rule changes never rewrite already-paid rewards.
- Retention: approved rewards can enter `pending` (task-specific `retention_days`) before becoming available.
- Withdrawals move available → pending; threshold enforced from the active `withdrawal_rules` row.
- No manual balance edits without an audited ledger row.
- No stack traces or internals leak to API clients in production (500 → generic `Server Error` when `APP_DEBUG=false`); validation failures always answer `{success:false, message, errors}`.
