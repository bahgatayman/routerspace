# Link Space Customer API — v1

Public, **read-only**, **stateless** JSON API for the Link Space website (`link-space.net`) and mobile app.
It lives in the Panel backend (`panel.link-space.net`) so there is one source of truth; owner and Super Admin
features stay in the Panel (`routes/web.php`) and are not reachable through `/api`.

Not in v1 (by design): customer accounts/tokens, bookings, availability, payments.

---

## Endpoints

### `GET /api/v1/workspaces` — directory

Published workspaces that pass the publication checks **right now** (see *Eligibility*), newest first.

| Query | Type | Default | Rules |
|---|---|---|---|
| `page` | int | 1 | 1 – 10000 |
| `per_page` | int | 12 | 1 – 50 |
| `lang` | `en` \| `ar` | `Accept-Language`, else `en` | other values ignored |

Unknown parameters are ignored.

```http
GET /api/v1/workspaces?per_page=2 HTTP/1.1
Accept: application/json
```

```json
{
  "data": [
    {
      "slug": "contract-hub",
      "name": "Contract Hub",
      "city": "Cairo",
      "address": "10 Tahrir St",
      "coordinates": { "latitude": 30.04, "longitude": 31.23 },
      "description": "Quiet coworking with fast Wi-Fi.",
      "primary_image": {
        "url": "https://panel.link-space.net/storage/workspace-images/<random>.jpg",
        "thumbnail_url": "https://panel.link-space.net/storage/workspace-images/<random>-thumb.webp",
        "is_primary": true
      },
      "rooms_count": 2,
      "starting_price": { "amount": 40, "currency": "EGP" }
    }
  ],
  "links": {
    "first": "https://panel.link-space.net/api/v1/workspaces?per_page=2&page=1",
    "last": "https://panel.link-space.net/api/v1/workspaces?per_page=2&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1, "from": 1, "last_page": 1,
    "links": [ { "url": null, "label": "&laquo; Previous", "active": false }, "…" ],
    "path": "https://panel.link-space.net/api/v1/workspaces",
    "per_page": 2, "to": 1, "total": 1
  }
}
```

### `GET /api/v1/workspaces/{slug}` — details

```http
GET /api/v1/workspaces/contract-hub HTTP/1.1
Accept: application/json
```

Exact shape (asserted by `PublicWorkspaceApiTest::test_exact_detail_contract`; URLs abbreviated):

```json
{
  "data": {
    "slug": "contract-hub",
    "name": "Contract Hub",
    "city": "Cairo",
    "address": "10 Tahrir St",
    "coordinates": { "latitude": 30.04, "longitude": 31.23 },
    "description": "Quiet coworking with fast Wi-Fi.",
    "primary_image": { "url": "https://…/main.jpg", "thumbnail_url": "https://…/main-thumb.webp", "is_primary": true },
    "rooms_count": 2,
    "starting_price": { "amount": 40, "currency": "EGP" },
    "images": [
      { "url": "https://…/main.jpg", "thumbnail_url": "https://…/main-thumb.webp", "is_primary": true },
      { "url": "https://…/second.jpg", "thumbnail_url": "https://…/second.jpg", "is_primary": false }
    ],
    "rooms": [
      { "id": "3c4fc2cf-1b9c-472d-bfce-485142b637aa", "name": "Board Room", "type": "meeting", "type_label": "Meeting Room", "capacity": 8,
        "price": { "amount": 150, "currency": "EGP", "is_starting_price": false, "display": "EGP 150.00/ hr" } },
      { "id": "813c3ef9-2b66-42f7-968a-e89ae23e3876", "name": "Hot Desk", "type": "shared", "type_label": "Shared Space", "capacity": 20,
        "price": { "amount": 40, "currency": "EGP", "is_starting_price": false, "display": "EGP 40.00/ hr" } }
    ]
  }
}
```

Field notes:

- `coordinates` — the owner's map pin from the Create Workspace picker, or `null` when there is no pin, only half a
  pin, the placeholder `0,0`, or an out-of-range value. Clients should fall back to `address`/`city`.
