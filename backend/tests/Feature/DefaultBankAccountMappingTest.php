<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Currency;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class DefaultBankAccountMappingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
    }

    public function test_company_default_bank_account_overrides_legacy_mapping(): void
    {
        Currency::firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        $legacy = ChartOfAccount::where('code', '1020')->firstOrFail();
        $configured = ChartOfAccount::create([
            'code' => '1099',
            'name' => 'Configured Bank',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $mapping = AccountMapping::where('mapping_key', 'bank')->whereNull('document_type')->first();
        if ($mapping) {
            $mapping->update(['account_id' => $legacy->id, 'is_active' => true]);
        } else {
            AccountMapping::create([
                'mapping_key' => 'bank',
                'account_id' => $legacy->id,
                'document_type' => null,
                'is_active' => true,
            ]);
        }

        $settings = CompanySettings::query()->first() ?? new CompanySettings(['base_currency' => 'USD']);
        $settings->default_bank_account_id = $configured->id;
        $settings->save();

        $resolved = app(AccountMappingService::class)->resolve('bank');

        $this->assertSame($configured->id, $resolved->id);
    }
}
