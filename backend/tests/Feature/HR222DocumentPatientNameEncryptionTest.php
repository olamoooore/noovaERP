<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #222 (regression from the #159 fix) — the same patient-name PHI was
 * left in cleartext on invoices, quotations and delivery_order_lines while
 * only medical_cases.patient_name_encrypted was encrypted.
 */
class HR222DocumentPatientNameEncryptionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['invoices', 'quotations', 'delivery_orders', 'delivery_order_lines', 'customers']);

        $this->user = User::create([
            'name' => 'HR222 User',
            'email' => 'hr222-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeInvoice(string $patientName): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'created_by' => $this->user->id,
            'patient_name' => $patientName,
        ]);
    }

    private function makeQuotation(string $patientName): Quotation
    {
        return Quotation::create([
            'quotation_number' => 'QT-'.Str::upper(Str::random(8)),
            'customer_id' => Customer::factory()->create()->id,
            'quotation_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'patient_name' => $patientName,
        ]);
    }

    private function makeDeliveryOrderLine(string $patientName): DeliveryOrderLine
    {
        $order = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => null,
            'status' => 'draft',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        return DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'description' => 'HR222 line',
            'patient_name' => $patientName,
        ]);
    }

    private function raw(string $table, int $id): ?string
    {
        return DB::table($table)->where('id', $id)->value('patient_name_encrypted');
    }

    public function test_invoice_patient_name_is_encrypted_at_rest(): void
    {
        $invoice = $this->makeInvoice('Jane Doe');

        $this->assertFalse(Schema::hasColumn('invoices', 'patient_name'), 'Plaintext column must be gone');

        $raw = $this->raw('invoices', $invoice->id);
        $this->assertNotSame('Jane Doe', $raw, 'Value must be ciphertext at rest');
        $this->assertSame('Jane Doe', Crypt::decryptString($raw));

        $fresh = Invoice::find($invoice->id);
        $this->assertSame('Jane Doe', $fresh->patient_name);

        $array = $fresh->toArray();
        $this->assertSame('Jane Doe', $array['patient_name']);
        $this->assertArrayNotHasKey('patient_name_encrypted', $array, 'Ciphertext must stay hidden');
    }

    public function test_quotation_patient_name_is_encrypted_at_rest(): void
    {
        $quotation = $this->makeQuotation('Quotation Patient');

        $this->assertFalse(Schema::hasColumn('quotations', 'patient_name'));

        $raw = $this->raw('quotations', $quotation->id);
        $this->assertNotSame('Quotation Patient', $raw);
        $this->assertSame('Quotation Patient', Crypt::decryptString($raw));
        $this->assertSame('Quotation Patient', Quotation::find($quotation->id)->patient_name);
        $this->assertSame('Quotation Patient', Quotation::find($quotation->id)->toArray()['patient_name']);
    }

    public function test_delivery_order_line_patient_name_is_encrypted_at_rest(): void
    {
        $line = $this->makeDeliveryOrderLine('DO Patient');

        $this->assertFalse(Schema::hasColumn('delivery_order_lines', 'patient_name'));

        $raw = $this->raw('delivery_order_lines', $line->id);
        $this->assertNotSame('DO Patient', $raw);
        $this->assertSame('DO Patient', Crypt::decryptString($raw));
        $this->assertSame('DO Patient', DeliveryOrderLine::find($line->id)->patient_name);
        $this->assertSame('DO Patient', DeliveryOrderLine::find($line->id)->toArray()['patient_name']);
    }

    public function test_updates_re_encrypt_and_null_clears(): void
    {
        $invoice = $this->makeInvoice('First Name');

        $invoice->update(['patient_name' => 'Second Name']);
        $raw = $this->raw('invoices', $invoice->id);
        $this->assertSame('Second Name', Crypt::decryptString($raw));
        $this->assertSame('Second Name', Invoice::find($invoice->id)->patient_name);

        $invoice->update(['patient_name' => null]);
        $this->assertNull($this->raw('invoices', $invoice->id));
        $this->assertNull(Invoice::find($invoice->id)->patient_name);
    }
}
