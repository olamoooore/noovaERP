<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pillar 2 Part 3 §4.2: IoT agent tokens must not be stored in plaintext.
 *
 * The original `agents.api_token` column contained the raw bearer token and
 * both agent-authentication middleware classes compared against it directly.
 * This migration adds a SHA-256 hash column, backfills it in PHP (so it does
 * not depend on the PostgreSQL pgcrypto extension), then removes the raw
 * column. New provisioning writes `api_token_hash` and returns the raw token
 * exactly once to the agent operator.
 *
 * The migration is idempotent for interrupted/partially-applied upgrades.
 * The down migration can restore the old column name for schema rollback, but
 * cannot restore plaintext values; previously hashed credentials remain hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agents')) {
            return;
        }

        if (! Schema::hasColumn('agents', 'api_token_hash')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->string('api_token_hash', 64)->nullable();
            });
        }

        // Backfill any legacy raw tokens. This is intentionally done through
        // PHP's hash() rather than a database-specific crypto function.
        if (Schema::hasColumn('agents', 'api_token')) {
            DB::table('agents')
                ->whereNotNull('api_token')
                ->where(function ($query) {
                    $query->whereNull('api_token_hash')
                        ->orWhere('api_token_hash', '');
                })
                ->select(['id', 'api_token'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('agents')
                            ->where('id', $row->id)
                            ->update(['api_token_hash' => hash('sha256', $row->api_token)]);
                    }
                }, 'id');

            // The raw credential is no longer needed after backfill. Dropping
            // the column prevents future legacy writers from reintroducing
            // plaintext storage.
            Schema::table('agents', function (Blueprint $table) {
                $table->dropColumn('api_token');
            });
        }

        // All rows in the original schema had a non-null token. After the
        // backfill, enforce that invariant for the hash column and add a
        // unique lookup index.
        DB::statement('ALTER TABLE agents ALTER COLUMN api_token_hash SET NOT NULL');
        if (! $this->indexExists('agents_api_token_hash_unique')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->unique('api_token_hash', 'agents_api_token_hash_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agents') || ! Schema::hasColumn('agents', 'api_token_hash')) {
            return;
        }

        if ($this->indexExists('agents_api_token_hash_unique')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->dropUnique('agents_api_token_hash_unique');
            });
        }

        // This restores the column name only. Hashes cannot be reversed into
        // the original bearer tokens, so a rollback requires token rotation.
        Schema::table('agents', function (Blueprint $table) {
            $table->renameColumn('api_token_hash', 'api_token');
        });
    }

    private function indexExists(string $indexName): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) DB::scalar(
            "SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1",
            [$indexName]
        );
    }
};
