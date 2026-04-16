<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\SuperAdmin\Domain\AdminNote;
use App\SuperAdmin\Domain\TenantHealthSnapshot;
use App\SuperAdmin\Domain\TenantOperation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Tenant360Seeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'platform_super_admin')->first();

        $tenants = [
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'acme-corp',
                'data'   => [
                    'name'      => 'Acme Corporation',
                    'email'     => 'admin@acme-corp.com',
                    'plan'      => 'starter',
                    'suspended' => false,
                ],
                'status'    => 'active',
                'ops'       => 3,
                'notes'     => 2,
                'healthy'   => true,
            ],
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'globex-inc',
                'data'   => [
                    'name'      => 'Globex Inc',
                    'email'     => 'admin@globex-inc.com',
                    'plan'      => 'professional',
                    'suspended' => false,
                ],
                'status'  => 'active',
                'ops'     => 1,
                'notes'   => 0,
                'healthy' => true,
            ],
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'initech-llc',
                'data'   => [
                    'name'      => 'Initech LLC',
                    'email'     => 'admin@initech-llc.com',
                    'plan'      => 'starter',
                    'suspended' => true,
                ],
                'status'  => 'suspended',
                'ops'     => 1,
                'notes'   => 1,
                'healthy' => true,
            ],
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'umbrella-co',
                'data'   => [
                    'name'         => 'Umbrella Co',
                    'email'        => 'admin@umbrella-co.com',
                    'plan'         => 'trial',
                    'suspended'    => false,
                    'trial_ends_at'=> now()->addDays(14)->toIso8601String(),
                ],
                'status'  => 'trial',
                'ops'     => 0,
                'notes'   => 0,
                'healthy' => true,
            ],
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'wayne-ent',
                'data'   => [
                    'name'      => 'Wayne Enterprises',
                    'email'     => 'admin@wayne-ent.com',
                    'plan'      => 'professional',
                    'suspended' => false,
                ],
                'status'  => 'active',
                'ops'     => 2,
                'notes'   => 1,
                'healthy' => false, // db_connected=false
            ],
            [
                'id'     => Str::uuid()->toString(),
                'slug'   => 'stark-ind',
                'data'   => [
                    'name'         => 'Stark Industries',
                    'email'        => 'admin@stark-ind.com',
                    'plan'         => 'trial',
                    'suspended'    => false,
                    'trial_ends_at'=> now()->subDays(3)->toIso8601String(),
                ],
                'status'  => 'trial_expired',
                'ops'     => 0,
                'notes'   => 0,
                'healthy' => true,
            ],
        ];

        foreach ($tenants as $t) {
            $data = $t['data'];
            // Insert tenant with real columns + data bag for extras
            DB::table('tenants')->insertOrIgnore([
                'id'                   => $t['id'],
                'name'                 => $data['name'],
                'email'                => $data['email'],
                'plan'                 => $data['plan'],
                'trial_ends_at'        => $data['trial_ends_at'] ?? null,
                'subscription_ends_at' => $data['subscription_ends_at'] ?? null,
                'data'                 => json_encode(['suspended' => $data['suspended'] ?? false]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // Insert domain
            DB::table('domains')->insertOrIgnore([
                'domain'     => $t['slug'] . '.localhost',
                'tenant_id'  => $t['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Health snapshots
            $healthy = $t['healthy'];
            for ($i = 0; $i < 2; $i++) {
                TenantHealthSnapshot::create([
                    'tenant_id'          => $t['id'],
                    'db_connected'       => $healthy,
                    'storage_usage_mb'   => $healthy ? rand(10, 500) : null,
                    'last_login_at'      => $healthy ? now()->subHours(rand(1, 72)) : null,
                    'error_rate_percent' => $healthy ? round(rand(0, 5) / 10, 2) : null,
                    'response_time_ms'   => $healthy ? rand(50, 300) : null,
                    'metadata'           => null,
                    'created_at'         => now()->subHours($i * 6),
                ]);
            }

            // Operations
            $operationTypes = ['suspend', 'reinstate', 'ping_health'];
            for ($i = 0; $i < $t['ops']; $i++) {
                $op = $t['status'] === 'suspended' && $i === 0 ? 'suspend' : $operationTypes[$i % count($operationTypes)];
                TenantOperation::create([
                    'tenant_id'     => $t['id'],
                    'admin_user_id' => $admin?->id,
                    'operation'     => $op,
                    'payload'       => ['reason' => 'Seeded operation'],
                    'result'        => ['ok' => true],
                    'status'        => 'success',
                    'created_at'    => now()->subHours(rand(1, 48)),
                ]);
            }

            // Notes
            $noteBodies = [
                'Tenant requested feature flag for advanced reporting module.',
                'Billing issue resolved — invoice resent manually.',
                'Customer confirmed migration from legacy ERP completed.',
            ];
            for ($i = 0; $i < $t['notes']; $i++) {
                AdminNote::create([
                    'tenant_id'     => $t['id'],
                    'admin_user_id' => $admin?->id,
                    'body'          => $noteBodies[$i % count($noteBodies)],
                    'created_at'    => now()->subHours(rand(1, 24)),
                    'updated_at'    => now()->subHours(rand(1, 24)),
                ]);
            }
        }

        $this->command->info('Tenant360Seeder: 6 tenants seeded (active, suspended, trial, expired, healthy, unhealthy).');
    }
}
