<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #907 (PCU3) — document-scoped notification mutes.
 *
 * `platform_notification_preferences` (#903) keys a preference by
 * (tenant, user, event, channel). #907 adds a second, additive scope for the
 * "mute all from this document" action: a row keyed by
 * (tenant, user, event, document_type, document_id) where channel = 'all' and
 * mail_enabled = false mutes every channel of that one document for that one
 * user, leaving the event-wide preference untouched.
 *
 * The unique index is split into two partial indexes so event-wide rows (all
 * document columns null) keep the #903 guarantee while a user can mute many
 * documents for the same event without collisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_notification_preferences', function (Blueprint $table) {
            $table->string('document_type', 50)->nullable()->after('channel');
            $table->string('document_id', 64)->nullable()->after('document_type');
        });

        Schema::table('platform_notification_preferences', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id', 'event', 'channel']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX platform_notification_preferences_event_unique '
            .'ON platform_notification_preferences (tenant_id, user_id, event, channel) '
            .'WHERE document_type IS NULL AND document_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX platform_notification_preferences_document_unique '
            .'ON platform_notification_preferences (tenant_id, user_id, event, channel, document_type, document_id) '
            .'WHERE document_type IS NOT NULL AND document_id IS NOT NULL'
        );

        Schema::table('platform_notification_preferences', function (Blueprint $table) {
            $table->index(
                ['user_id', 'event', 'document_type', 'document_id'],
                'notification_preferences_document_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('platform_notification_preferences', function (Blueprint $table) {
            $table->dropIndex('notification_preferences_document_lookup_index');
        });

        DB::statement('DROP INDEX IF EXISTS platform_notification_preferences_event_unique');
        DB::statement('DROP INDEX IF EXISTS platform_notification_preferences_document_unique');

        Schema::table('platform_notification_preferences', function (Blueprint $table) {
            $table->unique(['tenant_id', 'user_id', 'event', 'channel']);
            $table->dropColumn(['document_type', 'document_id']);
        });
    }
};