- `images` — owner's display order; `thumbnail_url` falls back to `url` when no thumbnail exists. Treat image URLs as
  opaque. Photos are stored as `workspace-images/<random 40 chars>.<ext>` (no internal id in the path). Photos uploaded
  **before** this layout keep their original, still-working URL `workspace-images/<workspace id>/<random>.<ext>`, which
  contains the workspace's numeric database id. Those files are deliberately not moved (see *Known gaps*).
- `rooms` — only rooms that are **available** and have a **displayable price above zero**.
- `rooms[].id` — the room's **public identifier**: a random UUID (`rooms.public_id`), **never** the internal database
  primary key. Generated automatically for every room, unique (database index), immutable, and unchanged by renames,
  re-pricing or availability toggles. Use it with the workspace slug in future availability/booking calls (e.g.
  `…/workspaces/{slug}/rooms/{id}/…`). **It is a reference, not authorization**: any future endpoint must re-check that
  the room belongs to that slug's *public* workspace and is eligible for the requested operation. No JSON field carries
  an internal numeric id; the only place one can still appear is inside the URL of a photo uploaded before the id-free
  storage layout (see `images`).
- `price.amount` / `price.display` come from `RoomPricingService` (`displayPrice()` / `summary()`);
  `is_starting_price` is `true` for duration/people-tier rooms ("From …"). `starting_price` = lowest room amount.
- `?lang=ar` localizes `type_label` and `price.display`. Hourly prices: English `"EGP 150.00/ hr"`, Arabic
  `"150.00 ج.م / ساعة"`; per-person tiers: `"From EGP 50.00/ hr"` / `"يبدأ من 50.00 ج.م / ساعة"`. `amount` and
  `currency` never change with the language.
- Slugs are stable: renaming a workspace does not change its slug.

### Errors

Always `{"message": "..."}` (plus `errors` for 422), localized, never a stack trace, SQL or exception class — even
with `APP_DEBUG=true` (exceptions are still logged server-side).

| Status | When | Body |
|---|---|---|
| 404 | unknown, malformed, or not-public slug; unknown path | `{"message":"Not found."}` (identical for every reason) |
| 405 | non-GET method | `{"message":"Method not allowed."}` |
| 422 | invalid `page` / `per_page` | `{"message":"The request is invalid.","errors":{"per_page":["The per page field must not be greater than 50."]}}` — Arabic: `{"message":"الطلب غير صالح.","errors":{"per_page":["يجب ألا يزيد عدد النتائج في الصفحة عن 50."]}}` |
| 429 | rate limit exceeded | `{"message":"Too many requests. Please try again shortly."}` + `Retry-After` |
| 500 | unexpected error | `{"message":"Something went wrong. Please try again later."}` |

Validation messages follow the API locale: Arabic text comes from `app.api.validation` (rules `integer`, `min`, `max`
and the `page` / `per_page` field names); English is Laravel's default text. Field keys in `errors` (`page`,
`per_page`) never change with the language. Paginator `meta.links[].label` stays English.

