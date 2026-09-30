<?php

namespace Modules\AI\Data;

/**
 * NoovaERP platform knowledge — what the ERP contains, where every module
 * lives, and how to get common jobs done. Ingested into the tenant
 * knowledge base (source: `platform`) so the assistant is platform-aware:
 * it can answer "what can you do?", "how do I create an invoice?" or
 * "where is the stock page?" with accurate, clickable links.
 *
 * Paths mirror the SPA route table (frontend/src/App.tsx). Keep them in
 * sync when routes move.
 */
class PlatformKnowledge
{
    /**
     * @return array<int, array{category: string, question: string, answer: string, keywords?: string[], priority?: int}>
     */
    public static function all(): array
    {
        $entry = fn (string $question, string $answer, array $keywords = [], int $priority = 20) => [
            'category' => 'platform',
            'question' => $question,
            'answer' => $answer,
            'keywords' => $keywords,
            'priority' => $priority,
        ];

        return [
            $entry(
                'What is NoovaERP and what can this AI assistant do?',
                "NoovaERP is your all-in-one ERP: sales & invoicing, quotations, purchases, inventory, accounting & tax, payments, loyalty, CRM, HRM, POS, financial close, events and more.\n\nThis assistant can DO right now:\n• Orders & invoices — check a status, list your orders/invoices, outstanding/overdue/recent payments, customer balance and ledger\n• Loyalty — points, tier, rewards, redemption history, tier progress\n• Products & inventory — search products, prices, availability, low/out-of-stock lists, warehouses, stock movements\n• Accounting — revenue summaries, P&L reports, journal entries, supplier bills, AP coding suggestions, collections prioritisation, close anomalies\n• Knowledge — answer anything about your shop's policies and how NoovaERP works, and **guide you to the exact page** (I include clickable links)\n• Support — create a support ticket for anything I can't resolve\n\nI cannot click or create records inside module forms (invoices, products, payments) — I guide you to the right page with a link instead.",
                ['what can you do', 'capabilities', 'help', 'noovaerp', 'assistant'],
                30,
            ),
            $entry(
                'Where is the dashboard and the built-in dashboards?',
                'Main dashboard: [Dashboard](/dashboard). Role dashboards: [Executive](/dashboards/executive), [Financial](/dashboards/financial), [Sales](/dashboards/sales) and [Inventory](/dashboards/inventory).',
                ['dashboard', 'home', 'overview'],
            ),
            $entry(
                'Invoices module — where is it and what can I do there?',
                'Open [Invoices](/invoices) to list and manage sales invoices. Create one at [New invoice](/invoices/create), record a payment from the invoice page, and import in bulk at [Invoice import](/invoices/import). I can also look up any invoice, outstanding/overdue balances and recent payments for you right here.',
                ['invoices', 'invoice page', 'create invoice', 'new invoice'],
                25,
            ),
            $entry(
                'How do I create an invoice?',
                "Go to [New invoice](/invoices/create). Pick the customer, add line items (products or free text), set quantities/prices and save. If the invoice should post to the ledger, post it from the invoice page once reviewed. I can't create it from the chat — but I can check customers, prices and stock while you do.",
                ['create invoice', 'new invoice', 'make invoice', 'bill customer'],
                28,
            ),
            $entry(
                'Quotations module — where is it?',
                'Open [Quotations](/quotations) to manage quotations. Create at [New quotation](/quotations/create); accepted quotes can be converted into invoices.',
                ['quotation', 'quote', 'estimate'],
            ),
            $entry(
                'Sales orders and sales reports — where are they?',
                'Sales orders: [Sales orders](/sales/orders). Quotes: [Sales quotes](/sales/quotes). Price lists: [Price lists](/sales/price-lists). Bonus schemes: [Bonus schemes](/sales/bonus-schemes). Sales analytics: [Sales analytics](/reports/sales-analytics).',
                ['sales order', 'sales', 'sell'],
            ),
            $entry(
                'Delivery orders — where do I manage deliveries?',
                'Open [Delivery orders](/delivery-orders) to track deliveries and statuses. Create at [New delivery order](/delivery-orders/create).',
                ['delivery', 'delivery order', 'shipping'],
            ),
            $entry(
                'Customers and contacts — where do I manage them?',
                'Customers: [Customers](/customers) — add/import at [Import customers](/customers/import). Unified contacts (customers + vendors): [Master contacts](/master-contacts).',
                ['customer', 'contact', 'client', 'add customer'],
            ),
            $entry(
                'Products & catalog — where do I manage products?',
                'Open [Products](/products). Add at [New product](/products/new), import at [Product import](/products/import). Catalog health: [Product dashboard](/products/dashboard). Default margins/pricing rules: [Product settings](/products/settings).',
                ['product', 'catalog', 'add product', 'items'],
                25,
            ),
            $entry(
                'How do I add a product?',
                'Go to [New product](/products/new): set the name, SKU (or let it auto-generate), price/cost, category and whether stock is tracked. Save, and it appears in [Products](/products).',
                ['add product', 'new product', 'create product'],
                25,
            ),
            $entry(
                'Inventory & stock — where do I manage stock?',
                'Stock levels: [Stock](/inventory/stock). All movements: [Movements](/inventory/movements). Warehouses: [Warehouses](/inventory/warehouses). Transfers: [Transfers](/inventory/transfers). Settings (thresholds, SME mode): [Inventory settings](/inventory/settings).',
                ['inventory', 'stock', 'warehouse', 'stock level'],
                25,
            ),
            $entry(
                'How do I receive stock or adjust stock?',
                'Use [Stock operations](/inventory/operations): Receive Stock, Remove Stock or Reconcile Count. Direct stock entry requires **SME Mode** — enable it at [Inventory settings](/inventory/settings). Larger businesses can instead use the Purchase Order workflow at [Purchase orders](/inventory/purchase-orders). I can show you low/out-of-stock lists and movements right here.',
                ['receive stock', 'stock in', 'adjust stock', 'add stock', 'reconcile'],
                25,
            ),
            $entry(
                'Accounting core — where are journal entries, chart of accounts and journals?',
                'Accounting home: [Accounting](/accounting). Journal entries: [Journal entries](/accounting/core/journal-entries). Chart of accounts: [Chart of accounts](/accounting/core/chart-of-accounts). Journals: [Journals](/accounting/core/journals). Lock dates: [Lock dates](/accounting/core/lock-dates). Multi-currency: [Multi-currency](/accounting/core/multi-currency).',
                ['journal', 'journal entry', 'chart of accounts', 'accounting'],
            ),
            $entry(
                'Accounting reports — P&L, balance sheet, trial balance, general ledger?',
                'All reports live under [Accounting reporting](/accounting/reporting): [Profit & Loss](/accounting/reporting/profit-loss), [Balance sheet](/accounting/reporting/balance-sheet), [Trial balance](/accounting/reporting/trial-balance), [General ledger](/accounting/reporting/general-ledger), [Cash flow](/accounting/reporting/cash-flow), [Aged receivables](/accounting/reporting/aged-receivables) and [Aged payables](/accounting/reporting/aged-payables). I can pull revenue summaries and P&L data for you in the chat.',
                ['profit and loss', 'p&l', 'balance sheet', 'trial balance', 'general ledger', 'report'],
                25,
            ),
            $entry(
                'Customer receivables — bills, payments and statements?',
                'Under accounting: [Customer bills](/accounting/customers/bills), [Customer payments](/accounting/customers/payments), [Credit notes](/accounting/customers/credit-notes), [Statements](/accounting/customers/statements) and [Transactions](/accounting/customers/transactions). I can check outstanding/overdue invoices and customer balances in the chat.',
                ['receivable', 'customer payment', 'statement', 'credit note', 'ar'],
            ),
            $entry(
                'Vendors & payables — supplier bills and payments?',
                'Under accounting: [Vendors](/accounting/vendors), [Vendor bills](/accounting/vendors/bills), [Vendor payments](/accounting/vendors/payments), [Debit notes](/accounting/vendors/debit-notes). I can review supplier invoices and suggest AP account coding.',
                ['vendor', 'supplier', 'payable', 'ap', 'supplier bill'],
            ),
            $entry(
                'Payments — where do I track payments and payment analytics?',
                '[Payments](/payments) lists payment activity; [Payment analytics](/payment-analytics) has trends. Gateway settings live with your storefront/payment configuration.',
                ['payment', 'payments', 'payment analytics'],
            ),
            $entry(
                'Tax engine, tax reports and AI tax advisory — where?',
                '[Tax engine](/accounting/tax-engine) shows rules, packs and thresholds. [Tax report](/accounting/tax-report) and [Tax returns](/accounting/tax-returns) cover filing. For AI-powered tax guidance open [AI Tax Advisory](/ai/tax-advisory) — it uses your configured AI provider.',
                ['tax', 'gst', 'vat', 'tax report', 'tax advisory'],
            ),
            $entry(
                'Reports hub — sales, inventory, financial and expense analytics?',
                'Reports: [Sales analytics](/reports/sales-analytics), [Inventory report](/reports/inventory), [Financial reports](/reports/financial) and [Expense analytics](/reports/expense-analytics).',
                ['reports', 'analytics', 'reporting'],
            ),
            $entry(
                'Loyalty — where do I manage points, rewards and member tiers?',
                "Open [Loyalty](/loyalty): [Members](/loyalty/customers), [Rewards](/loyalty/rewards), [Redemptions](/loyalty/redemptions), [Loyalty settings](/loyalty/settings). I can check a member's points, tier and available rewards in the chat.",
                ['loyalty', 'points', 'rewards', 'tier', 'membership'],
            ),
            $entry(
                'HRM — employees, attendance, leave and payroll?',
                'Open [HRM](/hrm): [Employees](/hrm/employees), [Attendance](/hrm/attendance), [Leave requests](/hrm/leave-requests), [Payroll](/hrm/payroll), [Contracts](/hrm/contracts), [Timeshifts](/hrm/timeshifts).',
                ['hrm', 'employee', 'payroll', 'attendance', 'leave'],
            ),
            $entry(
                'CRM — where are leads managed?',
                'Open [CRM](/crm) and [Leads](/crm/leads) to manage the pipeline; create a lead from the leads page.',
                ['crm', 'lead', 'pipeline', 'sales lead'],
            ),
            $entry(
                'POS — terminal, orders, barcodes and offline queue?',
                'Open [POS](/pos): [Terminal](/pos/terminal), [Orders](/pos/orders), [Reports](/pos/reports), [Barcodes](/pos/barcodes), [Approvals](/pos/approvals), [Offline queue](/pos/offline-queue), [POS settings](/pos/settings).',
                ['pos', 'point of sale', 'cashier', 'terminal'],
            ),
            $entry(
                'Financial close — cycles, tasks, readiness and audit trail?',
                'Open [Financial close](/financial-close): [Cycles](/financial-close/cycles), [Tasks](/financial-close/tasks), [Readiness](/financial-close/readiness), [Review queue](/financial-close/review-queue), [Findings](/financial-close/findings), [Forecast](/financial-close/forecast) and [Audit trail](/financial-close/audit-trail).',
                ['close', 'month end', 'financial close', 'readiness'],
            ),
            $entry(
                'Events, field service, OCR and IoT — where are they?',
                'Events: [Event management](/event-management) (dashboard, bookings, tickets, staff, feedback). Field service: [Field service](/field-service) (work orders, schedule, teams, technicians). OCR bookkeeping: [OCR documents](/ocr/documents). IoT: [IoT hub](/iot-hub).',
                ['event', 'field service', 'work order', 'ocr', 'iot'],
            ),
            $entry(
                'Approvals and auditor portal — where?',
                'Approvals queue: [Approvals](/approvals). Auditor portal: [Auditor dashboard](/auditor/dashboard) (activity, queries, exports).',
                ['approval', 'auditor', 'audit'],
            ),
            $entry(
                'Settings — company profile, users and AI configuration?',
                'Open [Settings](/settings) for company profile and configuration. Manage users at [Users](/settings/users) and IoT devices at [IoT devices](/settings/iot-devices). AI provider, website assistant identity and knowledge base: [AI settings](/settings?section=ai).',
                ['settings', 'users', 'invite user', 'company profile', 'ai settings'],
                25,
            ),
            $entry(
                'How do I manage users and roles?',
                "Go to [Users](/settings/users). Owners/Admins can invite users and assign roles (Owner, Admin, Sales, Accountant, etc.); roles decide which modules and actions people can access. I can't change roles from chat — permission changes are deliberately manual.",
                ['add user', 'invite user', 'roles', 'permissions', 'manage users'],
                25,
            ),
            $entry(
                'How do I record a customer payment?',
                'Fastest path: open the invoice ([Invoices](/invoices)) and use its payment action to record the amount. Bulk entry: [Customer payments](/accounting/customers/payments). I can show which invoices are outstanding or overdue first.',
                ['record payment', 'customer payment', 'paid invoice'],
                25,
            ),
            $entry(
                'How do I get support or find the product manual?',
                'Ask me to **create a support ticket** and the team will follow up (within 2 business hours). Product manuals and guides are available at [Manuals](/manuals).',
                ['support', 'help', 'manual', 'contact support'],
                25,
            ),
            $entry(
                'Advice: how can I keep inventory accurate?',
                'A few high-impact habits: keep minimum stock levels on each product/warehouse ([Stock](/inventory/stock), [Warehouses](/inventory/warehouses)); do regular [Stock operations](/inventory/operations) reconciliations; review [Low stock](/inventory/stock) and [Movements](/inventory/movements) weekly; and prefer the [Purchase order](/inventory/purchase-orders) workflow for incoming goods so receipts are traceable. Enable SME Mode at [Inventory settings](/inventory/settings) if you want direct stock entry.',
                ['inventory accuracy', 'stock advice', 'improve stock', 'best practice'],
                15,
            ),
        ];
    }
}
