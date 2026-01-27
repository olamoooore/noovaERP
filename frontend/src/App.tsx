import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Toaster } from '@/components/ui/toaster'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { MainLayout } from '@/components/layout/MainLayout'
import { POSLayout } from '@/components/layout/POSLayout'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { ProductsPage } from '@/pages/ProductsPage'
import { ProductCreatePage } from '@/pages/Products/ProductCreatePage'
import { ProductViewPage } from '@/pages/Products/ProductViewPage'
import { ProductEditPage } from '@/pages/Products/ProductEditPage'
import { ProductSettingsPage } from '@/pages/Products/ProductSettingsPage'
import { CustomersPage } from '@/pages/CustomersPage'
import { InvoicesPage } from '@/pages/InvoicesPage'
import { PaymentsPage } from '@/pages/PaymentsPage'
import { AccountingPage } from '@/pages/AccountingPage'
import { ModulesPage } from '@/pages/Modules/ModulesPage'
import { POSPage } from '@/pages/POSPage'
import { POSTerminal, POSOrdersPage, POSBarcodePage, POSReportsPage } from '@/pages/POS'
import { SettingsPage } from '@/pages/SettingsPage'
import { WebsitesIndex } from '@/pages/WebsiteBuilder/WebsitesIndex'
import { WebsiteEditor } from '@/pages/WebsiteBuilder/WebsiteEditor'
import { PageManagement } from '@/pages/WebsiteBuilder/PageManagement'
import { PublicSiteViewer } from '@/pages/PublicSite/PublicSiteViewer'
import {
  InventoryDashboard,
  SuppliersPage,
  StockMovementsPage,
  StockInPage,
  StockOutPage,
  StockAdjustPage,
  StockManagementPage,
  PurchaseOrdersPage,
  PurchaseOrderCreatePage,
  WarehouseTransfersPage,
  WarehouseTransferCreatePage,
  WarehousesPage,
} from '@/pages/Inventory'
import MyEventsDashboard from '@/pages/EventManagement/MyEventsDashboard'
import BrowseEventsPage from '@/pages/EventManagement/BrowseEventsPage'
import MyBookingsPage from '@/pages/EventManagement/MyBookingsPage'
import MyTicketsPage from '@/pages/EventManagement/MyTicketsPage'
import { StaffManagementPage, LiveOperationsDashboard } from '@/pages/EventManagement'
import { EventPortalPage } from '@/pages/EventPortal'
import { useSettingsStore } from '@/store/settingsStore'

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
          <Routes>
          <Route path="/login" element={<LoginPage />} />
          
          {/* Public site viewer - no auth required */}
          <Route path="/sites/:websiteSlug" element={<PublicSiteViewer />} />
          <Route path="/sites/:websiteSlug/:pageSlug" element={<PublicSiteViewer />} />
          
          <Route element={<ProtectedRoute />}>
            {/* Main Layout Routes - with sidebar */}
            <Route element={<MainLayout />}>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/products" element={<ProductsPage />} />
              <Route path="/products/new" element={<ProductCreatePage />} />
              <Route path="/products/:id" element={<ProductViewPage />} />
              <Route path="/products/:id/edit" element={<ProductEditPage />} />
              <Route path="/products/settings" element={<ProductSettingsPage />} />
              <Route path="/customers" element={<CustomersPage />} />
              <Route path="/invoices" element={<InvoicesPage />} />
              <Route path="/payments" element={<PaymentsPage />} />
              <Route path="/accounting" element={<AccountingPage />} />
              <Route path="/modules" element={<ModulesPage />} />
              <Route path="/pos" element={<POSPage />} />
              <Route path="/pos/orders" element={<POSOrdersPage />} />
              <Route path="/pos/barcodes" element={<POSBarcodePage />} />
              <Route path="/pos/reports" element={<POSReportsPage />} />
              <Route path="/settings" element={<SettingsPage />} />
              <Route path="/website-builder" element={<WebsitesIndex />} />
              
              {/* Inventory Module Routes */}
              <Route path="/inventory" element={<InventoryDashboard />} />
              <Route path="/inventory/suppliers" element={<SuppliersPage />} />
              <Route path="/inventory/warehouses" element={<WarehousesPage />} />
              <Route path="/inventory/stock" element={<StockManagementPage />} />
              <Route path="/inventory/movements" element={<StockMovementsPage />} />
              <Route path="/inventory/stock/in" element={<StockInPage />} />
              <Route path="/inventory/stock/out" element={<StockOutPage />} />
              <Route path="/inventory/stock/adjust" element={<StockAdjustPage />} />
              <Route path="/inventory/purchase-orders" element={<PurchaseOrdersPage />} />
              <Route path="/inventory/purchase-orders/new" element={<PurchaseOrderCreatePage />} />
              <Route path="/inventory/transfers" element={<WarehouseTransfersPage />} />
              <Route path="/inventory/transfers/new" element={<WarehouseTransferCreatePage />} />
              
              {/* Event Management Module Routes */}
              <Route path="/events" element={<MyEventsDashboard />} />
              <Route path="/events/browse" element={<BrowseEventsPage />} />
              <Route path="/events/bookings" element={<MyBookingsPage />} />
              <Route path="/events/tickets" element={<MyTicketsPage />} />
              <Route path="/event-portal" element={<EventPortalPage />} />
              <Route path="/event-management/staff" element={<StaffManagementPage />} />
              <Route path="/event-management/operations" element={<LiveOperationsDashboard />} />
            </Route>

            {/* Website Editor - fullscreen without sidebar */}
            <Route element={<ProtectedRoute />}>
              <Route path="/website-builder/:id" element={<WebsiteEditor />} />
              <Route path="/website-builder/:id/pages" element={<PageManagement />} />
            </Route>

            {/* POS Layout Routes - fullscreen without sidebar */}
            <Route element={<POSLayout />}>
              <Route path="/pos/terminal" element={<POSTerminal />} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </div>
      <Toaster />
    </QueryClientProvider>
  )
}

export default App
