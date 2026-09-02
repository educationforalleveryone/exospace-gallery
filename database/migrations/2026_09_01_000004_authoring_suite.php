<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iteration 5 "Authoring" (roadmap P2.1): make venue iteration safe in
 * production. Two schema pieces:
 *
 *   1. venue_templates.archived_at (nullable timestamp)
 *      "Delete" becomes ARCHIVE (§9.2 #4). The previous hard delete reset
 *      every gallery using the venue back to the default white-cube — an
 *      irreversible, customer-visible catastrophe behind one misclick.
 *      An archived venue instead:
 *        - disappears from every SELECTION surface (picker, public venue
 *          pages, previews — via scopeActive, one choke point),
 *        - keeps SERVING every gallery already using it (the exporter and
 *          the Gallery#venueTemplate relation deliberately ignore the
 *          flag — an archived venue never breaks a live show),
 *        - is restorable in one click from the admin table.
 *      Distinct from is_active (the pause toggle): archived = retired.
 *
 *   2. venue_template_snapshots
 *      The last 5 pre-save states of each venue (§9.2 #3), written by
 *      VenueSnapshotManager on every admin save (and before every restore,
 *      so a restore is itself reversible). JSON payload, no git, no
 *      branching — one-click rollback that de-fangs the "bad save is
 *      instantly live for every gallery using the venue" risk.
 *
 * SAFETY: purely additive — no existing column changes, no data rewrites.
 * ROLLBACK: down() drops exactly what up() created; archived venues (if
 * any) must be unarchived first ONLY if the operator wants them back —
 * dropping archived_at keeps is_active semantics intact otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_templates', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('published_at');
            $table->index('archived_at');
        });

        Schema::create('venue_template_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_template_id')
                ->constrained('venue_templates')
                ->cascadeOnDelete();
            $table->string('label')->nullable();      // "before save #12", "before restore"
            $table->json('config');                    // restorable content payload
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();                      // snapshot survives user deletion
            $table->timestamp('created_at')->nullable();

            // The only query patterns: latest-for-venue (list) and
            // prune-oldest-for-venue (retention).
            $table->index(['venue_template_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_template_snapshots');

        Schema::table('venue_templates', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
