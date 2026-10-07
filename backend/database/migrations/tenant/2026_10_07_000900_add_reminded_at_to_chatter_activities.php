<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #410: due-activity reminders. `reminded_at` records that the assignee
 * was reminded about the activity's current due date, so the hourly sweep
 * reminds once; snoozing clears it. Additive and nullable — existing rows are
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatter_activities') || Schema::hasColumn('chatter_activities', 'reminded_at')) {
            return;
        }

        Schema::table('chatter_activities', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chatter_activities') && Schema::hasColumn('chatter_activities', 'reminded_at')) {
            Schema::table('chatter_activities', function (Blueprint $table) {
                $table->dropColumn('reminded_at');
            });
        }
    }
};
