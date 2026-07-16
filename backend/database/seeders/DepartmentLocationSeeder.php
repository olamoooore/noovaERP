<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Seeds common departments and SG/MY office locations.
 * Run inside a tenant context:
 *   php artisan tenants:run db:seed --option="class=DepartmentLocationSeeder"
 *
 * Or for a single tenant (e.g. demo):
 *   php artisan tinker
 *   >>> Tenant::find('your-id')->run(fn() => app(\Database\Seeders\DepartmentLocationSeeder::class)->run());
 */
class DepartmentLocationSeeder extends Seeder
{
    public function run(): void
    {
        // ── Departments ───────────────────────────────────────────────────────
        // Common departments used in SG/MY corporate and SME environments.
        // Using updateOrCreate so re-running is safe.
        $departments = [
            // Executive
            ['code' => 'EXEC',  'name' => 'Executive',                  'description' => 'C-suite and board-level leadership'],
            // Core business functions
            ['code' => 'SALES', 'name' => 'Sales',                      'description' => 'Revenue generation and account management'],
            ['code' => 'MKT',   'name' => 'Marketing',                  'description' => 'Brand, digital, and growth marketing'],
            ['code' => 'OPS',   'name' => 'Operations',                  'description' => 'Day-to-day business operations'],
            ['code' => 'FIN',   'name' => 'Finance & Accounting',        'description' => 'Budgeting, reporting, and statutory compliance'],
            ['code' => 'HR',    'name' => 'Human Resources',             'description' => 'Talent acquisition, payroll, and people ops'],
            ['code' => 'IT',    'name' => 'Information Technology',      'description' => 'Infrastructure, security, and internal systems'],
            ['code' => 'ENGG',  'name' => 'Engineering',                 'description' => 'Software and hardware product development'],
            ['code' => 'PROD',  'name' => 'Product',                     'description' => 'Product strategy, roadmap, and UX'],
            ['code' => 'CS',    'name' => 'Customer Success',            'description' => 'Onboarding, support, and retention'],
            ['code' => 'LEGAL', 'name' => 'Legal & Compliance',          'description' => 'Contracts, licensing, and regulatory affairs'],
            ['code' => 'PROC',  'name' => 'Procurement',                 'description' => 'Vendor management and purchasing'],
            ['code' => 'LOG',   'name' => 'Logistics & Supply Chain',    'description' => 'Warehousing, distribution, and fulfilment'],
            ['code' => 'QA',    'name' => 'Quality Assurance',           'description' => 'Quality control and process improvement'],
            ['code' => 'ADMIN', 'name' => 'Administration',              'description' => 'Office management and general admin'],
            ['code' => 'R&D',   'name' => 'Research & Development',      'description' => 'Innovation and technical research'],
            ['code' => 'BD',    'name' => 'Business Development',        'description' => 'Partnerships, expansion, and new markets'],
            ['code' => 'HSEC',  'name' => 'Health, Safety & Security',   'description' => 'Workplace safety and physical security'],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(
                ['code' => $dept['code']],
                array_merge($dept, ['is_active' => true])
            );
        }

        // ── Locations ─────────────────────────────────────────────────────────
        // Remove old placeholder locations that are no longer valid.
        $removeCodes = ['SG-HQ', 'SG-ONE-N', 'SG-CBD', 'MY-KL', 'MY-PJ', 'MY-PG', 'MY-KK'];
        Location::whereIn('code', $removeCodes)->forceDelete();

        // Real SG and MY office locations.
        $locations = [
            // Singapore
            [
                'code' => 'SG-ALJ',
                'name' => 'Singapore — Aljunied',
                'city' => 'Singapore',
                'state' => 'East Region',
                'country' => 'Singapore',
                'postal_code' => '389836',
                'address' => '625 Aljunied Road, #05-08, Aljunied Industrial Complex',
                'description' => 'Singapore office at Aljunied Industrial Complex',
            ],
            [
                'code' => 'SG-HSC',
                'name' => 'Singapore — High Street Centre',
                'city' => 'Singapore',
                'state' => 'Central Region',
                'country' => 'Singapore',
                'postal_code' => '179094',
                'address' => '1 North Bridge Road, #03-41A, High Street Centre',
                'description' => 'Singapore office at High Street Centre',
            ],
            // Malaysia
            [
                'code' => 'MY-JB',
                'name' => 'Johor Bahru Office',
                'city' => 'Johor Bahru',
                'state' => 'Johor',
                'country' => 'Malaysia',
                'postal_code' => '80300',
                'address' => 'Johor Bahru',
                'description' => 'Malaysia office — Johor Bahru',
            ],
            // Remote / Virtual
            [
                'code' => 'REMOTE',
                'name' => 'Remote / Work From Home',
                'city' => null,
                'state' => null,
                'country' => null,
                'postal_code' => null,
                'address' => null,
                'description' => 'Fully remote employees not assigned to a physical office',
            ],
        ];

        foreach ($locations as $loc) {
            Location::updateOrCreate(
                ['code' => $loc['code']],
                array_merge($loc, ['is_active' => true])
            );
        }

        if ($this->command) {
            $this->command->info('Seeded '.count($departments).' departments and '.count($locations).' locations.');
        }
    }
}
