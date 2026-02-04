import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Toaster } from '@/components/ui/toaster'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { MainLayout } from '@/components/layout/MainLayout'
import { POSLayout } from '@/components/layout/POSLayout'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { useSettingsStore } from '@/store/settingsStore'

// Lazy load heavy pages for better initial load performance
const ProductsPage = lazy(() => import('@/pages/ProductsPage').then(m => ({ default: m.ProductsPage })))
const ProductCreatePage = lazy(() => import('@/pages/Products/ProductCreatePage').then(m => ({ default: m.ProductCreatePage })))
const ProductImportPage = lazy(() => import('@/pages/Products/ProductImportPage').then(m => ({ default: m.ProductImportPage })))
const ProductViewPage = lazy(() => import('@/pages/Products/ProductViewPage').then(m => ({ default: m.ProductViewPage })))
const ProductEditPage = lazy(() => import('@/pages/Products/ProductEditPage').then(m => ({ default: m.ProductEditPage })))
const ProductSettingsPage = lazy(() => import('@/pages/Products/ProductSettingsPage').then(m => ({ default: m.ProductSettingsPage })))
const CustomersPage = lazy(() => import('@/pages/CustomersPage').then(m => ({ default: m.CustomersPage })))
const CustomerDetailsPage = lazy(() => import('@/pages/Customers/CustomerDetailsPage').then(m => ({ default: m.CustomerDetailsPage })))
const InvoicesPage = lazy(() => import('@/pages/InvoicesPage').then(m => ({ default: m.InvoicesPage })))
const InvoiceDetailPage = lazy(() => import('@/pages/InvoiceDetailPage').then(m => ({ default: m.InvoiceDetailPage })))
const PaymentsPage = lazy(() => import('@/pages/PaymentsPage').then(m => ({ default: m.PaymentsPage })))
const AccountingPage = lazy(() => import('@/pages/AccountingPage').then(m => ({ default: m.AccountingPage })))
const ModulesPage = lazy(() => import('@/pages/Modules/ModulesPage').then(m => ({ default: m.ModulesPage })))
const POSPage = lazy(() => import('@/pages/POSPage').then(m => ({ default: m.POSPage })))
const POSTerminal = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSTerminal })))
const POSOrdersPage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSOrdersPage })))
const POSBarcodePage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSBarcodePage })))
const POSReportsPage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSReportsPage })))
const SettingsPage = lazy(() => import('@/pages/SettingsPage').then(m => ({ default: m.SettingsPage })))
const IoTDevicesPage = lazy(() => import('@/pages/IoT/IoTDevicesPage').then(m => ({ default: m.IoTDevicesPage })))
const IoTHubPage = lazy(() => import('@/pages/IoTHub').then(m => ({ default: m.IoTHubPage })))
const OCRDocumentsPage = lazy(() => import('@/pages/OCRDocumentsPage').then(m => ({ default: m.OCRDocumentsPage })))
const OCRDocumentReviewPage = lazy(() => import('@/pages/OCRDocumentReviewPage').then(m => ({ default: m.OCRDocumentReviewPage })))

// Lazy load reporting pages (heavy with charts)
const FinancialReportsPage = lazy(() => import('@/pages/reports/FinancialReportsPage').then(m => ({ default: m.FinancialReportsPage })))
const InventoryReportsPage = lazy(() => import('@/pages/reports/InventoryReportsPage').then(m => ({ default: m.InventoryReportsPage })))
const SalesAnalyticsPage = lazy(() => import('@/pages/reports/SalesAnalyticsPage').then(m => ({ default: m.SalesAnalyticsPage })))
const ExpenseAnalyticsPage = lazy(() => import('@/pages/reports/ExpenseAnalyticsPage').then(m => ({ default: m.ExpenseAnalyticsPage })))

// Lazy load dashboard pages (heavy with charts)
const ExecutiveDashboard = lazy(() => import('@/pages/dashboards/ExecutiveDashboard').then(m => ({ default: m.ExecutiveDashboard })))
const SalesDashboard = lazy(() => import('@/pages/dashboards/SalesDashboard').then(m => ({ default: m.SalesDashboard })))
const InventoryDashboard = lazy(() => import('@/pages/dashboards/InventoryDashboard').then(m => ({ default: m.InventoryDashboard })))
const FinancialDashboard = lazy(() => import('@/pages/dashboards/FinancialDashboard').then(m => ({ default: m.FinancialDashboard })))

