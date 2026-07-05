# Observers Directory

## TD-18: Decision — Use inline `static::boot()` hooks, not Observers

This directory exists to document the codebase's decision on the
Observers vs. boot-hooks pattern. It's intentionally empty (no observer
classes) — the project uses inline `static::boot()` hooks in models.

### Why inline boot hooks (current pattern)

- **Proximity**: the hook lives next to the model it affects. No need
  to jump to a separate Observer file to understand model lifecycle
  behavior.
- **Simplicity**: no need to register observers in a ServiceProvider.
  Laravel auto-runs `static::boot()` on every model.
- **Small surface area**: the codebase has ~10 models, each with 1-3
  boot hooks. An Observer per model would double the file count with
  no benefit.

### When to switch to Observers

Switch to the Observer pattern if ANY of these become true:

1. A single model's boot() grows to >50 lines (the hooks are doing too
   much — extract to an Observer for readability).
2. A hook needs to call external services (e.g. send an email, dispatch
   a job, call an API) — Observers are easier to mock in tests.
3. Multiple models share the same hook logic (e.g. "on delete, purge
   related cache") — a shared Observer or trait is cleaner than
   duplicating the hook in each model's boot().
4. The hook ordering matters across models (e.g. User::deleting must
   run before Gallery::deleting) — Observers can declare explicit
   priorities via the `ShouldQueue` interface or the `$listen` array.

### Existing boot hooks (for reference)

| Model | Hook | Purpose |
|-------|------|---------|
| `User` | `creating` | Default plan + limits + plan_started_at |
| `User` | `updating` | Refresh limits on plan change (TD-13: preserves plan_started_at) |
| `Team` | `creating` | Auto-generate slug from name |
| `Gallery` | `creating` | Auto-generate slug (P2-5: relies on DB unique constraint) |
| `Gallery` | `saving` | Normalize custom_domain (strip scheme/path/port) |
| `VenueTemplate` | `creating` | Auto-generate slug |
| `VenueTemplate` | `updating` | Touch `updated_at` on related galleries (cache invalidation) |
| `Artist` | `creating` | Auto-generate slug |

### If you DO add an Observer

1. Create `app/Observers/{ModelName}Observer.php`.
2. Register it in `AppServiceProvider::boot()`:
   ```php
   Model::observe(ModelObserver::class);
   ```
3. Move the relevant `static::creating` / `static::updating` hooks from
   the model's `boot()` method to the observer's `creating` / `updating`
   methods.
4. Add a test that verifies the observer fires on the expected event.
