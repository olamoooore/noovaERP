<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReportingService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #275: filing calendar statuses + registration/threshold gate.
 */
class TaxComplianceCalendarTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);
    }

    public function test_sg_calendar_reports_filed_overdue_and_open(): void
    {
        $service = app(TaxReportingService::class);
        $calendar = collect($service->filingCalendar('SG', 2026));

        $this->assertCount(4, $calendar);
        $this->assertSame('2026-04-30', $calendar->firstWhere('period', '2026-Q1')['due_date']);
        $this->assertSame('overdue', $calendar->firstWhere('period', '2026-Q1')['status']);
        $this->assertSame('open', $calendar->firstWhere('period', '2026-Q4')['status']);

        // Filing Q1 flips it to filed.
        $this->postJson('/api/v1/accounting/statutory-returns/SG/2026-Q1/file')->assertStatus(201);
        $calendar = collect($service->filingCalendar('SG', 2026));
        $this->assertSame('filed', $calendar->firstWhere('period', '2026-Q1')['status']);

        $this->getJson('/api/v1/accounting/tax-report/compliance-calendar?country=SG&year=2026')
            ->assertStatus(200)
            ->assertJsonPath('data.calendar.0.period', '2026-Q1');
    }

    public function test_threshold_exceeded_without_registration_is_a_blocking_exception(): void
    {
        $cash = ChartOfAccount::factory()->create(['code' => 'CC-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => 'CC-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $gst = ChartOfAccount::factory()->create(['code' => 'CC-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'CC-SST-8'], ['name' => 'CC SST 8', 'type' => 'service', 'rate' => 8, 'is_active' => true]);
        $ruleId = (int) Tax::query()->where('code', 'CC-SST-8')->value('id');

        $taxable = 600000.0;
        $tax = round($taxable * 0.08, 2);

        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Threshold crossing sale',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cash->id, debit: $taxable + $tax, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $revenue->id, debit: 0.0, credit: $taxable),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $gst->id, debit: 0.0, credit: $tax, taxCode: 'CC-SST-8', taxId: $ruleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'MY', 'tax_amount' => $tax,
                'tax_lines' => [[
                    'tax_code' => 'CC-SST-8', 'jurisdiction' => 'MY', 'taxable_base' => $taxable,
                    'tax_rate' => 8.0, 'tax_amount' => $tax, 'treatment' => 'standard_rated',
                ]],
            ],
        ));
        $this->assertTrue($result->success, $result->error ?? '');

        $service = app(TaxReportingService::class);
        $status = $service->registrationStatus('MY');
        $this->assertTrue($status['threshold_exceeded'], 'MY turnover over 500k must exceed the threshold.');
        $this->assertFalse($status['registered']);

        $codes = collect($service->exceptions(now()->format('Y-m')))->pluck('code')->all();
        $this->assertContains('REGISTRATION_REQUIRED', $codes);

        $readiness = $service->readiness(now()->format('Y-m'));
        $this->assertTrue($readiness['close_blocking']);
    }
}