// Lazy load new accounting module pages
const AccountingDashboard = lazy(() => import('@/pages/accounting/AccountingDashboard').then(m => ({ default: m.AccountingDashboard })))
const CustomerBills = lazy(() => import('@/pages/accounting/customers/CustomerBills').then(m => ({ default: m.CustomerBills })))
const CustomerInvoiceDetail = lazy(() => import('@/pages/accounting/customers/CustomerInvoiceDetail').then(m => ({ default: m.CustomerInvoiceDetail })))
const CustomerPayments = lazy(() => import('@/pages/accounting/customers/CustomerPayments').then(m => ({ default: m.CustomerPayments })))
const CustomerFollowupReports = lazy(() => import('@/pages/accounting/customers/CustomerFollowupReports').then(m => ({ default: m.CustomerFollowupReports })))
const CustomerStatements = lazy(() => import('@/pages/accounting/customers/CustomerStatements').then(m => ({ default: m.CustomerStatements })))
const VendorBills = lazy(() => import('@/pages/accounting/vendors/VendorBills').then(m => ({ default: m.VendorBills })))
const VendorBillDetail = lazy(() => import('@/pages/accounting/vendors/VendorBillDetail').then(m => ({ default: m.VendorBillDetail })))
const VendorPayments = lazy(() => import('@/pages/accounting/vendors/VendorPayments').then(m => ({ default: m.VendorPayments })))
const AgedReceivablesPage = lazy(() => import('@/pages/accounting/reports/AgedReceivablesPage').then(m => ({ default: m.AgedReceivablesPage })))
const AgedPayablesPage = lazy(() => import('@/pages/accounting/reports/AgedPayablesPage').then(m => ({ default: m.AgedPayablesPage })))
const BankReconciliationPage = lazy(() => import('@/pages/accounting/BankReconciliationPage').then(m => ({ default: m.BankReconciliationPage })))
const AssetsManagementPage = lazy(() => import('@/pages/accounting/AssetsManagementPage'))
const LockDatesPage = lazy(() => import('@/pages/accounting/LockDatesPage'))
const MultiCurrencyPage = lazy(() => import('@/pages/accounting/MultiCurrencyPage'))
const AnalyticAccountsPage = lazy(() => import('@/pages/accounting/AnalyticAccountsPage'))
const CompanySettingsPage = lazy(() => import('@/pages/accounting/CompanySettingsPage'))
const PaymentTermsPage = lazy(() => import('@/pages/accounting/PaymentTermsPage'))
const ChartOfAccountsPage = lazy(() => import('@/pages/accounting/ChartOfAccountsPage'))
const JournalEntriesPage = lazy(() => import('@/pages/accounting/JournalEntriesPage'))
const JournalsPage = lazy(() => import('@/pages/accounting/JournalsPage'))
const TaxConfigurationPage = lazy(() => import('@/pages/accounting/TaxConfigurationPage'))
const BankAccountsPage = lazy(() => import('@/pages/accounting/BankAccountsPage'))
const CustomerCreditNotesPage = lazy(() => import('@/pages/accounting/CustomerCreditNotesPage'))
const VendorCreditNotesPage = lazy(() => import('@/pages/accounting/VendorCreditNotesPage'))
const ARTransactionsPage = lazy(() => import('@/pages/accounting/ARTransactionsPage'))
const APTransactionsPage = lazy(() => import('@/pages/accounting/APTransactionsPage'))
const BalanceSheetPage = lazy(() => import('@/pages/accounting/reports/BalanceSheetPage'))
const ProfitLossPage = lazy(() => import('@/pages/accounting/reports/ProfitLossPage'))
const CashFlowPage = lazy(() => import('@/pages/accounting/reports/CashFlowPage'))
const GeneralLedgerPage = lazy(() => import('@/pages/accounting/reports/GeneralLedgerPage'))
const TrialBalancePage = lazy(() => import('@/pages/accounting/reports/TrialBalancePage'))
const AccountingPoliciesPage = lazy(() => import('@/pages/accounting/AccountingPoliciesPage'))

// Inventory pages
const SuppliersPage = lazy(() => import('@/pages/Inventory/SuppliersPage').then(m => ({ default: m.SuppliersPage })))
const InventoryDashboardPage = lazy(() => import('@/pages/Inventory/InventoryDashboard').then(m => ({ default: m.InventoryDashboard })))
const StockManagementPage = lazy(() => import('@/pages/Inventory/StockManagementPage').then(m => ({ default: m.StockManagementPage })))
const StockMovementsPage = lazy(() => import('@/pages/Inventory/StockMovementsPage').then(m => ({ default: m.StockMovementsPage })))
const StockOperationsPage = lazy(() => import('@/pages/Inventory/StockOperationsPage').then(m => ({ default: m.StockOperationsPage })))
const PurchaseOrdersPage = lazy(() => import('@/pages/Inventory/PurchaseOrdersPage').then(m => ({ default: m.PurchaseOrdersPage })))
const PurchaseOrderCreatePage = lazy(() => import('@/pages/Inventory/PurchaseOrderCreatePage').then(m => ({ default: m.PurchaseOrderCreatePage })))
const WarehouseTransfersPage = lazy(() => import('@/pages/Inventory/WarehouseTransfersPage').then(m => ({ default: m.WarehouseTransfersPage })))
const WarehouseTransferCreatePage = lazy(() => import('@/pages/Inventory/WarehouseTransferCreatePage').then(m => ({ default: m.WarehouseTransferCreatePage })))
const WarehousesPage = lazy(() => import('@/pages/Inventory/WarehousesPage').then(m => ({ default: m.WarehousesPage })))

