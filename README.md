# NoovaERP — Multi-Tenant SaaS ERP

A commercial-grade, multi-tenant ERP SaaS: Laravel 11 API (`backend/`), a React + Vite tenant-facing SPA (`frontend/`), a separate React + Vite SuperAdmin console (`superadmin/`), and a standalone Python OCR microservice (`ocr-service/`). Accounting-first architecture with centralized double-entry posting, a disk-driven module system, and schema-per-tenant multi-tenancy.

## 🏗️ Architecture

### Backend (Laravel 11)
- **Multi-Tenancy**: Schema-per-tenant using PostgreSQL (via stancl/tenancy) — each tenant gets its own Postgres schema, switched via `search_path`
- **Authentication**: Laravel Sanctum (token-based API auth); tenant role resolved from `public.user_company_access.role` and bridged to Spatie permissions per-schema via `TenantRoleService`
- **Accounting Core**: Double-entry bookkeeping centralized through `PostingEngine` — operational modules call `PostingEngine::post()` rather than writing ledger rows directly
- **Module System**: Business capabilities live under `backend/modules/<Name>/` (own routes, controllers, services, models, and a `ModuleContract`); shared platform concerns (auth, tenancy middleware, RBAC, the module registry) live under `backend/app/`. Module registration/activation is disk-driven (`ModuleManager` scans `modules/*/module.json`)
- **API-First**: RESTful API with consistent responses

### Frontend (React + Vite)
- **UI Framework**: shadcn/ui (Tailwind CSS components)
- **Icons**: Iconify Solar Icons (bold-duotone variants)
- **State Management**: Zustand + React Query
- **Routing**: React Router v6
- **Type Safety**: TypeScript

### SuperAdmin console (`superadmin/`)
Separate Vite/React app — the platform-operator console (tenant provisioning, entitlements, module catalog), not a tenant-facing UI.

### OCR service (`ocr-service/`)
Standalone Python service invoked by the backend's OCR/bookkeeping ingestion path.

## 📋 Features

### Core Modules
✅ **Tenant Management** - Signup, onboarding, subdomain routing
✅ **Authentication** - User login/logout with role-based access
✅ **Products** - SKU, pricing, cost, inventory tracking
✅ **Customers** - Contact info, billing, outstanding balance
✅ **Invoices** - Line items, auto-calculate totals, PDF generation
✅ **Payments** - Record payments, update invoice balance
✅ **Accounting** - Chart of Accounts, Journal Entries, Trial Balance
✅ **Inventory** - Stock movements, auto-deduct on sale
✅ **Dashboard** - KPIs, revenue charts, recent activity

Additional modules include Sales, Purchasing, HRM, CRM, POS, Event Management, IoT, Website Builder, and Financial Close.

### Accounting Workflow (Critical)
```
Create Invoice → Post Invoice → Auto-Generate Journal Entry
                              → Debit: Accounts Receivable
                              → Credit: Sales Revenue
                              → Credit: Tax Payable

Record Payment → Auto-Generate Journal Entry
               → Debit: Cash
               → Credit: Accounts Receivable
               → Update Invoice Balance
               → Reduce Inventory Stock
```

**Rule**: Every transaction posts to the double-entry ledger. Debits always equal credits.

## 🚀 Installation

### Prerequisites
- PHP 8.2+
- Composer
- PostgreSQL 14+
- Redis
- Node.js 18+
- npm/yarn

### Backend Setup

```bash
cd backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Configure database
# Edit .env:
#   DB_CONNECTION=pgsql
#   DB_DATABASE=noovaerp
#   DB_USERNAME=postgres
#   DB_PASSWORD=your_password

# Generate app key
php artisan key:generate

# Run migrations (central + tenants)
php artisan migrate
php artisan tenants:migrate

# Start server
php artisan serve
# Backend runs on http://localhost:8000
```

### Frontend Setup

```bash
cd frontend

# Install dependencies
npm install

# Start dev server
npm run dev
# Frontend runs on http://localhost:5173
```

### SuperAdmin Setup

```bash
cd superadmin

# Install dependencies
npm install

# Start dev server
npm run dev
```

#### Typecheck / lint ratchet (CI)

