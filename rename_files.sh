#!/usr/bin/env bash
# Renames a set of poorly-named files in the Exospace project and fixes
# the internal references that point to their old names/paths.
#
# WHERE TO RUN THIS:
#   Place this script in the ROOT of your project (the folder that
#   contains "artisan", "composer.json", "app/", "tests/", "scripts/", "docs/")
#
# USAGE:
#   cd /path/to/your/exospace/project
#   bash rename_files.sh --dry-run     # preview every change, touches nothing
#   bash rename_files.sh               # actually perform the renames
#   bash rename_files.sh --yes         # perform renames without the confirm prompt
#
# SAFETY:
#   - --dry-run prints every planned action and exits without changing anything.
#   - If this is a git repo, the script REQUIRES a clean working tree (no
#     uncommitted changes) before it does anything, so rollback is always
#     possible with a single command.
#   - If this is NOT a git repo, the script first copies every file it is
#     about to touch into a timestamped backup folder next to the project.
#   - If anything fails partway through, or you hit Ctrl-C, the script
#     automatically rolls back everything it had done up to that point —
#     you are never left in a half-renamed state.

set -euo pipefail

DRY_RUN=0
SKIP_CONFIRM=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes)     SKIP_CONFIRM=1 ;;
  esac
done

if [ ! -f "artisan" ] || [ ! -f "composer.json" ]; then
  echo "ERROR: run this from the project root (where 'artisan' and 'composer.json' live)."
  exit 1
fi

# ---------------------------------------------------------------------------
# The list of renames. Format: "from|to"
# ---------------------------------------------------------------------------
MOVES=(
  "tests/Feature/Iteration2Test.php|tests/Feature/ProrationConfigAndUiComponentsTest.php"
  "tests/Feature/Iteration4Test.php|tests/Feature/AuditLoggingAndAffiliateDashboardTest.php"
  "tests/Feature/Iteration5Test.php|tests/Feature/PiiAnonymizationCommandsTest.php"
  "tests/Feature/Iteration6Test.php|tests/Feature/OperationalHealthChecksTest.php"
  "tests/Feature/Iteration7Test.php|tests/Feature/AlertDeduplicationTest.php"
  "tests/Feature/Iteration8Test.php|tests/Feature/ApiTokenHashingSecurityTest.php"
  "tests/Feature/Iteration9Test.php|tests/Feature/MultiDiskBackupConfigTest.php"
  "tests/Feature/Iteration10Test.php|tests/Feature/AlertWebhookRoutingTest.php"
  "tests/Feature/Iteration11Test.php|tests/Feature/MetricsEndpointFormatTest.php"
  "tests/Feature/Iteration12Test.php|tests/Feature/CspSecurityHeaderRegressionTest.php"
  "tests/Feature/CriticalBugFixesTest.php|tests/Feature/GalleryViewIncrementJobTest.php"
  "tests/Feature/GardenIteration5FixesTest.php|tests/Feature/SculptureGardenAndCspFixesTest.php"
  "tests/Feature/PerformanceHotfixesTest.php|tests/Feature/QueryPerformanceOptimizationTest.php"
  "tests/Feature/ExampleTest.php|tests/Feature/HomepageSmokeTest.php"
  "tests/Unit/ExampleTest.php|tests/Unit/SanityCheckTest.php"
  "scripts/iter7_migration_check.php|scripts/check_billing_digest_recipients_migration.php"
  "scripts/validate_migration9.py|scripts/validate_luxury_penthouse_media_wall_migration.py"
  "scripts/harness/zen-transitions.mjs|scripts/harness/harness-static-server.mjs"
  "scripts/harness/zen-app-transitions.mjs|scripts/harness/probe-zen-transitions.mjs"
  "docs/DR.md|docs/DISASTER-RECOVERY.md"
)

