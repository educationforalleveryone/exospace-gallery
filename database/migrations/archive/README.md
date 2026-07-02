# Archived Migrations (Task H47)

This directory documents the additive migrations that have been
consolidated into single CREATE migrations for fresh installs.

## Consolidated migrations

### Galleries table
**Consolidated by:** `2026_07_02_150000_create_galleries_table_consolidated.php`

Original additive migrations (NOT moved — kept in parent directory for
backward compatibility with existing production databases):

1. `2026_01_19_111649_create_galleries_table.php` — base table
2. `2026_02_06_054006_add_audio_to_galleries_table.php` — audio_path
3. `2026_02_06_084946_add_custom_logo_to_galleries_table.php` — custom_logo_path
4. `2026_03_22_184944_add_room_layout_to_galleries_table.php` — room_layout
5. `2026_04_21_201851_add_pin_to_galleries_table.php` — pin_hash
6. `2026_04_22_140439_add_schedule_to_galleries_table.php` — opens_at, closes_at
7. `2026_06_29_195411_add_custom_domain_to_galleries_table.php` — custom_domain
8. `2026_06_30_014504_add_featured_and_curtain_to_galleries.php` — is_featured, curtain_*
9. `2026_07_01_070923_convert_gallery_material_columns_to_varchar.php` — ENUM→VARCHAR
10. `2026_07_02_000001_add_visual_overrides_to_galleries.php` — visual_overrides
11. `2026_07_02_100000_add_custom_domain_verification_to_galleries_table.php` — verification fields

### Users table
**Consolidated by:** `2026_07_02_160000_create_users_table_consolidated.php`

1. `0001_01_01_000000_create_users_table.php` — base table
2. `2026_02_01_042719_add_plans_to_users_table.php` — plan columns
3. `2026_02_07_042958_add_super_admin_flag_to_users_table.php` — is_super_admin
4. `2026_04_23_121455_add_current_team_id_to_users_table.php` — current_team_id
5. `2026_04_25_015249_add_banned_at_to_users_table.php` — banned_at, ban_reason

### Venue templates table
**Consolidated by:** `2026_07_02_160001_create_venue_templates_table_consolidated.php`

1. `2026_06_09_000001_create_venue_templates_table.php` — base table
2. `2026_06_29_184241_extend_venue_templates_table.php` — 17 columns added

## How it works

- **Existing production databases:** The consolidated migrations are
  no-ops (they check `Schema::hasTable()` first). The original additive
  migrations are kept in the parent directory so `migrate:rollback`
  continues to work.

- **Fresh installs (new deployments, test suite):** The consolidated
  migrations create the full schema in one step. The original additive
  migrations still run afterward but are no-ops because the table
  already exists (their `Schema::hasColumn` checks pass).

- **To fully archive:** Once ALL production environments have been
  updated to a version that includes the consolidated migrations, the
  original additive migrations can be safely moved to this directory.
  At that point, `migrate:fresh` on any environment will use only the
  consolidated migrations.

## When to move the originals

Move the original additive migrations here when:
1. All production environments have run the consolidated migrations
2. No environment needs to `migrate:rollback` to a state before the
   consolidated migrations existed
3. The `migrations` table in every database has entries for the
   consolidated migrations

Until then, keep them in the parent directory.
