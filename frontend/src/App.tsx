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
const ProductViewPage = lazy(() => import('@/pages/Products/ProductViewPage').then(m => ({ default: m.ProductViewPage })))
const ProductEditPage = lazy(() => import('@/pages/Products/ProductEditPage').then(m => ({ default: m.ProductEditPage })))
const ProductSettingsPage = lazy(() => import('@/pages/Products/ProductSettingsPage').then(m => ({ default: m.ProductSettingsPage })))
const CustomersPage = lazy(() => import('@/pages/CustomersPage').then(m => ({ default: m.CustomersPage })))
const CustomerDetailsPage = lazy(() => import('@/pages/Customers/CustomerDetailsPage').then(m => ({ default: m.CustomerDetailsPage })))
const InvoicesPage = lazy(() => import('@/pages/InvoicesPage').then(m => ({ default: m.InvoicesPage })))
const PaymentsPage = lazy(() => import('@/pages/PaymentsPage').then(m => ({ default: m.PaymentsPage })))
const AccountingPage = lazy(() => import('@/pages/AccountingPage').then(m => ({ default: m.AccountingPage })))
const ModulesPage = lazy(() => import('@/pages/Modules/ModulesPage').then(m => ({ default: m.ModulesPage })))
const POSPage = lazy(() => import('@/pages/POSPage').then(m => ({ default: m.POSPage })))
const POSTerminal = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSTerminal })))
const POSOrdersPage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSOrdersPage })))
const POSBarcodePage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSBarcodePage })))
const POSReportsPage = lazy(() => import('@/pages/POS').then(m => ({ default: m.POSReportsPage })))
const SettingsPage = lazy(() => import('@/pages/SettingsPage').then(m => ({ default: m.SettingsPage })))
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
        <BrowserRouter>
          <Suspense fallback={<PageLoader />}>
            <Routes>
            <Route path="/login" element={<LoginPage />} />
            
            <Route element={<ProtectedRoute />}>
              {/* Main Layout Routes - with sidebar */}
              <Route element={<MainLayout />}>
                <Route path="/" element={<DashboardPage />} />
                <Route path="/products" element={<ProductsPage />} />
                <Route path="/products/new" element={<ProductCreatePage />} />
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
                <Route path="/payments" element={<PaymentsPage />} />
                <Route path="/accounting" element={<AccountingPage />} />
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
