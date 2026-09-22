<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Tests\TenantTestCase;

/**
 * Issue #80 — the dead email reply-to/threading columns were removed from
 * communication_messages and the model no longer advertises email threading
 * (no incoming-email integration exists).
 */
class HR80CommunicationDeadEmailColumnsTest extends TenantTestCase
{
    private const DEAD_COLUMNS = [
        'email_from', 'email_to', 'email_cc', 'email_bcc',
        'email_message_id', 'email_in_reply_to', 'parent_id',
    ];

    public function test_dead_email_columns_no_longer_exist(): void
    {
        if (Schema::hasColumn('communication_messages', 'email_from')) {
            // Central module migration may be excluded by the tenant test
            // harness's --path runs; apply it explicitly for this assertion.
            Artisan::call('migrate', [
                '--path' => base_path('modules/Communication/database/migrations/2026_09_22_000001_remove_dead_email_columns_from_communication_messages.php'),
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertFalse(
                Schema::hasColumn('communication_messages', $column),
                "{$column} must be removed (dead email-threading field)"
            );
        }
    }

    public function test_model_no_longer_advertises_email_threading(): void
    {
        $fillable = (new CommunicationMessage)->getFillable();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertNotContains($column, $fillable, "{$column} must not be fillable");
        }
    }
}
