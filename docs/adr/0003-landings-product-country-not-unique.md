# ADR-0003: `(product_id, country)` on landings is not unique

## Status

Accepted. Supersedes the original schema, which had `UNIQUE KEY uq_landings_product_country (product_id, country)`.

## Context

Per ADR-0001, `landings.id` is the upstream id and sync is an `INSERT ... ON DUPLICATE KEY UPDATE` keyed on `id`. The original schema also had a secondary unique key on `(product_id, country)`, intended to guard against upstream ever describing two active landings for the same product+country pair.

That guard backfires under a real upstream failure mode: upstream recreates a landing under a **new** id (delete + republish) while keeping the same `parent_sku` + `country`. MySQL's `ON DUPLICATE KEY UPDATE` fires on *any* unique-key collision, not just the primary key. So the `INSERT` for the new id collides with the old row via the `(product_id, country)` unique key, and MySQL updates the *old* row in place — the new id is silently discarded, and worse, our own soft-delete/keep-testimonials logic (which runs *after* the upsert step and diffs by id) never even sees a new id to add or an old id to remove. A landing rename-via-recreate upstream would corrupt the local mapping between id and testimonials without raising any error.

## Decision

Replace the unique key with a plain (non-unique) index: `KEY ix_landings_product_country (product_id, country)`. It still supports the same lookups (e.g. "does this product already have an EN landing"), but a collision on `(product_id, country)` can no longer make `ON DUPLICATE KEY UPDATE` silently rewrite the wrong row. Sync upserts strictly by `id`; when upstream recreates a landing under a new id, sync inserts the new id as a brand-new row and the existing markRemovedExcept step soft-deletes the old id normally (it's simply missing from the new feed) — its testimonials stay attached to the old, now-hidden landing.

## Consequences

- Two *active* landings can technically share `(product_id, country)` for a short window (until the next sync soft-deletes the stale one). This is intentional headroom, not a data integrity hole we need — the truth about "one canonical landing per product/country" comes from `removed_at IS NULL`, not from a unique constraint.
- Regression-tested: `tests/Integration/Application/LandingSyncServiceTest::testLandingRecreatedUpstreamWithNewIdKeepsOldRowSoftDeleted` reproduces exactly this upstream-recreates-under-a-new-id scenario and asserts the old row is soft-deleted (not overwritten) and keeps its testimonials.
