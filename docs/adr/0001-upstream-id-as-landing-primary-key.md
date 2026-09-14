# ADR-0001: Upstream id as landing primary key

## Status

Accepted.

## Context

The upstream feed (`GET /landings-api.php`) assigns each landing a stable numeric `id`. Our schema needs a primary key for `landings`, and testimonials reference a landing via `landing_id`. If we used our own auto-increment id and mapped it to the upstream id via a separate lookup column, every sync would need to resolve upstream id → local id before it could upsert, and every read of testimonials would carry the indirection.

## Decision

`landings.id` **is** the upstream id (`INT UNSIGNED NOT NULL`, not auto-increment). Sync becomes a single `INSERT ... ON DUPLICATE KEY UPDATE` keyed on `id`: a landing already known gets its columns refreshed in place, a new one gets inserted, and nothing needs a translation table. Testimonials keep referencing `landing_id` — their foreign key never needs remapping across syncs, no matter how many times sync runs.

## Consequences

- Sync is simple and idempotent: same upstream id in → same row updated, not duplicated.
- We depend on upstream never reusing an id for a semantically different landing. If upstream *recreates* a landing under a new id (e.g. after deleting and republishing it), our row for the old id is soft-deleted rather than merged into the new one — see ADR-0003, which is the direct consequence of this decision.
- We cannot renumber landings locally without breaking the sync's assumption that `id` is the upstream identity.
