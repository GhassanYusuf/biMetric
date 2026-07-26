# Engineering Standards (binding)

These standards govern **all** work in this repository. They override prior
patterns (the app's original Inertia + redirect/flash flows). Do not deviate
without an explicit instruction from the product owner.

## Stack
- **Backend:** Laravel 12 (PHP 8.2+)
- **DB:** SQLite
- **Frontend:** React (main interactive layer) via Vite; jQuery only for tiny
  utility/DOM helpers — never the app architecture.
- **API:** RESTful JSON under `/api`.
- **Auth:** Laravel Sanctum (SPA/session + token), roles & permissions
  (spatie/laravel-permission).
- **Real-time:** MQTT (php-mqtt/laravel-client) publish from backend; browser
  MQTT-over-WebSocket subscribe in React.

## Architecture rules
1. **API-first.** All business logic lives in **services / actions / policies**;
   business controllers return **JSON only**. Web routes return the app shell.
2. **Response envelope** — every JSON API response:
   ```json
   { "success": true, "message": "...", "data": {}, "meta": {}, "errors": [] }
   ```
3. **Validation** via Form Requests. **Authorization** via Policies/Gates/
   middleware — every important action is authorization-aware.
4. **Thin controllers**, no God classes, no business logic in web controllers.
5. **Modular** and isolated; clean extension points.

## Multi-tenancy (this project = true multi-tenant SaaS)
- Every tenant-owned model has `tenant_id` and is **globally scoped** to the
  current tenant.
- **Never trust client-submitted tenant/role context.** Tenant is resolved
  server-side from the authenticated user.
- Scopes: **super-admin** (platform) / **tenant-admin** / **staff/operator** /
  **member (employee/self-service)**.

## Roles & permissions
- Explicit **roles AND permissions**; authorize on permissions, not role names.
- Seedable and extendable; custom tenant roles supported.

## MQTT / real-time
- Domain events publish MQTT from **services/listeners**, not controllers.
- Notifications are **persisted in the DB** as well as delivered live.
- App actions **still succeed if MQTT is down**; delivery is best-effort/retryable.
- Topics are tenant/user scoped — no cross-tenant leakage, no insecure wildcards.
- Topic design:
  - `tenant/{tenantId}/user/{userId}/notifications`
  - `tenant/{tenantId}/role/{roleName}/alerts`
  - `tenant/{tenantId}/module/{module}/{recordId}`
  - `tenant/{tenantId}/dashboard/{userId}`

## Live no-refresh UX
- Async forms; inline JSON validation errors; live badges/counters/lists;
  no full-page reload for ordinary CRUD. Polling only as a fallback.

## Mobile-first + separate presentation files
- Design phone-first, enhance up to desktop.
- When mobile/desktop UX differs materially, use **separate presentation files**
  (`resources/js/mobile/...`, `resources/js/desktop/...`) sharing components,
  hooks, services, API clients, and validation.

## Reuse & security
- Check for an existing component before building a new one; components are
  standalone and self-contained.
- Security-first: authz everywhere, scoped queries, no IDOR, rate-limit
  sensitive endpoints, protect uploads/downloads, secure MQTT creds/topics,
  audit sensitive actions.

_Full original brief retained in team notes; this file is the operative summary._