# Format: "file|old_class|new_class" — applied AFTER the move above.
CLASS_RENAMES=(
  "tests/Feature/ProrationConfigAndUiComponentsTest.php|Iteration2Test|ProrationConfigAndUiComponentsTest"
  "tests/Feature/AuditLoggingAndAffiliateDashboardTest.php|Iteration4Test|AuditLoggingAndAffiliateDashboardTest"
  "tests/Feature/PiiAnonymizationCommandsTest.php|Iteration5Test|PiiAnonymizationCommandsTest"
  "tests/Feature/OperationalHealthChecksTest.php|Iteration6Test|OperationalHealthChecksTest"
  "tests/Feature/AlertDeduplicationTest.php|Iteration7Test|AlertDeduplicationTest"
  "tests/Feature/ApiTokenHashingSecurityTest.php|Iteration8Test|ApiTokenHashingSecurityTest"
  "tests/Feature/MultiDiskBackupConfigTest.php|Iteration9Test|MultiDiskBackupConfigTest"
  "tests/Feature/AlertWebhookRoutingTest.php|Iteration10Test|AlertWebhookRoutingTest"
  "tests/Feature/MetricsEndpointFormatTest.php|Iteration11Test|MetricsEndpointFormatTest"
  "tests/Feature/CspSecurityHeaderRegressionTest.php|Iteration12Test|CspSecurityHeaderRegressionTest"
  "tests/Feature/GalleryViewIncrementJobTest.php|CriticalBugFixesTest|GalleryViewIncrementJobTest"
  "tests/Feature/SculptureGardenAndCspFixesTest.php|GardenIteration5FixesTest|SculptureGardenAndCspFixesTest"
  "tests/Feature/QueryPerformanceOptimizationTest.php|PerformanceHotfixesTest|QueryPerformanceOptimizationTest"
  "tests/Feature/HomepageSmokeTest.php|ExampleTest|HomepageSmokeTest"
  "tests/Unit/SanityCheckTest.php|ExampleTest|SanityCheckTest"
)

CONFIG_FILE="config/test-profiles.php"
DR_FILE_NEW="docs/DISASTER-RECOVERY.md"

GLOB_LINE="                'tests/Feature/Iteration*Test.php',"
read -r -d '' GLOB_REPLACEMENT <<'EOL' || true
                'tests/Feature/ProrationConfigAndUiComponentsTest.php',
                'tests/Feature/AuditLoggingAndAffiliateDashboardTest.php',
                'tests/Feature/PiiAnonymizationCommandsTest.php',
                'tests/Feature/OperationalHealthChecksTest.php',
                'tests/Feature/AlertDeduplicationTest.php',
                'tests/Feature/ApiTokenHashingSecurityTest.php',
                'tests/Feature/MultiDiskBackupConfigTest.php',
                'tests/Feature/AlertWebhookRoutingTest.php',
                'tests/Feature/MetricsEndpointFormatTest.php',
                'tests/Feature/CspSecurityHeaderRegressionTest.php',
EOL

# ---------------------------------------------------------------------------
# Preflight: figure out whether we're in a git repo, and how rollback works.
# ---------------------------------------------------------------------------
USE_GIT=0
if git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
  USE_GIT=1
fi

BACKUP_DIR=""

check_destinations_are_free() {
  local conflict=0
  for pair in "${MOVES[@]}"; do
    to="${pair##*|}"
    if [ -e "$to" ]; then
      echo "  CONFLICT: destination already exists: $to"
      conflict=1
    fi
  done
  if [ "$conflict" -eq 1 ]; then
    echo ""
    echo "ERROR: one or more destination paths already exist. Refusing to run,"
    echo "since 'mv'/'git mv' would silently move a source file INTO an existing"
    echo "directory rather than renaming it. Remove/rename the conflicting"
    echo "path(s) above and re-run."
    exit 1
  fi
}