Response headers: `Content-Language`, `Vary: Accept-Language`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`.
No cookies are set.

---

## Eligibility (who appears)

Decided only by `App\Services\PublicWorkspaceDirectory`, re-checked on **every** request:

1. `publication_status = published` (approved by a Super Admin) and a slug, **and**
2. `WorkspaceReadinessService` passes now: name; address + city; description; primary photo; at least one available
   room with a price > 0; owner account active; subscription active (any plan, including Free/trial).

Draft, pending, rejected and unpublished workspaces never appear. A published workspace that stops meeting a rule
disappears (and returns once fixed) without its status changing.

## Public vs. excluded fields

**Returned:** slug, name, city, address, coordinates (valid pin only), description, image URLs, room public id (UUID)/name/type/
label/capacity, room price (amount, currency, display text, starting-price flag), starting price, room count.

**Never returned:** any internal numeric id as a field (room, workspace, owner, image; see `images` for older photo
URLs), owner name/email/phone/business name, workspace phone, Google place id,
storage paths, room descriptions, operational flags (`is_active`, `is_available`), publication status/dates, review
history, moderation reasons, pricing rules JSON, billing settings, bookings, sessions, sales, financials, staff,
router/MikroTik data. Tests assert these never appear in the JSON.

---

## Configuration

All optional; defaults keep the Panel's current behaviour. Set them in the server `.env`, then run
`php artisan config:clear` (or `config:cache`).

| Variable | Default | Purpose |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | *(empty)* | Comma-separated exact origins allowed to call the API from a browser, e.g. `https://link-space.net,https://www.link-space.net`. Empty = none. No wildcard; credentials are never allowed. |
| `API_PUBLIC_RATE_LIMIT` | `60` | Requests per minute per client IP. |
| `TRUSTED_PROXIES` | *(empty)* | Comma-separated IPs/CIDRs whose `X-Forwarded-*` headers are trusted. Only for a proxy/CDN that terminates HTTPS in front of PHP. **Never `*` in production** without a deliberate, reviewed reason: it lets any client forge its IP and bypass the per-IP rate limit. When a CDN/reverse proxy is introduced, list only its verified, published IP ranges. |
| `ALLOW_DROP_ROOM_PUBLIC_IDS` | *(unset)* | **Emergency override, one command only**, e.g. `ALLOW_DROP_ROOM_PUBLIC_IDS=1 php artisan migrate:rollback --step=1`. Never put it in `.env`: Laravel copies `.env` into the process environment, which would silently disable the rollback guard. |

**Current hosting (checked read-only, 2026-10-27):** `panel.link-space.net` is served directly by LiteSpeed (no CDN/
proxy headers), and Laravel already generates `https://panel.link-space.net/…` URLs (login form action). `asset()`
uses the same URL generator, so image URLs will be `https://` **without** `TRUSTED_PROXIES`. Set it only if a
CDN/proxy (e.g. Cloudflare) is added later. The `public/storage` symlink (`php artisan storage:link`) must exist.

Native mobile apps don't use CORS.

### Production check (after deploying)

```bash
curl -sI https://panel.link-space.net/api/v1/workspaces | grep -i -E "content-type|x-ratelimit"
curl -s https://panel.link-space.net/api/v1/workspaces | grep -o '"url":"[^"]*"' | head -1   # must start with https://
curl -sI -H "Origin: https://link-space.net" https://panel.link-space.net/api/v1/workspaces | grep -i access-control-allow-origin
```

---

## Migrations (all uncommitted phases)

Run in this order (the timestamps already enforce it) with a database backup first: `php artisan migrate`.
Verified on SQLite and on MariaDB 10.4 (scratch) — up, data preserved, and the guarded rollbacks.

| # | Migration | Change | Rollback |
|---|---|---|---|
| 1 | `2026_10_24_000001_create_workspace_images_table` | workspace photo table | drops the table; **photo files on disk remain** (clean `storage/app/public/workspace-images/` manually if wanted) |
| 2 | `2026_10_25_000001_add_publication_to_workspaces_table` | `slug` (unique), `publication_status` (default `draft`), `published_at` | drops the columns — **all publication state and slugs are lost** |
| 3 | `2026_10_25_000002_create_workspace_publication_reviews_table` | review history | drops the table — **history is lost** |
| 4 | `2026_10_26_000001_change_workspace_description_to_text` | `workspaces.description` VARCHAR(255) → TEXT (lossless) | refuses while any description > 255 chars |
| 5 | `2026_10_27_000001_change_room_description_to_text` | `rooms.description` VARCHAR(255) → TEXT (lossless) | refuses while any description > 255 chars |
| 6 | `2026_10_28_000001_add_public_id_to_rooms_table` | `rooms.public_id` VARCHAR(36): add nullable (if missing) → backfill a UUID only for rooms without one (PHP `Str::uuid()`, no DB-specific default; existing values are never replaced) → **stop** if any value is duplicated → NOT NULL (if still nullable) → unique index (if no unique index on the column exists, whatever its name). Safe to re-run after an interruption at any step | **refuses** while rooms exist unless `ALLOW_DROP_ROOM_PUBLIC_IDS=1` is set for that one command; a confirmed rollback discards the identifiers and re-running the migration issues **new** ones (any saved links/bookmarks to old ids break). Never do this once ids have been exposed to consumers |

