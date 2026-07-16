<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialReportingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->info('Seeding Financial Reporting data...');

        // Seed Departments
        $this->seedDepartments();

        // Seed Projects
        $this->seedProjects();

        // Seed Locations
        $this->seedLocations();

        // Seed Vendors
        $this->seedVendors();

        // Seed Customers
        $this->seedCustomers();

        // Seed Products (if not enough)
        $this->seedProducts();

        // Seed Invoices and Invoice Lines
        $this->seedInvoices();

        // Seed Stock Movements
        $this->seedStockMovements();

        $this->info('Financial Reporting data seeded successfully!');
    }

    private function info($message): void
    {
        if ($this->command) {
            $this->info($message);
        } else {
            echo $message."\n";
        }
    }

    private function warn($message): void
    {
        if ($this->command) {
            $this->warn($message);
        } else {
            echo '⚠ '.$message."\n";
        }
    }

    private function seedDepartments(): void
    {
        $departments = [
            ['name' => 'Sales', 'code' => 'SALES', 'is_active' => true],
            ['name' => 'Marketing', 'code' => 'MKTG', 'is_active' => true],
            ['name' => 'Operations', 'code' => 'OPS', 'is_active' => true],
            ['name' => 'IT', 'code' => 'IT', 'is_active' => true],
            ['name' => 'Finance', 'code' => 'FIN', 'is_active' => true],
            ['name' => 'HR', 'code' => 'HR', 'is_active' => true],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(
                ['code' => $dept['code']],
                $dept
            );
        }

        $this->info('✓ Departments seeded');
    }

    private function seedProjects(): void
    {
        $projects = [
            ['name' => 'Website Redesign', 'code' => 'WEB-001', 'status' => 'active'],
            ['name' => 'Mobile App Development', 'code' => 'APP-001', 'status' => 'active'],
            ['name' => 'ERP Implementation', 'code' => 'ERP-001', 'status' => 'active'],
            ['name' => 'Marketing Campaign Q1', 'code' => 'MKT-Q1', 'status' => 'completed'],
        ];

        foreach ($projects as $project) {
            Project::firstOrCreate(
                ['code' => $project['code']],
                $project
            );
        }

        $this->info('✓ Projects seeded');
    }

    private function seedLocations(): void
    {
        $locations = [
            ['name' => 'Main Office', 'code' => 'HQ', 'is_active' => true],
            ['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true],
            ['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true],
            ['name' => 'Retail Store 1', 'code' => 'STORE-1', 'is_active' => true],
        ];

        foreach ($locations as $location) {
            Location::firstOrCreate(
                ['code' => $location['code']],
                $location
            );
        }

        $this->info('✓ Locations seeded');
    }

    private function seedVendors(): void
    {
        $vendors = [
            [
                'name' => 'Tech Supplies Inc',
                'email' => 'sales@techsupplies.com',
                'phone' => '+1-555-0101',
                'company' => 'Tech Supplies Inc',
                'payment_terms' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Office Depot',
                'email' => 'orders@officedepot.com',
                'phone' => '+1-555-0102',
                'company' => 'Office Depot LLC',
                'payment_terms' => 15,
                'is_active' => true,
            ],
            [
                'name' => 'Global Logistics',
                'email' => 'billing@globallogistics.com',
                'phone' => '+1-555-0103',
                'company' => 'Global Logistics Corp',
                'payment_terms' => 45,
                'is_active' => true,
            ],
            [
                'name' => 'Cloud Services Pro',
                'email' => 'support@cloudservices.com',
                'phone' => '+1-555-0104',
                'company' => 'Cloud Services Pro',
                'payment_terms' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Marketing Agency Plus',
                'email' => 'hello@marketingplus.com',
                'phone' => '+1-555-0105',
                'company' => 'Marketing Agency Plus',
                'payment_terms' => 30,
                'is_active' => true,
            ],
        ];

        foreach ($vendors as $vendor) {
            DB::table('vendors')->updateOrInsert(
                ['email' => $vendor['email']],
                array_merge($vendor, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->info('✓ Vendors seeded');
    }

    private function seedCustomers(): void
    {
        $customers = [
            [
                'name' => 'Acme Corporation',
                'email' => 'billing@acme.com',
                'phone' => '+1-555-1001',
                'company' => 'Acme Corporation',
                'credit_limit' => 50000,
                'payment_terms' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'TechStart Inc',
                'email' => 'accounts@techstart.com',
                'phone' => '+1-555-1002',
                'company' => 'TechStart Inc',
                'credit_limit' => 25000,
                'payment_terms' => 15,
                'is_active' => true,
            ],
            [
                'name' => 'Global Enterprises',
                'email' => 'finance@globalent.com',
                'phone' => '+1-555-1003',
                'company' => 'Global Enterprises Ltd',
                'credit_limit' => 100000,
                'payment_terms' => 45,
                'is_active' => true,
            ],
            [
                'name' => 'Small Business Co',
                'email' => 'owner@smallbiz.com',
                'phone' => '+1-555-1004',
                'company' => 'Small Business Co',
                'credit_limit' => 10000,
                'payment_terms' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Retail Chain LLC',
                'email' => 'purchasing@retailchain.com',
                'phone' => '+1-555-1005',
                'company' => 'Retail Chain LLC',
                'credit_limit' => 75000,
                'payment_terms' => 30,
                'is_active' => true,
            ],
        ];

        foreach ($customers as $customer) {
            Customer::updateOrCreate(
                ['email' => $customer['email']],
                $customer
            );
        }

        $this->info('✓ Customers seeded');
    }

    private function seedProducts(): void
    {
        $existingCount = Product::count();

        if ($existingCount >= 10) {
            $this->info('✓ Products already seeded (skipping)');

            return;
        }

        $products = [
            ['name' => 'Laptop Pro 15"', 'sku' => 'LAP-001', 'cost' => 800, 'price' => 1200, 'stock_quantity' => 50],
            ['name' => 'Wireless Mouse', 'sku' => 'MOU-001', 'cost' => 15, 'price' => 29.99, 'stock_quantity' => 200],
            ['name' => 'Mechanical Keyboard', 'sku' => 'KEY-001', 'cost' => 50, 'price' => 89.99, 'stock_quantity' => 100],
            ['name' => 'USB-C Hub', 'sku' => 'HUB-001', 'cost' => 25, 'price' => 49.99, 'stock_quantity' => 150],
            ['name' => 'Monitor 27"', 'sku' => 'MON-001', 'cost' => 200, 'price' => 349.99, 'stock_quantity' => 75],
            ['name' => 'Webcam HD', 'sku' => 'CAM-001', 'cost' => 40, 'price' => 79.99, 'stock_quantity' => 120],
            ['name' => 'Headset Pro', 'sku' => 'HEAD-001', 'cost' => 60, 'price' => 119.99, 'stock_quantity' => 90],
            ['name' => 'Desk Lamp LED', 'sku' => 'LAMP-001', 'cost' => 20, 'price' => 39.99, 'stock_quantity' => 180],
            ['name' => 'Office Chair', 'sku' => 'CHAIR-001', 'cost' => 150, 'price' => 299.99, 'stock_quantity' => 40],
            ['name' => 'Standing Desk', 'sku' => 'DESK-001', 'cost' => 300, 'price' => 599.99, 'stock_quantity' => 25],
        ];

        foreach ($products as $product) {
            Product::firstOrCreate(
                ['sku' => $product['sku']],
                array_merge($product, [
                    'description' => 'High quality '.$product['name'],
                    'is_active' => true,
                ])
            );
        }

        $this->info('✓ Products seeded');
    }

    private function seedInvoices(): void
    {
        $customers = Customer::all();
        $products = Product::all();
        $user = DB::table('users')->first();

        if ($customers->isEmpty() || $products->isEmpty() || ! $user) {
            $this->warn('⚠ Skipping invoices (missing customers, products, or users)');

            return;
        }

        // Check if invoices already exist
        if (Invoice::count() > 0) {
            $this->info('✓ Invoices already seeded (skipping)');

            return;
        }

        // Create invoices for the last 3 months
        $startDate = Carbon::now()->subMonths(3);
        $invoiceNumber = 1;

        for ($i = 0; $i < 30; $i++) {
            $customer = $customers->random();
            $invoiceDate = $startDate->copy()->addDays(rand(0, 90));
            $dueDate = $invoiceDate->copy()->addDays($customer->payment_terms);

            $invoice = Invoice::create([
                'invoice_number' => 'INV-'.date('Y').'-'.str_pad($invoiceNumber++, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'status' => $invoiceDate->lt(Carbon::now()->subDays(30)) ? 'paid' : (rand(0, 1) ? 'paid' : 'sent'),
                'created_by' => $user->id,
            ]);

            // Add 1-5 line items
            $lineCount = rand(1, 5);
            $subtotal = 0;

            for ($j = 0; $j < $lineCount; $j++) {
                $product = $products->random();
                $quantity = rand(1, 10);
                $unitPrice = $product->price;
                $lineTotal = $quantity * $unitPrice;
                $subtotal += $lineTotal;

                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 10,
                    'tax_amount' => $lineTotal * 0.10,
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $taxAmount = $subtotal * 0.10;
            $total = $subtotal + $taxAmount;

            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $invoice->status === 'paid' ? $total : 0,
                'balance' => $invoice->status === 'paid' ? 0 : $total,
            ]);
        }

        $this->info('✓ Invoices seeded (30 invoices with line items)');
    }

    private function seedStockMovements(): void
    {
        $products = Product::all();
        $user = DB::table('users')->first();

        if ($products->isEmpty() || ! $user) {
            $this->warn('⚠ Skipping stock movements (missing products or users)');

            return;
        }

        // Check if stock movements already exist
        if (StockMovement::count() > 0) {
            $this->info('✓ Stock movements already seeded (skipping)');

            return;
        }

        $startDate = Carbon::now()->subMonths(3);

        foreach ($products as $product) {
            // Initial stock IN
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'in',
                'quantity' => $product->stock_quantity,
                'reference' => 'INITIAL-STOCK',
                'notes' => 'Initial stock',
                'created_by' => $user->id,
                'created_at' => $startDate,
                'updated_at' => $startDate,
            ]);

            // Random movements over 3 months
            for ($i = 0; $i < rand(5, 15); $i++) {
                $type = rand(0, 1) ? 'in' : 'out';
                $quantity = rand(1, 20);
                $movementDate = $startDate->copy()->addDays(rand(0, 90));

                StockMovement::create([
                    'product_id' => $product->id,
                    'type' => $type,
                    'quantity' => $quantity,
                    'reference' => $type === 'in' ? 'PO-'.rand(1000, 9999) : 'SO-'.rand(1000, 9999),
                    'notes' => $type === 'in' ? 'Purchase order' : 'Sales order',
                    'created_by' => $user->id,
                    'created_at' => $movementDate,
                    'updated_at' => $movementDate,
                ]);
            }
        }

        $this->info('✓ Stock movements seeded');
    }
}
