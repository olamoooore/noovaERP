<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Illuminate\Support\Facades\DB;
use Tests\Feature\HR116ContactPermissionEnforcementTest;

/**
 * Hostile review repro (#116 B1 + new): Viewer must not read HR salary; Sales
 * must not write it. Real router + RequirePermission + seeded matrix.
 */
class HRV116SensitiveProfileAccessTest extends HR116ContactPermissionEnforcementTest
{
    private function hrvCall(string $m, ...$a)
    {
        return \Closure::bind(fn () => $this->{$m}(...$a), $this, HR116ContactPermissionEnforcementTest::class)();
    }

    public function test_sales_can_write_and_viewer_can_read_hr_salary(): void
    {
        $contactId = $this->hrvCall('makeContact');

        $sales = $this->hrvCall('actingAsRole', 'sales', 'Sales');
        $write = $this->actingAs($sales)->postJson("/api/v1/contacts/{$contactId}/profiles/hr", [
            'employee_id' => 'E-1', 'salary' => 12345, 'salary_currency' => 'SGD',
        ]);

        $viewer = $this->hrvCall('actingAsRole', 'viewer', 'Viewer');
        $read = $this->actingAs($viewer)->getJson("/api/v1/contacts/{$contactId}/profiles");

        fwrite(STDERR, sprintf("\n[HRV116] sales POST profiles/hr -> %d ; viewer GET profiles -> %d, hr.salary=%s, stored_row=%s\n",
            $write->getStatusCode(), $read->getStatusCode(),
            var_export($read->json('data.hr.salary'), true),
            DB::table('hr_staff_profiles')->where('contact_id', $contactId)->exists() ? 'yes' : 'no'));

        $this->assertSame(403, $write->getStatusCode(), 'Sales must not write HR salary');
        $this->assertNull($read->json('data.hr.salary'), 'Viewer must not read HR salary');
    }
}