Notes:
- 1–3 are new tables/columns (no existing data touched). 4–6 alter existing tables: `ALTER TABLE … MODIFY/ADD`
  on MySQL/MariaDB (verified on MariaDB 10.4 with existing rooms and bookings — data unchanged, unique index enforced);
  on SQLite Laravel rebuilds the table and keeps indexes/foreign keys (verified with `php artisan migrate` on a copy
  holding bookings/sessions/photos — no rows lost, `foreign_key_check` clean).
- SQLite caveat: run these through `php artisan migrate`, not inside an outer transaction. A table rebuild inside an
  outer transaction can't switch foreign keys off and would cascade-delete rows that reference the table.
- Pre-existing (not introduced here): `migrate:rollback` of *all* migrations fails on SQLite at an older `sales`
  migration that drops an indexed column. Partial rollbacks of the migrations above work.
- Roll back in reverse order: `php artisan migrate:rollback --step=N`. Rolling back 2 while the app code that uses
  publication is deployed will break the publication screens — roll back code and schema together.
- Nothing is backfilled: existing workspaces start as `draft` with no slug (slugs are created on first submission).

---

## Deployment runbook

A checklist for the **first** production deployment of these phases. **It has not been carried out yet.** Run
everything from the app directory on the server.

**1. Before**
- [ ] Take a full database backup (e.g. `mysqldump --single-transaction --routines <db> > pre-api-$(date +%F).sql`)
      and **verify it**: the file is non-empty, ends with `-- Dump completed`, and restores into a scratch database
      with matching `rooms` / `bookings` / `workspaces` row counts.
- [ ] Back up `storage/app/public/` (uploaded photos).
- [ ] `php artisan migrate:status`: save the output and confirm that exactly the six migrations above are *Pending*.
- [ ] `.env`: `APP_DEBUG=false`, `CORS_ALLOWED_ORIGINS` set to the approved origins only, `TRUSTED_PROXIES` empty
      (current hosting has no proxy) and `ALLOW_DROP_ROOM_PUBLIC_IDS` **absent**.

**2. Deploy (in this order: new code must never run without its schema, nor old code on the new schema)**
1. `php artisan down`
2. Update the code (pull/upload), then `composer install --no-dev --optimize-autoloader` if dependencies changed.
3. `php artisan migrate --force`
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
5. `php artisan storage:link` (no-op if `public/storage` already exists)
6. `php artisan up`

**3. Verify**
- [ ] `php artisan migrate:status`: all six are *Ran* and none are pending.
- [ ] `rooms.public_id`:
      `SELECT COUNT(*) FROM rooms WHERE public_id IS NULL OR public_id = '';` → 0.
      `SELECT public_id FROM rooms GROUP BY public_id HAVING COUNT(*) > 1;` → no rows.
      `SHOW INDEX FROM rooms WHERE Column_name = 'public_id';` → one row with `Non_unique = 0`.
      `SELECT COUNT(*) FROM rooms;` and `SELECT COUNT(*) FROM bookings;` → same as in the backup.
- [ ] Photos: an existing room photo and a newly uploaded workspace photo both load over `https://`.
- [ ] Smoke tests:
  ```bash
  B=https://panel.link-space.net/api/v1
  curl -s "$B/workspaces?lang=en" | head -c 300; echo                 # 200, JSON {data,links,meta}
  curl -s "$B/workspaces?lang=ar" | head -c 300; echo                 # 200, Arabic labels / prices
  curl -s "$B/workspaces/<a-published-slug>?lang=ar" | head -c 300; echo   # 200 detail (if any is published)
  curl -s -o /dev/null -w "%{http_code}\n" "$B/workspaces/Not_A_Slug"   # 404, {"message":"Not found."}
  curl -s "$B/workspaces?per_page=51&lang=ar"; echo                     # 422, Arabic validation text
  curl -s "$B/workspaces?per_page=51"; echo                             # 422, English validation text
  curl -sI "$B/workspaces" | grep -i -E "content-type|x-ratelimit|content-language"
  for i in $(seq 1 61); do curl -s -o /dev/null -w "%{http_code} " "$B/workspaces"; done; echo   # last one: 429
  ```
  Also run the CORS check in *Production check* above.