`.github/workflows/frontend-ci.yml` runs `npm run typecheck:ci` and `npm run lint:ci` for `frontend/` and `superadmin/` on every PR. Both apps carry pre-existing tsc/eslint findings, recorded in `<app>/ci-baseline/{tsc,eslint}.json`; the job fails only when a finding is **new** (keys are file + TS code + message for tsc, file + rule for eslint — no line numbers, so unrelated edits don't churn). Script: `scripts/ci/ts-lint-ratchet.mjs`.

- New error reported → fix it (`npx tsc --noEmit` / `npx eslint <file>` show line numbers).
- Fixed existing errors → shrink the baseline: `npm run typecheck:baseline` / `npm run lint:baseline` and commit the JSON. These only ever lower counts.
- Deliberately accept a new finding (e.g. a file rename moved existing errors) → `npm run typecheck:baseline -- --accept-new` (or `lint:baseline -- --accept-new`); the baseline growth shows in the PR diff for the reviewer.

### Create First Tenant

**Via API** (Postman/cURL):
```bash
POST http://localhost:8000/api/v1/signup
Content-Type: application/json

{
  "name": "John Doe",
  "email": "admin@company.com",
  "company": "My Company",
  "subdomain": "mycompany",
  "password": "password123"
}
```

**Response**:
```json
{
  "message": "Tenant created successfully",
  "tenant": { ... },
  "domain": "mycompany.localhost"
}
```

### Login

Navigate to http://localhost:5173/login

```
Email: admin@company.com
Password: password123
```

## 📊 Database Schema

### Central Database (Landlord)
- `tenants` - Tenant metadata, subscription info
- `domains` - Tenant subdomains

### Tenant Schema (Per-Tenant)
**Accounting Core:**
- `chart_of_accounts` - COA with opening balances
- `journal_entries` - Immutable ledger entries
- `journal_entry_lines` - Debit/credit lines

**Business Entities:**
- `products` - Inventory, pricing, accounts
- `customers` - Contact info, credit limits
- `invoices` + `invoice_lines` - Sales invoicing
- `payments` - Payment records
- `stock_movements` - Inventory transactions

**System:**
- `users` - Tenant-scoped users
- `roles` + `permissions` - RBAC (Spatie)
- `features` + `tenant_features` - Add-on flags

## 🧪 Testing the Workflow

### Backend CI

The repository includes a PostgreSQL-backed backend CI workflow at `.github/workflows/backend-ci.yml`.
It runs central migrations, the tenancy provisioning configuration test, and a real tenant provisioning smoke check using `backend/scripts/assert_tenant_provisioning.php`.

For a local equivalent against PostgreSQL:

```bash
cd backend
php artisan migrate
php artisan test tests/Feature/TenancyProvisioningConfigurationTest.php
php scripts/assert_tenant_provisioning.php
```

### 1. Create Product
```
POST /api/v1/products
{
  "name": "Laptop",
  "sku": "LAP-001",
  "price": 1200,
  "cost": 800,
  "stock_quantity": 10,
  "type": "product",
  "track_inventory": true
}
```

### 2. Create Customer
```
POST /api/v1/customers
{
  "name": "Tech Corp",
  "email": "billing@techcorp.com",
  "phone": "+1234567890"
}
```

### 3. Create Invoice
```
POST /api/v1/invoices
{
  "customer_id": 1,
  "lines": [
    {
      "product_id": 1,
      "description": "Laptop",
      "quantity": 2,
      "unit_price": 1200,
      "tax_rate": 10
    }
  ]
}
```

### 4. Post Invoice (Auto-posts to Ledger)
```
POST /api/v1/invoices/1/send
```

**Result**: Creates journal entry with:
- DR: A/R (1100) = $2,640
- CR: Sales (4100) = $2,400
- CR: Tax Payable (2200) = $240

**Inventory**: Reduces stock by 2 units

### 5. Record Payment
```
POST /api/v1/payments
{
  "invoice_id": 1,
  "amount": 2640,
  "method": "bank_transfer",
  "payment_date": "2024-01-15"
}
```

**Result**: Creates journal entry with:
- DR: Cash (1010) = $2,640
- CR: A/R (1100) = $2,640

### 6. View Trial Balance
```
GET /api/v1/accounting/trial-balance
```

**Result**: Shows balanced ledger (Total Debit = Total Credit)

## 🔑 Key Design Decisions

### Why Schema-per-Tenant?
- **Isolation**: Complete data separation
- **Security**: No cross-tenant data leaks
- **Compliance**: Easier GDPR/audit requirements
- **Backups**: Per-tenant restore capability

### Why Double-Entry Accounting?
- **Accuracy**: Self-balancing ledger
- **Audit Trail**: Immutable transaction history
- **Financial Reports**: Reliable P&L, Balance Sheet
- **Compliance**: Meets accounting standards

### Why PostgreSQL?
- **Schema Support**: Native multi-tenancy
- **Transactions**: ACID compliance for accounting
- **Performance**: Efficient for complex queries
- **Triggers**: Enforces debit=credit rule

### Why API-First?
- **Flexibility**: Multiple frontends (Web, Mobile, POS)
- **Scalability**: Microservices-ready
- **Integration**: External apps can consume API
- **Testing**: Easier to test endpoints

## 📦 Tech Stack Summary

| Layer | Technology | Purpose |
|-------|-----------|---------|
| Backend | Laravel 11 | API framework |
| Database | PostgreSQL | Multi-tenant data |
| Cache/Queue | Redis | Performance, jobs |
| Auth | Sanctum | Token-based API auth |
| Tenancy | stancl/tenancy | Schema isolation |
| RBAC | Spatie Permission | Role management |
| Frontend | React 18 | UI framework |
| Build Tool | Vite | Fast dev server |
| UI Components | shadcn/ui | Tailwind components |
| Icons | Iconify Solar | Professional icons |
| State | Zustand | Global state |
| Data Fetching | React Query | API caching |
| Routing | React Router | SPA routing |

## 🚧 Roadmap

### Advanced Features
- [ ] Purchase Orders & Procurement
- [ ] HR & Payroll
- [ ] Multi-warehouse Inventory
- [ ] POS (PWA, offline-first)
- [ ] Ecommerce Integration

### Add-Ons (Billable)
- [ ] Loyalty Program
- [ ] WhatsApp Integration
- [ ] AI Chatbot
- [ ] IoT (RFID, Cameras)
- [ ] Event Management

### Enterprise
- [ ] Advanced Reporting (BI)
- [ ] Multi-currency
- [ ] Tax Compliance (VAT, GST)
- [ ] API Webhooks
- [ ] SSO (SAML, OAuth)

## 📝 API Endpoints

### Central (Non-Tenant)
```
POST   /api/v1/signup         - Create tenant
GET    /api/v1/health         - Health check
```

### Tenant (Requires Auth)
```
# Auth
POST   /api/v1/login          - Login
POST   /api/v1/logout         - Logout
GET    /api/v1/me             - Current user

# Dashboard
GET    /api/v1/dashboard      - KPIs & stats

# Products
GET    /api/v1/products       - List products
POST   /api/v1/products       - Create product
GET    /api/v1/products/{id}  - Show product
PUT    /api/v1/products/{id}  - Update product
DELETE /api/v1/products/{id}  - Delete product

# Customers
GET    /api/v1/customers      - List customers
POST   /api/v1/customers      - Create customer
... (standard REST)

# Invoices
GET    /api/v1/invoices       - List invoices
POST   /api/v1/invoices       - Create invoice
POST   /api/v1/invoices/{id}/send - Post invoice

# Payments
GET    /api/v1/payments       - List payments
POST   /api/v1/payments       - Record payment

# Accounting
GET    /api/v1/accounting/chart-of-accounts   - COA
GET    /api/v1/accounting/journal-entries     - Ledger
GET    /api/v1/accounting/trial-balance       - Trial balance
GET    /api/v1/accounting/ledger/{account}    - Account ledger
```

## 🔐 Security Considerations

- ✅ SQL Injection protected (Eloquent ORM)
- ✅ XSS protected (React escapes by default)
- ✅ CSRF protected (Sanctum cookies)
- ✅ Rate limiting (Laravel throttle middleware)
- ✅ Password hashing (bcrypt)
- ✅ Tenant isolation (schema-per-tenant)
- ⚠️ TODO: Add rate limiting to API routes
- ⚠️ TODO: Implement activity logging (Spatie)
- ⚠️ TODO: Add file upload validation

## 🤝 Contributing

Contributions are welcome via pull requests. For feature requests or bug reports, open an issue on GitHub.

## 📞 Support

- Issues: https://github.com/olamoooore/noovaERP/issues
