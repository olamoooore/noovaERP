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
import { useStripeDevtoolsGuard } from '@/hooks/useStripeDevtoolsGuard'

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
const POSTerminal = lazy(() => import('@/pages/POS/POSTerminal').then(m => ({ default: m.POSTerminal })))
const POSCustomerDisplay = lazy(() => import('@/pages/POS/POSCustomerDisplay').then(m => ({ default: m.POSCustomerDisplay })))
const POSOrdersPage = lazy(() => import('@/pages/POS/POSOrdersPage').then(m => ({ default: m.POSOrdersPage })))
const POSBarcodePage = lazy(() => import('@/pages/POS/POSBarcodePage').then(m => ({ default: m.POSBarcodePage })))
const POSReportsPage = lazy(() => import('@/pages/POS/POSReportsPage').then(m => ({ default: m.POSReportsPage })))
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
const QuotesPage = lazy(() => import('@/pages/sales/QuotesPage').then(m => ({ default: m.QuotesPage })))
const InventoryDashboard = lazy(() => import('@/pages/dashboards/InventoryDashboard').then(m => ({ default: m.InventoryDashboard })))
const FinancialDashboard = lazy(() => import('@/pages/dashboards/FinancialDashboard').then(m => ({ default: m.FinancialDashboard })))

// Lazy load new accounting module pages
const AccountingDashboard = lazy(() => import('@/pages/accounting/AccountingDashboard').then(m => ({ default: m.AccountingDashboard })))
const CustomerBills = lazy(() => import('@/pages/accounting/customers/CustomerBills').then(m => ({ default: m.CustomerBills })))
const CreateInvoicePage = lazy(() => import('@/pages/accounting/customers/CreateInvoicePage').then(m => ({ default: m.CreateInvoicePage })))
const CustomerInvoiceDetail = lazy(() => import('@/pages/accounting/customers/CustomerInvoiceDetail').then(m => ({ default: m.CustomerInvoiceDetail })))
const CustomerPayments = lazy(() => import('@/pages/accounting/customers/CustomerPayments').then(m => ({ default: m.CustomerPayments })))
const CustomerFollowupReports = lazy(() => import('@/pages/accounting/customers/CustomerFollowupReports').then(m => ({ default: m.CustomerFollowupReports })))
const CustomerStatements = lazy(() => import('@/pages/accounting/customers/CustomerStatements').then(m => ({ default: m.CustomerStatements })))
const VendorsPage = lazy(() => import('@/pages/accounting/vendors/VendorsPage'))
const VendorBills = lazy(() => import('@/pages/accounting/vendors/VendorBills').then(m => ({ default: m.VendorBills })))
const CreateVendorBillPage = lazy(() => import('@/pages/accounting/vendors/CreateVendorBillPage').then(m => ({ default: m.CreateVendorBillPage })))
const VendorBillDetail = lazy(() => import('@/pages/accounting/vendors/VendorBillDetail').then(m => ({ default: m.VendorBillDetail })))
const VendorPayments = lazy(() => import('@/pages/accounting/vendors/VendorPayments').then(m => ({ default: m.VendorPayments })))
const AgedReceivablesPage = lazy(() => import('@/pages/accounting/reports/AgedReceivablesPage').then(m => ({ default: m.AgedReceivablesPage })))
const AgedPayablesPage = lazy(() => import('@/pages/accounting/reports/AgedPayablesPage').then(m => ({ default: m.AgedPayablesPage })))
const BankReconciliationPage = lazy(() => import('@/pages/accounting/BankReconciliationPage').then(m => ({ default: m.BankReconciliationPage })))
const BankReconciliationHistoryPage = lazy(() => import('@/pages/accounting/BankReconciliationHistoryPage'))
const AssetsManagementPage = lazy(() => import('@/pages/accounting/AssetsManagementPage'))
const LockDatesPage = lazy(() => import('@/pages/accounting/LockDatesPage'))
const MultiCurrencyPage = lazy(() => import('@/pages/accounting/MultiCurrencyPage'))
const AnalyticAccountsPage = lazy(() => import('@/pages/accounting/AnalyticAccountsPage'))
const AccountingOnboardingPage = lazy(() => import('@/pages/accounting/AccountingOnboardingPage'))
const AccountingEntityManagementPage = lazy(() => import('@/pages/accounting/AccountingEntityManagementPage'))
const CompanySettingsPage = lazy(() => import('@/pages/accounting/CompanySettingsPage'))
const PaymentTermsPage = lazy(() => import('@/pages/accounting/PaymentTermsPage'))
const ChartOfAccountsPage = lazy(() => import('@/pages/accounting/ChartOfAccountsPage'))
const JournalEntriesPage = lazy(() => import('@/pages/accounting/JournalEntriesPage'))
const JournalsPage = lazy(() => import('@/pages/accounting/JournalsPage'))
const TaxConfigurationPage = lazy(() => import('@/pages/accounting/TaxConfigurationPage'))
const BankAccountsPage = lazy(() => import('@/pages/accounting/BankAccountsPage'))
const CustomerCreditNotesPage = lazy(() => import('@/pages/accounting/CustomerCreditNotesPage'))
const CustomerCreditNoteDetail = lazy(() => import('@/pages/accounting/customers/CustomerCreditNoteDetail'))
const VendorCreditNotesPage = lazy(() => import('@/pages/accounting/VendorCreditNotesPage'))
const VendorCreditNoteDetail = lazy(() => import('@/pages/accounting/vendors/VendorCreditNoteDetail'))
const ARTransactionsPage = lazy(() => import('@/pages/accounting/ARTransactionsPage'))
const APTransactionsPage = lazy(() => import('@/pages/accounting/APTransactionsPage'))
const BalanceSheetPage = lazy(() => import('@/pages/accounting/reports/BalanceSheetPage'))
const ProfitLossPage = lazy(() => import('@/pages/accounting/reports/ProfitLossPage'))
const CashFlowPage = lazy(() => import('@/pages/accounting/reports/CashFlowPage'))
const GeneralLedgerPage = lazy(() => import('@/pages/accounting/reports/GeneralLedgerPage'))
const TrialBalancePage = lazy(() => import('@/pages/accounting/reports/TrialBalancePage'))
const AccountingPoliciesPage = lazy(() => import('@/pages/accounting/AccountingPoliciesPage'))