**4. If something goes wrong: keep the room public ids**
- Don't run `migrate:rollback` on migration 6, and don't drop or regenerate `rooms.public_id`. Values already issued
  may be saved by consumers.
- If `migrate` stopped part-way, check `migrate:status` and the SQL above, then simply run
  `php artisan migrate --force` again. Migration 6 re-runs safely from any interrupted step and keeps every existing
  value.
- If it stops with **"duplicate values"**: keep the id that clients may already know (the older room's). Set the
  other room's value to `NULL` (`UPDATE rooms SET public_id = NULL WHERE id = <other room id>;`) and re-run. It
  gets a new UUID.
- An application problem *without* schema damage: roll back the **code only**. The new columns and tables are
  additive, and old code ignores them (apart from room creation, which then needs the new code back).
- Last resort: restore the verified backup. Note that any room created after the backup would lose its id.

---

## Performance (measured, scratch MariaDB 10.4)

Data: 60 owners, 660 workspaces (360 published incl. 60 deliberately broken, 300 drafts), each with 4 photos and
6 rooms (one unavailable, one priced 0), ~700-character descriptions. One listing page = `paginate(12, 1)` + JSON.

| | Queries | Rows loaded | Peak memory | Time | Result |
|---|---|---|---|---|---|
| Listing, before 3.1 | 4 | 4,020 | 11.7 MB | ~200 ms | 300 total, 13,813 bytes |
| Listing, after 3.1 | 4 | 1,860 | 5.8 MB | ~105 ms | identical (300 total, 13,813 bytes) |
| Detail, before → after | 4 → 4 | 12 → 10 | 1.0 MB | ~8 ms | identical |

Findings:
- No N+1: query count is constant regardless of workspace count (test `test_listing_does_not_run_queries_per_workspace`).
- No binary image content is ever read — image rows are paths only; URLs are built with `asset()`.
- 3.1 change: the SQL pre-filter now also excludes blank name/address/city/description and requires an available room
  priced > 0 (or rule-based); listing eager-loads only the **primary** photo and only **available, possibly-priced**
  rooms without their descriptions. The final decision is still `WorkspaceReadinessService` on every request.
- Remaining cost: every request still loads **all** candidate workspaces (published + passing the SQL pre-filter) to
  filter and paginate in PHP, because one rule — a rule-based room whose lowest grid price is 0 — is evaluated from
  JSON by `RoomPricingService`. Cost grows linearly: ~1,860 rows / 5.8 MB per 300 eligible workspaces.

**Proposed next step (not implemented — needs approval)**, when the directory approaches ~1,000+ published workspaces:
add a `rooms.display_price` decimal column computed by `RoomPricingService::displayPrice()` whenever a room is saved
(model `saving` hook) plus a one-time backfill. Every readiness rule then becomes SQL-expressible and exact, so the
API can count and `LIMIT/OFFSET` in the database and load only one page. It stays correct because the display price
depends only on the room's own pricing columns, which change only through room saves; owner status, subscription
expiry, photos and availability remain live SQL conditions (no cached eligibility flag). Risk: raw SQL updates to
room pricing that bypass Eloquent would leave the column stale — none exist in the codebase today.

---

## Known gaps

- Room descriptions exist (up to 1000 chars) but are not part of the v1 contract.
- **Older workspace photo URLs include the workspace's numeric id** (`workspace-images/<id>/…`). New uploads don't.
  Hiding the old ones means moving files and updating their `path` rows, a separate, reviewed operation with its
  own backup. It is not done automatically; the files and URLs are left exactly as they are.
- Room photos are still stored under `room-images/<room id>/…`. They are not part of the API today, so nothing
  exposes them. Revisit this before room photos are added to the contract.
- **Outstanding pre-launch decision: how the website consumes the API.** The limit is 60 requests/minute **per
  client IP**. If a server-rendered website calls the API from one server, all its visitors share that one budget.
  Options include caching or incremental static regeneration on the website, browser-side calls (CORS already
  allows approved origins), or a separate server-to-server allowance. None is implemented; the limit, the
  pagination defaults (12 / 50) and the keying are unchanged until the consumer architecture is approved.