preflight() {
  check_destinations_are_free
  if [ "$USE_GIT" -eq 1 ]; then
    echo "Git repo detected."
    if [ -n "$(git status --porcelain)" ]; then
      echo ""
      echo "ERROR: your working tree has uncommitted changes."
      echo "This script needs a clean tree so it can roll back with a single"
      echo "'git reset --hard HEAD' if anything goes wrong. Please commit or"
      echo "stash your changes first, then re-run this script."
      exit 1
    fi
    echo "Working tree is clean — rollback will use 'git reset --hard HEAD' if needed."
  else
    echo "No git repo detected — will back up every touched file before changing it."
    if [ "$DRY_RUN" -eq 0 ]; then
      BACKUP_DIR="../exospace_rename_backup_$(date +%Y%m%d_%H%M%S)"
      mkdir -p "$BACKUP_DIR"
      for pair in "${MOVES[@]}"; do
        from="${pair%%|*}"
        if [ -f "$from" ]; then
          mkdir -p "$BACKUP_DIR/$(dirname "$from")"
          cp -a "$from" "$BACKUP_DIR/$from"
        fi
      done
      for f in "$CONFIG_FILE"; do
        if [ -f "$f" ]; then
          mkdir -p "$BACKUP_DIR/$(dirname "$f")"
          cp -a "$f" "$BACKUP_DIR/$f"
        fi
      done
      echo "Backup created at: $BACKUP_DIR"
    fi
  fi
}

# ---------------------------------------------------------------------------
# Rollback: invoked automatically on error or interrupt (see trap below).
# ---------------------------------------------------------------------------
ROLLED_BACK=0
rollback() {
  if [ "$ROLLED_BACK" -eq 1 ] || [ "$DRY_RUN" -eq 1 ]; then
    return
  fi
  ROLLED_BACK=1
  echo ""
  echo "!! Interrupted or failed — rolling back all changes made so far..."
  if [ "$USE_GIT" -eq 1 ]; then
    git reset --hard HEAD > /dev/null
    echo "Rolled back with 'git reset --hard HEAD'. Working tree matches your last commit."
  elif [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
    # Remove any newly-created destination files, then restore originals.
    for pair in "${MOVES[@]}"; do
      to="${pair##*|}"
      [ -f "$to" ] && rm -f "$to"
    done
    cp -a "$BACKUP_DIR/." ./
    echo "Restored original files from backup at: $BACKUP_DIR"
  else
    echo "No backup exists yet (failure happened before backup was made) —"
    echo "nothing should have changed."
  fi
}

trap rollback ERR INT TERM

# ---------------------------------------------------------------------------
# Step 1: rename files
# ---------------------------------------------------------------------------
do_mv() {
  local from="$1" to="$2"
  if [ ! -f "$from" ]; then
    echo "  SKIP (not found): $from"
    return
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [dry-run] would rename: $from -> $to"
    return
  fi
  mkdir -p "$(dirname "$to")"
  if [ "$USE_GIT" -eq 1 ]; then
    git mv "$from" "$to"
  else
    mv "$from" "$to"
  fi
  echo "  renamed: $from -> $to"
}

# ---------------------------------------------------------------------------
# Step 2: fix PHP class names to match new filenames (PSR-4 requires this)
# ---------------------------------------------------------------------------
do_class_rename() {
  local filepath="$1" old_class="$2" new_class="$3"
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [dry-run] would rename class in $filepath: $old_class -> $new_class"
    return
  fi
  if [ ! -f "$filepath" ]; then
    return
  fi
  sed -i.bak -E "s/^class ${old_class} extends/class ${new_class} extends/" "$filepath"
  rm -f "${filepath}.bak"
  echo "  updated class in: $filepath ($old_class -> $new_class)"
}

# ---------------------------------------------------------------------------
# Step 3: fix references in config/test-profiles.php
# ---------------------------------------------------------------------------
update_config_file() {
  if [ ! -f "$CONFIG_FILE" ]; then
    echo "  SKIP (not found): $CONFIG_FILE"
    return
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [dry-run] would update 3 direct paths + expand the Iteration*Test.php glob in $CONFIG_FILE"
    return
  fi
  sed -i.bak \
    -e "s#tests/Feature/CriticalBugFixesTest\.php#tests/Feature/GalleryViewIncrementJobTest.php#g" \
    -e "s#tests/Feature/PerformanceHotfixesTest\.php#tests/Feature/QueryPerformanceOptimizationTest.php#g" \
    -e "s#tests/Feature/ExampleTest\.php#tests/Feature/HomepageSmokeTest.php#g" \
    "$CONFIG_FILE"

  if grep -qF "$GLOB_LINE" "$CONFIG_FILE"; then
    {
      while IFS= read -r line || [ -n "$line" ]; do
        if [ "$line" = "$GLOB_LINE" ]; then
          printf '%s\n' "$GLOB_REPLACEMENT"
        else
          printf '%s\n' "$line"
        fi
      done < "$CONFIG_FILE"
    } > "${CONFIG_FILE}.tmp"
    mv "${CONFIG_FILE}.tmp" "$CONFIG_FILE"
    echo "  expanded Iteration*Test.php glob into 10 explicit filenames"
  else
    echo "  NOTE: could not find the exact glob line to replace — check $CONFIG_FILE manually"
  fi
  rm -f "${CONFIG_FILE}.bak"
  echo "  updated: $CONFIG_FILE"
}

# ---------------------------------------------------------------------------
# Step 4: fix the script-name reference inside the renamed DR doc
# ---------------------------------------------------------------------------
update_dr_doc() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [dry-run] would update the validate_migration9.py reference in $DR_FILE_NEW"
    return
  fi
  if [ -f "$DR_FILE_NEW" ]; then
    sed -i.bak \
      -e "s#scripts/validate_migration9\.py#scripts/validate_luxury_penthouse_media_wall_migration.py#g" \
      "$DR_FILE_NEW"
    rm -f "${DR_FILE_NEW}.bak"
    echo "  updated: $DR_FILE_NEW"
  fi
}