// AI Module Pages
const AIChatPage = lazy(() => import('@/pages/AI/AIChatPage').then(m => ({ default: m.AIChatPage })))

// Field Service Module Pages
const FieldServiceDashboard = lazy(() => import('@/pages/FieldService/FieldServiceDashboard').then(m => ({ default: m.FieldServiceDashboard })))
const WorkOrdersPage = lazy(() => import('@/pages/FieldService/WorkOrdersPage').then(m => ({ default: m.WorkOrdersPage })))
const WorkOrderCreatePage = lazy(() => import('@/pages/FieldService/WorkOrderCreatePage').then(m => ({ default: m.WorkOrderCreatePage })))
const WorkOrderViewPage = lazy(() => import('@/pages/FieldService/WorkOrderViewPage').then(m => ({ default: m.WorkOrderViewPage })))
const SchedulePage = lazy(() => import('@/pages/FieldService/SchedulePage').then(m => ({ default: m.SchedulePage })))
const FSTechniciansPage = lazy(() => import('@/pages/FieldService/TechniciansPage').then(m => ({ default: m.TechniciansPage })))
const FSTeamsPage = lazy(() => import('@/pages/FieldService/TeamsPage').then(m => ({ default: m.TeamsPage })))
const FSGanttPage = lazy(() => import('@/pages/FieldService/GanttPage'))
const FSWorksheetTemplatesPage = lazy(() => import('@/pages/FieldService/WorksheetTemplatesPage'))
const FSAnalyticsPage = lazy(() => import('@/pages/FieldService/AnalyticsPage').then(m => ({ default: m.AnalyticsPage })))

// HRM Module Pages
const HRMDashboard = lazy(() => import('@/pages/HRM').then(m => ({ default: m.HRMDashboard })))
const EmployeesPage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.EmployeesPage })))
const ContractsPage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.ContractsPage })))
const TimeshiftsPage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.TimeshiftsPage })))
const AttendancePage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.AttendancePage })))
const LeaveRequestsPage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.LeaveRequestsPage })))
const PayrollPage = lazy(() => import('@/pages/HRM').then(m => ({ default: m.PayrollPage })))

