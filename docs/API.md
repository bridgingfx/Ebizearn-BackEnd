# eBiz Earn API — v1 Reference

Base URL: `/api/v1` (113 routes, verified 2026-09-23 — every route resolves to a real controller method).
Auth: Laravel Sanctum Bearer tokens (`Authorization: Bearer <token>`).
All responses are JSON with `success: true|false`.

## Authentication & Portals

| Method | Endpoint | Auth | Notes |
|--------|----------|------|-------|
| POST | `/auth/register` | Public | `role: contributor|business`. Business requires `company_name`. |
| POST | `/auth/login` | Public | `email`, `password`, `portal: contributor|business|moderator|superadmin`. Wrong portal → 403 before token issuance. |
| POST | `/auth/logout` | Bearer | Revokes current token. |
| GET | `/auth/me` | Bearer | Current user with profile, wallet, business. |
| POST | `/auth/forgot-password` | Public | Sends reset link. |
| POST | `/auth/reset-password` | Public | Resets with token. |

**Risk telemetry (Phase 2):** every login writes a `fraud_events` row. A device fingerprint never seen for the user is flagged `new_device_login` (medium/flagged) for moderator review; repeat devices log as `login` (low/reviewed). Registration records `registration_ip`; 3+ accounts from one IP in 30 days flags `multi_account` (medium). Telemetry never blocks auth and never claims automated verdicts.

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
