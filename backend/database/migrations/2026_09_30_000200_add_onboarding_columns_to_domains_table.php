<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-PLAT-03 / A-26 — domain onboarding columns on the CENTRAL domains table.
 *
 * Existing rows are all platform-created subdomains from signup, so the
 * defaults mirror that truth: type=platform, dns_status=verified (the
 * platform controls the zone, nothing to verify). Custom domains inserted
 * by the onboarding service always set dns_status=pending explicitly and
 * only resolve once verified — see DomainOnboardingService + the
 * InitializeTenantForPublicAccess servability gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('type', 16)->default('platform')->after('domain');
            $table->boolean('is_primary')->default(false)->after('type');
            $table->string('dns_status', 16)->default('verified')->after('is_primary');
            $table->string('ssl_status', 16)->default('unknown')->after('dns_status');
            $table->string('verification_token', 64)->nullable()->unique()->after('ssl_status');
            $table->timestamp('verified_at')->nullable()->after('verification_token');
            $table->timestamp('cert_expires_at')->nullable()->after('verified_at');
            $table->timestamp('last_checked_at')->nullable()->after('cert_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn([
                'type', 'is_primary', 'dns_status', 'ssl_status',
                'verification_token', 'verified_at', 'cert_expires_at', 'last_checked_at',
            ]);
        });
    }
};