// Event Management Module Pages
const StaffManagementPage = lazy(() => import('@/pages/EventManagement').then(m => ({ default: m.StaffManagementPage })))
const LiveOperationsDashboard = lazy(() => import('@/pages/EventManagement').then(m => ({ default: m.LiveOperationsDashboard })))
const BrowseEventsPage = lazy(() => import('@/pages/EventManagement/BrowseEventsPage'))
const MyEventsDashboard = lazy(() => import('@/pages/EventManagement/MyEventsDashboard'))
const MyBookingsPage = lazy(() => import('@/pages/EventManagement/MyBookingsPage'))
const MyTicketsPage = lazy(() => import('@/pages/EventManagement/MyTicketsPage'))
const FeedbackSystem = lazy(() => import('@/pages/EventManagement/FeedbackSystem'))
const EventPortalPage = lazy(() => import('@/pages/EventPortal').then(m => ({ default: m.EventPortalPage })))

// Loyalty Module Pages
const LoyaltyDashboardPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyDashboardPage })))
const LoyaltyCustomersPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyCustomersPage })))
const LoyaltyCustomerDetailPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyCustomerDetailPage })))
const LoyaltyRewardsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyRewardsPage })))
const LoyaltyRedemptionsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyRedemptionsPage })))
const LoyaltySettingsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltySettingsPage })))

// Web Shop Pages
const WebShopCheckoutPage = lazy(() => import('@/pages/WebShop').then(m => ({ default: m.WebShopCheckoutPage })))
const WebShopCartPage = lazy(() => import('@/pages/WebShop').then(m => ({ default: m.WebShopCartPage })))
const OrderSuccessPage = lazy(() => import('@/pages/WebShop').then(m => ({ default: m.OrderSuccessPage })))
const PaymentCallbackPage = lazy(() => import('@/pages/PaymentCallbackPage'))

// Web Shop Customer Portal
const PortalLayout = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.PortalLayout })))
const CustomerPortalDashboardPage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.DashboardPage })))
const OrdersListPage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.OrdersListPage })))
const OrderDetailPage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.OrderDetailPage })))
const ProfilePage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.ProfilePage })))
const AddressesPage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.AddressesPage })))
const LoyaltyPage = lazy(() => import('@/pages/WebShop/CustomerPortal').then(m => ({ default: m.LoyaltyPage })))
const WebShopLoginPage = lazy(() => import('@/pages/WebShop/Auth/LoginPage').then(m => ({ default: m.LoginPage })))
const WebShopRegisterPage = lazy(() => import('@/pages/WebShop/Auth/RegisterPage').then(m => ({ default: m.RegisterPage })))

// Website Builder Pages
const WebsitesIndex = lazy(() => import('@/pages/WebsiteBuilder/WebsitesIndex').then(m => ({ default: m.WebsitesIndex })))
const WebsiteEditor = lazy(() => import('@/pages/WebsiteBuilder/WebsiteEditor').then(m => ({ default: m.WebsiteEditor })))
const PageManagement = lazy(() => import('@/pages/WebsiteBuilder/PageManagement').then(m => ({ default: m.PageManagement })))

// Public Site Viewer
const PublicSiteViewer = lazy(() => import('@/pages/PublicSite').then(m => ({ default: m.PublicSiteViewer })))

// Ecommerce Shop Pages (public-facing)
const EcommerceShopPage = lazy(() => import('@/pages/WebShop/EcommerceStorefront').then(m => ({ default: m.EcommerceStorefront })))

