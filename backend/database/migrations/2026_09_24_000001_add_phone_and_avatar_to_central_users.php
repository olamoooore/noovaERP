<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add phone + avatar columns to central public.users so the authenticated
     * user's profile is editable from a single Profile page regardless of
     * tenant context. The per-tenant users table already has these columns
     * (database/migrations/tenant/2024_01_01_000001_create_users_table.php);
     * the central table historically did not.
     *
     * avatar stores the public-disk path (e.g. "avatars/abc.jpg") — the
     * User model exposes a getAvatarUrlAttribute() accessor that resolves
     * it to an absolute URL via the public storage disk.
     */
    public function up(): void
    {
        // Idempotent — some live central `users` schemas already carry
        // these columns from older manual patches. Skip silently if so.
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar')->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar']);
        });
    }
};