// Payment Gateway Pages
const PaymentDashboard = lazy(() => import('@/pages/PaymentDashboard').then(m => ({ default: m.PaymentDashboard })))
const PaymentCheckout = lazy(() => import('@/pages/Checkout/PaymentCheckout').then(m => ({ default: m.PaymentCheckout })))
const PaymentTestPage = lazy(() => import('@/pages/PaymentTestPage').then(m => ({ default: m.PaymentTestPage })))
const PaymentSuccess = lazy(() => import('@/pages/PaymentSuccess').then(m => ({ default: m.default })))
const PaymentGatewaySettings = lazy(() => import('@/pages/Settings/PaymentGatewaySettings').then(m => ({ default: m.PaymentGatewaySettings })))
const OfflinePaymentMethods = lazy(() => import('@/pages/Settings/OfflinePaymentMethods').then(m => ({ default: m.OfflinePaymentMethodsPage })))

// Loading fallback component
const PageLoader = () => (
  <div className="flex items-center justify-center h-screen">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
  </div>
)

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
})

function App() {
  // Subscribe at the top-level so currency changes re-render the app
  const currencyCode = useSettingsStore((s) => s.currencyCode)

  return (
    <QueryClientProvider client={queryClient}>
      <div data-currency={currencyCode}>
        <BrowserRouter basename={import.meta.env.VITE_BASE_PATH || '/'}>
          <Suspense fallback={<PageLoader />}>
            <Routes>
            <Route path="/login" element={<LoginPage />} />
            
            <Route element={<ProtectedRoute />}>
              {/* Main Layout Routes - with sidebar */}
              <Route element={<MainLayout />}>
                <Route path="/" element={<DashboardPage />} />
                <Route path="/products" element={<ProductsPage />} />
                <Route path="/products/new" element={<ProductCreatePage />} />
                <Route path="/products/import" element={<ProductImportPage />} />
                <Route path="/products/:id" element={<ProductViewPage />} />
                <Route path="/products/:id/edit" element={<ProductEditPage />} />
                <Route path="/products/settings" element={<ProductSettingsPage />} />
                
                {/* Inventory Routes */}
                <Route path="/inventory" element={<InventoryDashboardPage />} />
                <Route path="/inventory/stock" element={<StockManagementPage />} />
                <Route path="/inventory/movements" element={<StockMovementsPage />} />
                <Route path="/inventory/operations" element={<StockOperationsPage />} />
                <Route path="/inventory/purchase-orders" element={<PurchaseOrdersPage />} />
                <Route path="/inventory/purchase-orders/new" element={<PurchaseOrderCreatePage />} />
                <Route path="/inventory/transfers" element={<WarehouseTransfersPage />} />
                <Route path="/inventory/transfers/new" element={<WarehouseTransferCreatePage />} />
                <Route path="/inventory/warehouses" element={<WarehousesPage />} />
                <Route path="/inventory/suppliers" element={<SuppliersPage />} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                
                {/* Sales Routes */}
                <Route path="/sales" element={<SalesDashboard />} />
                <Route path="/sales/orders" element={<POSOrdersPage />} />
                <Route path="/sales/quotes" element={<InvoicesPage />} />
                
                {/* Customer Routes */}
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/customers/:id" element={<CustomerDetailsPage />} />
                
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                <Route path="/accounting" element={<AccountingPage />} />
                
                {/* New Accounting Module Routes */}
                <Route path="/accounting/dashboard" element={<AccountingDashboard />} />
                
                {/* Customer Section */}
                <Route path="/accounting/customers/bills" element={<CustomerBills />} />
                <Route path="/accounting/customers/bills/:id" element={<CustomerInvoiceDetail />} />
                <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
                <Route path="/accounting/customers/credit-notes" element={<CustomerCreditNotesPage />} />
                <Route path="/accounting/customers/transactions" element={<ARTransactionsPage />} />
                <Route path="/accounting/customers/followup-reports" element={<CustomerFollowupReports />} />
                <Route path="/accounting/customers/statements" element={<CustomerStatements />} />
                
                {/* Vendor Section */}
                <Route path="/accounting/vendors/bills" element={<VendorBills />} />
                <Route path="/accounting/vendors/bills/:id" element={<VendorBillDetail />} />
                <Route path="/accounting/vendors/payments" element={<VendorPayments />} />
                <Route path="/accounting/vendors/credit-notes" element={<VendorCreditNotesPage />} />
                <Route path="/accounting/vendors/transactions" element={<APTransactionsPage />} />
                
                {/* Accounting Core Section */}
                <Route path="/accounting/core/chart-of-accounts" element={<ChartOfAccountsPage />} />
                <Route path="/accounting/core/journal-entries" element={<JournalEntriesPage />} />
                <Route path="/accounting/core/journals" element={<JournalsPage />} />
                <Route path="/accounting/core/reconciliation" element={<BankReconciliationPage />} />
                <Route path="/accounting/core/assets" element={<AssetsManagementPage />} />
                <Route path="/accounting/core/lock-dates" element={<LockDatesPage />} />
                <Route path="/accounting/core/multi-currency" element={<MultiCurrencyPage />} />
                <Route path="/accounting/core/analytic-accounts" element={<AnalyticAccountsPage />} />
                
                {/* Reporting Section */}
                <Route path="/accounting/reporting/balance-sheet" element={<BalanceSheetPage />} />
                <Route path="/accounting/reporting/profit-loss" element={<ProfitLossPage />} />
                <Route path="/accounting/reporting/cash-flow" element={<CashFlowPage />} />
                <Route path="/accounting/reporting/general-ledger" element={<GeneralLedgerPage />} />
                <Route path="/accounting/reporting/trial-balance" element={<TrialBalancePage />} />
                <Route path="/accounting/reporting/aged-receivables" element={<AgedReceivablesPage />} />
                <Route path="/accounting/reporting/aged-payables" element={<AgedPayablesPage />} />
                
                {/* Configuration Section */}
                <Route path="/accounting/configuration/company" element={<CompanySettingsPage />} />
                <Route path="/accounting/configuration/policies" element={<AccountingPoliciesPage />} />
                <Route path="/accounting/configuration/payment-terms" element={<PaymentTermsPage />} />
                <Route path="/accounting/configuration/taxes" element={<TaxConfigurationPage />} />
                <Route path="/accounting/configuration/bank-accounts" element={<BankAccountsPage />} />
                
                <Route path="/modules" element={<ModulesPage />} />
                <Route path="/pos" element={<POSPage />} />
                <Route path="/pos/orders" element={<POSOrdersPage />} />
                <Route path="/pos/barcodes" element={<POSBarcodePage />} />
                <Route path="/pos/reports" element={<POSReportsPage />} />
                <Route path="/ocr/documents" element={<OCRDocumentsPage />} />
                <Route path="/ocr/documents/:uuid" element={<OCRDocumentReviewPage />} />
                <Route path="/reports/financial" element={<FinancialReportsPage />} />
                <Route path="/reports/inventory" element={<InventoryReportsPage />} />
                <Route path="/reports/sales-analytics" element={<SalesAnalyticsPage />} />
                <Route path="/reports/expense-analytics" element={<ExpenseAnalyticsPage />} />
                <Route path="/dashboards/executive" element={<ExecutiveDashboard />} />
                <Route path="/dashboards/sales" element={<SalesDashboard />} />
                <Route path="/dashboards/inventory" element={<InventoryDashboard />} />
                <Route path="/dashboards/financial" element={<FinancialDashboard />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="/settings/iot-devices" element={<IoTDevicesPage />} />
                <Route path="/iot-hub" element={<IoTHubPage />} />
                
                {/* Payment Gateway Routes */}
                <Route path="/payments" element={<PaymentsPage />} />
                <Route path="/payment-analytics" element={<PaymentDashboard />} />
                <Route path="/checkout" element={<PaymentCheckout />} />
                <Route path="/payment-test" element={<PaymentTestPage />} />
                <Route path="/payment/success" element={<PaymentSuccess />} />
                <Route path="/settings/payment-gateways" element={<PaymentGatewaySettings />} />
                <Route path="/settings/offline-payments" element={<OfflinePaymentMethods />} />
              </Route>

              {/* POS Layout Routes - fullscreen without sidebar */}
              <Route element={<POSLayout />}>
                <Route path="/pos/terminal" element={<POSTerminal />} />
              </Route>
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </Suspense>
        </BrowserRouter>
      </div>
      <Toaster />
    </QueryClientProvider>
  )
}

export default App