# ---------------------------------------------------------------------------
# Step 5 (real runs only): sanity-check that renamed PHP files still parse
# ---------------------------------------------------------------------------
verify_php_syntax() {
  if [ "$DRY_RUN" -eq 1 ] || ! command -v php > /dev/null 2>&1; then
    return
  fi
  echo ""
  echo "Verifying PHP syntax on renamed files..."
  local failed=0
  for pair in "${MOVES[@]}"; do
    to="${pair##*|}"
    case "$to" in
      *.php)
        if [ -f "$to" ] && ! php -l "$to" > /dev/null 2>&1; then
          echo "  SYNTAX ERROR in $to"
          failed=1
        fi
        ;;
    esac
  done
  if [ "$failed" -eq 1 ]; then
    echo "PHP syntax errors found after rename — triggering rollback."
    return 1
  fi
  echo "  all renamed PHP files parse cleanly."
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
if [ "$DRY_RUN" -eq 1 ]; then
  echo "=== DRY RUN — no files will be changed ==="
fi

preflight

if [ "$DRY_RUN" -eq 0 ] && [ "$SKIP_CONFIRM" -eq 0 ]; then
  echo ""
  echo "About to rename ${#MOVES[@]} files and update 2 reference files."
  read -r -p "Proceed? [y/N] " confirm
  if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "Aborted — nothing was changed."
    trap - ERR INT TERM
    exit 0
  fi
fi

echo ""
echo "Renaming files..."
for pair in "${MOVES[@]}"; do
  do_mv "${pair%%|*}" "${pair##*|}"
done

echo ""
echo "Fixing PHP class names to match their new filenames..."
for entry in "${CLASS_RENAMES[@]}"; do
  IFS='|' read -r filepath old_class new_class <<< "$entry"
  do_class_rename "$filepath" "$old_class" "$new_class"
done

echo ""
echo "Updating references in $CONFIG_FILE..."
update_config_file

echo ""
echo "Updating script reference inside $DR_FILE_NEW..."
update_dr_doc

verify_php_syntax

# Success — disarm the rollback trap before exiting normally.
trap - ERR INT TERM

echo ""
if [ "$DRY_RUN" -eq 1 ]; then
  echo "Dry run complete — nothing was changed. Re-run without --dry-run to apply."
else
  echo "Done. Also worth a manual look: validate_luxury_penthouse_media_wall_migration.py"
  echo "has a hardcoded absolute path to the old machine's project folder"
  echo "(/home/z/my-project/exospace/...) — unrelated to this rename."
  echo ""
  if [ "$USE_GIT" -eq 1 ]; then
    echo "Run 'git status' / 'git diff --stat' to review, then commit when happy."
    echo "(If you want to undo everything right now: git reset --hard HEAD)"
  else
    echo "A backup of the original files is at: $BACKUP_DIR"
    echo "(delete it once you've confirmed everything looks right)"
  fi
fi