// Client Storefront (infiniti-kpop-store integration)
const StorefrontApp = lazy(() => import('@/storefront/StorefrontApp'))

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

  useStripeDevtoolsGuard()

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
                <Route path="/sales/quotes" element={<QuotesPage />} />
                <Route path="/sales/quotes/create" element={<CreateInvoicePage />} />
                <Route path="/sales/quotes/:id" element={<CustomerInvoiceDetail />} />
                
                {/* Customer Routes */}
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/customers/:id" element={<CustomerDetailsPage />} />
                
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/invoices/create" element={<CreateInvoicePage />} />
                <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                <Route path="/accounting" element={<AccountingPage />} />
                
                {/* New Accounting Module Routes */}
                <Route path="/accounting/dashboard" element={<AccountingDashboard />} />
                
                {/* Customer Section */}
                <Route path="/accounting/customers/bills" element={<CustomerBills />} />
                <Route path="/accounting/customers/bills/create" element={<CreateInvoicePage />} />
                <Route path="/accounting/customers/bills/:id" element={<CustomerInvoiceDetail />} />
                <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
                <Route path="/accounting/customers/credit-notes" element={<CustomerCreditNotesPage />} />
                <Route path="/accounting/customers/credit-notes/:id" element={<CustomerCreditNoteDetail />} />
                <Route path="/accounting/customers/transactions" element={<ARTransactionsPage />} />
                <Route path="/accounting/customers/followup-reports" element={<CustomerFollowupReports />} />
                <Route path="/accounting/customers/statements" element={<CustomerStatements />} />
                
                {/* Vendor Section */}
                <Route path="/accounting/vendors" element={<VendorsPage />} />
                <Route path="/accounting/vendors/bills" element={<VendorBills />} />
                <Route path="/accounting/vendors/bills/create" element={<CreateVendorBillPage />} />
                <Route path="/accounting/vendors/bills/:id" element={<VendorBillDetail />} />
                <Route path="/accounting/vendors/payments" element={<VendorPayments />} />
                <Route path="/accounting/vendors/credit-notes" element={<VendorCreditNotesPage />} />
                <Route path="/accounting/vendors/credit-notes/:id" element={<VendorCreditNoteDetail />} />
                <Route path="/accounting/vendors/transactions" element={<APTransactionsPage />} />
                
                {/* Accounting Core Section */}
                <Route path="/accounting/core/chart-of-accounts" element={<ChartOfAccountsPage />} />
                <Route path="/accounting/core/journal-entries" element={<JournalEntriesPage />} />
                <Route path="/accounting/core/journals" element={<JournalsPage />} />
                <Route path="/accounting/core/reconciliation" element={<BankReconciliationPage />} />
                <Route path="/accounting/core/reconciliation/history" element={<BankReconciliationHistoryPage />} />
                <Route path="/accounting/core/assets" element={<AssetsManagementPage />} />
                <Route path="/accounting/core/lock-dates" element={<LockDatesPage />} />
                <Route path="/accounting/core/multi-currency" element={<MultiCurrencyPage />} />
                <Route path="/accounting/core/analytic-accounts" element={<AnalyticAccountsPage />} />
                <Route path="/accounting/core/onboarding" element={<AccountingOnboardingPage />} />
                <Route path="/accounting/core/entities" element={<AccountingEntityManagementPage />} />
                
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
                
                {/* Loyalty Module Routes */}
                <Route path="/loyalty" element={<LoyaltyDashboardPage />} />
                <Route path="/loyalty/customers" element={<LoyaltyCustomersPage />} />
                <Route path="/loyalty/customers/:customerId" element={<LoyaltyCustomerDetailPage />} />
                <Route path="/loyalty/rewards" element={<LoyaltyRewardsPage />} />
                <Route path="/loyalty/redemptions" element={<LoyaltyRedemptionsPage />} />
                <Route path="/loyalty/settings" element={<LoyaltySettingsPage />} />
                
                {/* AI Module Routes */}
                <Route path="/ai/chat" element={<AIChatPage />} />

                {/* Field Service Module Routes */}
                <Route path="/field-service" element={<FieldServiceDashboard />} />
                <Route path="/field-service/work-orders" element={<WorkOrdersPage />} />
                <Route path="/field-service/work-orders/new" element={<WorkOrderCreatePage />} />
                <Route path="/field-service/work-orders/:id" element={<WorkOrderViewPage />} />
                <Route path="/field-service/schedule" element={<SchedulePage />} />
                <Route path="/field-service/technicians" element={<FSTechniciansPage />} />
                <Route path="/field-service/teams" element={<FSTeamsPage />} />
                <Route path="/field-service/gantt" element={<FSGanttPage />} />
                <Route path="/field-service/worksheet-templates" element={<FSWorksheetTemplatesPage />} />
                <Route path="/field-service/analytics" element={<FSAnalyticsPage />} />

                {/* HRM Module Routes */}
                <Route path="/hrm" element={<HRMDashboard />} />
                <Route path="/hrm/employees" element={<EmployeesPage />} />
                <Route path="/hrm/contracts" element={<ContractsPage />} />
                <Route path="/hrm/timeshifts" element={<TimeshiftsPage />} />
                <Route path="/hrm/attendance" element={<AttendancePage />} />
                <Route path="/hrm/leave-requests" element={<LeaveRequestsPage />} />
                <Route path="/hrm/payroll" element={<PayrollPage />} />

                {/* Event Management Module Routes */}
                <Route path="/event-management" element={<MyEventsDashboard />} />
                <Route path="/event-management/browse" element={<BrowseEventsPage />} />
                <Route path="/event-management/dashboard" element={<MyEventsDashboard />} />
                <Route path="/event-management/staff" element={<StaffManagementPage />} />
                <Route path="/event-management/operations" element={<LiveOperationsDashboard />} />
                <Route path="/event-management/bookings" element={<MyBookingsPage />} />
                <Route path="/event-management/tickets" element={<MyTicketsPage />} />
                <Route path="/event-management/feedback" element={<FeedbackSystem />} />
                <Route path="/event-portal" element={<EventPortalPage />} />
                
                {/* Web Shop Routes - inside MainLayout for admin access too */}
                {/* NOTE: Checkout accessible publicly below, outside ProtectedRoute */}
                
                {/* Website Builder Index - With sidebar */}
                <Route path="/website-builder" element={<WebsitesIndex />} />
              </Route>

              {/* Website Builder Routes - Full page, no sidebar (still admin-protected) */}
              <Route path="/website-builder/:id" element={<WebsiteEditor />} />
              <Route path="/website-builder/:id/pages" element={<PageManagement />} />

              {/* POS Layout Routes - fullscreen without sidebar */}
              <Route element={<POSLayout />}>
                <Route path="/pos/terminal" element={<POSTerminal />} />
                <Route path="/pos/customer-display" element={<POSCustomerDisplay />} />
              </Route>
            </Route>

            {/* ─── PUBLIC ROUTES (no admin auth required) ─────────────── */}

            {/* Web Shop Cart & Checkout - public, accessible from storefront */}
            <Route path="/web-shop/cart" element={<WebShopCartPage />} />
            <Route path="/web-shop/checkout" element={<WebShopCheckoutPage />} />
            <Route path="/web-shop/order/success" element={<OrderSuccessPage />} />

            {/* Web Shop Auth - No layout, no admin auth required */}
            <Route path="/web-shop/login" element={<WebShopLoginPage />} />
            <Route path="/web-shop/register" element={<WebShopRegisterPage />} />

            {/* Web Shop Customer Portal - uses its own WebCustomerAuth, NOT admin auth */}
            <Route path="/web-shop/account" element={<PortalLayout />}>
              <Route index element={<CustomerPortalDashboardPage />} />
              <Route path="orders" element={<OrdersListPage />} />
              <Route path="orders/:id" element={<OrderDetailPage />} />
              <Route path="profile" element={<ProfilePage />} />
              <Route path="addresses" element={<AddressesPage />} />
              <Route path="loyalty" element={<LoyaltyPage />} />
            </Route>

            {/* Public Website Viewer Routes - No auth required */}
            <Route path="/sites/:websiteSlug" element={<PublicSiteViewer />} />
            <Route path="/sites/:websiteSlug/:pageSlug" element={<PublicSiteViewer />} />
            
            {/* Payment callback for popup-based gateways (HitPay etc) */}
            <Route path="/payment/callback" element={<PaymentCallbackPage />} />

            {/* Public E-commerce Storefront */}
            <Route path="/shop" element={<EcommerceShopPage />} />
            <Route path="/shop/:pageType" element={<EcommerceShopPage />} />

            {/* Client Storefront (infiniti-kpop-store) */}
            <Route path="/store/*" element={<StorefrontApp />} />

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
