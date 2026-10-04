import { lazy, Suspense, type ReactNode } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { Toaster } from '@/components/ui/toaster'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { MainLayout } from '@/components/layout/MainLayout'
import { POSLayout } from '@/components/layout/POSLayout'
import { LoginPage } from '@/pages/LoginPage'
import { ForgotPasswordPage } from '@/pages/ForgotPasswordPage'
import { ResetPasswordPage } from '@/pages/ResetPasswordPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { RequireSettingsAccess } from '@/components/auth/RequireSettingsAccess'
import { RequirePermissionAccess } from '@/components/auth/RequirePermissionAccess'
import { useAuthStore } from '@/store/authStore'
import { useSettingsStore } from '@/store/settingsStore'
import { useStripeDevtoolsGuard } from '@/hooks/useStripeDevtoolsGuard'
import { createOptimizedQueryClient } from '@/lib/optimized-query-client'

// Lazy load heavy pages for better initial load performance
const ProductsPage = lazy(() => import('@/pages/ProductsPage').then(m => ({ default: m.ProductsPage })))
const ContactsDashboardPage = lazy(() => import('@/pages/ContactsDashboardPage').then(m => ({ default: m.ContactsDashboardPage })))
const ProductDashboardPage = lazy(() => import('@/pages/Products/ProductDashboardPage').then(m => ({ default: m.ProductDashboardPage })))
const ProductCreatePage = lazy(() => import('@/pages/Products/ProductCreatePage').then(m => ({ default: m.ProductCreatePage })))
const ProductImportPage = lazy(() => import('@/pages/Products/ProductImportPage').then(m => ({ default: m.ProductImportPage })))
const ProductViewPage = lazy(() => import('@/pages/Products/ProductViewPage').then(m => ({ default: m.ProductViewPage })))
const ProductEditPage = lazy(() => import('@/pages/Products/ProductEditPage').then(m => ({ default: m.ProductEditPage })))
const ProductSettingsPage = lazy(() => import('@/pages/Products/ProductSettingsPage').then(m => ({ default: m.ProductSettingsPage })))
const CustomersPage = lazy(() => import('@/pages/CustomersPage').then(m => ({ default: m.CustomersPage })))
const CustomerDetailsPage = lazy(() => import('@/pages/Customers/CustomerDetailsPage').then(m => ({ default: m.CustomerDetailsPage })))
const CustomerImportPage = lazy(() => import('@/pages/Customers/CustomerImportPage').then(m => ({ default: m.CustomerImportPage })))
const InvoicesPage = lazy(() => import('@/pages/InvoicesPage').then(m => ({ default: m.InvoicesPage })))
const InvoiceDetailPage = lazy(() => import('@/pages/InvoiceDetailPage').then(m => ({ default: m.InvoiceDetailPage })))
const InvoicePaymentPage = lazy(() => import('@/pages/InvoicePaymentPage').then(m => ({ default: m.InvoicePaymentPage })))
const InvoiceImportPage = lazy(() => import('@/pages/InvoiceImportPage').then(m => ({ default: m.InvoiceImportPage })))
const NotificationsPage = lazy(() => import('@/pages/NotificationsPage').then(m => ({ default: m.default })))
const MyActivitiesPage = lazy(() => import('@/pages/Activities/MyActivitiesPage'))
const SearchResultsPage = lazy(() => import('@/pages/SearchResultsPage').then(m => ({ default: m.default })))
const ApprovalsInboxPage = lazy(() => import('@/pages/ApprovalsInboxPage').then(m => ({ default: m.default })))
const InvoiceAcceptancePage = lazy(() => import('@/pages/InvoiceAcceptancePage').then(m => ({ default: m.default })))
const QuotationAcceptancePage = lazy(() => import('@/pages/QuotationAcceptancePage').then(m => ({ default: m.default })))
const DeliveryOrderAcceptancePage = lazy(() => import('@/pages/DeliveryOrderAcceptancePage').then(m => ({ default: m.default })))
const EsignPage = lazy(() => import('@/pages/EsignPage').then(m => ({ default: m.EsignPage })))
const PaymentsPage = lazy(() => import('@/pages/PaymentsPage').then(m => ({ default: m.PaymentsPage })))
const AccountingPage = lazy(() => import('@/pages/AccountingPage').then(m => ({ default: m.AccountingPage })))
const ModulesPage = lazy(() => import('@/pages/Modules/ModulesPage').then(m => ({ default: m.ModulesPage })))
const POSPage = lazy(() => import('@/pages/POSPage').then(m => ({ default: m.POSPage })))
const POSTerminal = lazy(() => import('@/pages/POS/POSTerminal').then(m => ({ default: m.POSTerminal })))
const POSCustomerDisplay = lazy(() => import('@/pages/POS/POSCustomerDisplay').then(m => ({ default: m.POSCustomerDisplay })))
const POSCustomerDisplayV2 = lazy(() => import('@/pages/POS/POSCustomerDisplayV2').then(m => ({ default: m.POSCustomerDisplayV2 })))
const POSOrdersPage = lazy(() => import('@/pages/POS/POSOrdersPage').then(m => ({ default: m.POSOrdersPage })))
const POSApprovalQueuePage = lazy(() => import('@/pages/POS/POSApprovalQueuePage').then(m => ({ default: m.POSApprovalQueuePage })))
const POSOfflineQueuePage = lazy(() => import('@/pages/POS/POSOfflineQueuePage').then(m => ({ default: m.POSOfflineQueuePage })))
const POSBarcodePage = lazy(() => import('@/pages/POS/POSBarcodePage').then(m => ({ default: m.POSBarcodePage })))
const POSReportsPage = lazy(() => import('@/pages/POS/POSReportsPage').then(m => ({ default: m.POSReportsPage })))
const POSSettingsPage = lazy(() => import('@/pages/POS/POSSettingsPage').then(m => ({ default: m.POSSettingsPage })))
const SettingsPage = lazy(() => import('@/pages/SettingsPage').then(m => ({ default: m.SettingsPage })))
const TenantUsersPage = lazy(() => import('@/pages/TenantUsersPage'))
const UserProfilePage = lazy(() => import('@/pages/Profile/ProfilePage').then(m => ({ default: m.ProfilePage })))
const ReceiptBuilderPage = lazy(() => import('@/pages/Settings/ReceiptBuilderPage').then(m => ({ default: m.ReceiptBuilderPage })))
const CountryPluginSettings = lazy(() => import('@/pages/Settings/CountryPluginSettings').then(m => ({ default: m.CountryPluginSettings })))
const TemplateEditorPage = lazy(() => import('@/pages/TemplateBuilder/TemplateEditorPage'))
const SalesSettingsPage = lazy(() => import('@/pages/sales/SalesSettingsPage').then(m => ({ default: m.SalesSettingsPage })))
const PriceListsPage = lazy(() => import('@/pages/sales/PriceListsPage'))
const IoTDevicesPage = lazy(() => import('@/pages/IoT/IoTDevicesPage').then(m => ({ default: m.IoTDevicesPage })))
const IoTHubPage = lazy(() => import('@/pages/IoTHub').then(m => ({ default: m.IoTHubPage })))
const ManualsLibraryPage = lazy(() => import('@/pages/Manuals/ManualsLibraryPage').then(m => ({ default: m.ManualsLibraryPage })))
const OCRDocumentsPage = lazy(() => import('@/pages/OCRDocumentsPage').then(m => ({ default: m.OCRDocumentsPage })))
const OCRDocumentReviewPage = lazy(() => import('@/pages/OCRDocumentReviewPage').then(m => ({ default: m.OCRDocumentReviewPage })))

// Lazy load reporting pages (heavy with charts)
const FinancialReportsPage = lazy(() => import('@/pages/reports/FinancialReportsPage').then(m => ({ default: m.FinancialReportsPage })))
const InventoryReportsPage = lazy(() => import('@/pages/reports/InventoryReportsPage').then(m => ({ default: m.InventoryReportsPage })))
const SalesAnalyticsPage = lazy(() => import('@/pages/reports/SalesAnalyticsPage').then(m => ({ default: m.SalesAnalyticsPage })))
const ExpenseAnalyticsPage = lazy(() => import('@/pages/reports/ExpenseAnalyticsPage').then(m => ({ default: m.ExpenseAnalyticsPage })))
const LotTraceabilityPage = lazy(() => import('@/pages/reports/LotTraceabilityPage'))

// Lazy load dashboard pages (heavy with charts)
const ExecutiveDashboard = lazy(() => import('@/pages/dashboards/ExecutiveDashboard').then(m => ({ default: m.ExecutiveDashboard })))
const SalesDashboard = lazy(() => import('@/pages/dashboards/SalesDashboard').then(m => ({ default: m.SalesDashboard })))
const QuotesPage = lazy(() => import('@/pages/sales/QuotesPage').then(m => ({ default: m.QuotesPage })))
const QuotationCreatePage = lazy(() => import('@/pages/sales/QuotationCreatePage').then(m => ({ default: m.QuotationCreatePage })))
const QuotationEditPage = lazy(() => import('@/pages/sales/QuotationEditPage').then(m => ({ default: m.QuotationEditPage })))
const QuotationDetailPage = lazy(() => import('@/pages/sales/QuotationDetailPage').then(m => ({ default: m.QuotationDetailPage })))
const QuotationImportPage = lazy(() => import('@/pages/sales/QuotationImportPage').then(m => ({ default: m.QuotationImportPage })))
const DeliveryOrdersPage = lazy(() => import('@/pages/sales/DeliveryOrdersPage').then(m => ({ default: m.DeliveryOrdersPage })))
const DeliveryOrderCreatePage = lazy(() => import('@/pages/sales/DeliveryOrderCreatePage').then(m => ({ default: m.DeliveryOrderCreatePage })))
const DeliveryOrderDetailPage = lazy(() => import('@/pages/sales/DeliveryOrderDetailPage').then(m => ({ default: m.DeliveryOrderDetailPage })))
const DeliveryOrderImportPage = lazy(() => import('@/pages/sales/DeliveryOrderImportPage').then(m => ({ default: m.DeliveryOrderImportPage })))
const DeliveryOrderEditPage = lazy(() => import('@/pages/sales/DeliveryOrderEditPage').then(m => ({ default: m.DeliveryOrderEditPage })))
const SalesOrdersPage = lazy(() => import('@/pages/sales/SalesOrdersPage').then(m => ({ default: m.SalesOrdersPage })))
const SalesOrderDetailPage = lazy(() => import('@/pages/sales/SalesOrderDetailPage').then(m => ({ default: m.SalesOrderDetailPage })))
const MedicalCasesPage = lazy(() => import('@/pages/sales/MedicalCasesPage').then(m => ({ default: m.MedicalCasesPage })))
const MedicalCaseDetailPage = lazy(() => import('@/pages/sales/MedicalCaseDetailPage').then(m => ({ default: m.MedicalCaseDetailPage })))
const MedicalCaseCreatePage = lazy(() => import('@/pages/sales/MedicalCaseCreatePage').then(m => ({ default: m.MedicalCaseCreatePage })))
const BonusSchemesPage = lazy(() => import('@/pages/sales/BonusSchemesPage').then(m => ({ default: m.BonusSchemesPage })))
const BonusSchemeDetailPage = lazy(() => import('@/pages/sales/BonusSchemeDetailPage').then(m => ({ default: m.BonusSchemeDetailPage })))
const BonusSchemeCreatePage = lazy(() => import('@/pages/sales/BonusSchemeCreatePage').then(m => ({ default: m.BonusSchemeCreatePage })))
const CustomerBonusSchemesPage = lazy(() => import('@/pages/sales/CustomerBonusSchemesPage').then(m => ({ default: m.CustomerBonusSchemesPage })))
const CustomerBonusSchemeCreatePage = lazy(() => import('@/pages/sales/CustomerBonusSchemeCreatePage').then(m => ({ default: m.CustomerBonusSchemeCreatePage })))
const InventoryDashboard = lazy(() => import('@/pages/dashboards/InventoryDashboard').then(m => ({ default: m.InventoryDashboard })))
const FinancialDashboard = lazy(() => import('@/pages/dashboards/FinancialDashboard').then(m => ({ default: m.FinancialDashboard })))

// Lazy load new accounting module pages
const AccountingDashboard = lazy(() => import('@/pages/accounting/AccountingDashboard').then(m => ({ default: m.AccountingDashboard })))
const CustomerBills = lazy(() => import('@/pages/accounting/customers/CustomerBills').then(m => ({ default: m.CustomerBills })))
const CreateInvoicePage = lazy(() => import('@/pages/accounting/customers/CreateInvoicePage').then(m => ({ default: m.CreateInvoicePage })))
const CustomerInvoiceDetail = lazy(() => import('@/pages/accounting/customers/CustomerInvoiceDetail').then(m => ({ default: m.CustomerInvoiceDetail })))
const CustomerPayments = lazy(() => import('@/pages/accounting/customers/CustomerPayments').then(m => ({ default: m.CustomerPayments })))
const PaymentImportPage = lazy(() => import('@/pages/accounting/customers/PaymentImportPage').then(m => ({ default: m.PaymentImportPage })))
const CustomerFollowupReports = lazy(() => import('@/pages/accounting/customers/CustomerFollowupReports').then(m => ({ default: m.CustomerFollowupReports })))
const CustomerStatements = lazy(() => import('@/pages/accounting/customers/CustomerStatements').then(m => ({ default: m.CustomerStatements })))
const VendorsPage = lazy(() => import('@/pages/accounting/vendors/VendorsPage'))
const VendorImportPage = lazy(() => import('@/pages/accounting/vendors/VendorImportPage').then(m => ({ default: m.VendorImportPage })))
const VendorBills = lazy(() => import('@/pages/accounting/vendors/VendorBills').then(m => ({ default: m.VendorBills })))
const CreateVendorBillPage = lazy(() => import('@/pages/accounting/vendors/CreateVendorBillPage').then(m => ({ default: m.CreateVendorBillPage })))
const VendorBillDetail = lazy(() => import('@/pages/accounting/vendors/VendorBillDetail').then(m => ({ default: m.VendorBillDetail })))
const VendorPayments = lazy(() => import('@/pages/accounting/vendors/VendorPayments').then(m => ({ default: m.VendorPayments })))
const AgedReceivablesPage = lazy(() => import('@/pages/accounting/reports/AgedReceivablesPage').then(m => ({ default: m.AgedReceivablesPage })))
const AgedPayablesPage = lazy(() => import('@/pages/accounting/reports/AgedPayablesPage').then(m => ({ default: m.AgedPayablesPage })))
const BankReconciliationPage = lazy(() => import('@/pages/accounting/BankReconciliationPage').then(m => ({ default: m.BankReconciliationPage })))
const BankReconciliationHistoryPage = lazy(() => import('@/pages/accounting/BankReconciliationHistoryPage'))
const BankStatementImportHistoryPage = lazy(() => import('@/pages/accounting/BankStatementImportHistoryPage'))
const ApSubledgerReconciliationPage = lazy(() => import('@/pages/accounting/ApSubledgerReconciliationPage').then(m => ({ default: m.ApSubledgerReconciliationPage })))
const AssetsManagementPage = lazy(() => import('@/pages/accounting/AssetsManagementPage'))
const FixedAssetImportPage = lazy(() => import('@/pages/accounting/FixedAssetImportPage'))
const LockDatesPage = lazy(() => import('@/pages/accounting/LockDatesPage'))
const AnalyticAccountsPage = lazy(() => import('@/pages/accounting/AnalyticAccountsPage'))
const CompanySettingsPage = lazy(() => import('@/pages/accounting/CompanySettingsPage'))
const ChartOfAccountsImportPage = lazy(() => import('@/pages/accounting/ChartOfAccountsImportPage'))
const PaymentTermsPage = lazy(() => import('@/pages/accounting/PaymentTermsPage'))
const JournalEntriesPage = lazy(() => import('@/pages/accounting/JournalEntriesPage'))
const JournalEntryImportPage = lazy(() => import('@/pages/accounting/JournalEntryImportPage'))
const JournalsPage = lazy(() => import('@/pages/accounting/JournalsPage'))
const TaxConfigurationPage = lazy(() => import('@/pages/accounting/TaxConfigurationPage'))
const TaxEnginePage = lazy(() => import('@/pages/accounting/TaxEnginePage'))
const TaxReturnsPage = lazy(() => import('@/pages/accounting/TaxReturnsPage'))
const TaxReportPage = lazy(() => import('@/pages/accounting/TaxReportPage'))
const AiTaxAdvisoryPage = lazy(() => import('@/pages/AI/TaxAdvisoryPage'))
const BankAccountsPage = lazy(() => import('@/pages/accounting/BankAccountsPage'))
const CustomerCreditNotesPage = lazy(() => import('@/pages/accounting/CustomerCreditNotesPage'))
const CustomerCreditNoteDetail = lazy(() => import('@/pages/accounting/customers/CustomerCreditNoteDetail'))
const VendorCreditNotesPage = lazy(() => import('@/pages/accounting/VendorCreditNotesPage'))
const VendorCreditNoteDetail = lazy(() => import('@/pages/accounting/vendors/VendorCreditNoteDetail'))
const VendorDebitNotesPage = lazy(() => import('@/pages/accounting/VendorDebitNotesPage'))
const VendorDebitNoteDetail = lazy(() => import('@/pages/accounting/vendors/VendorDebitNoteDetail'))
const ARTransactionsPage = lazy(() => import('@/pages/accounting/ARTransactionsPage'))
const APTransactionsPage = lazy(() => import('@/pages/accounting/APTransactionsPage'))
const BalanceSheetPage = lazy(() => import('@/pages/accounting/reports/BalanceSheetPage'))
const FinancialStatementPage = lazy(() => import('@/pages/accounting/reports/FinancialStatementPage'))

// Auditor Portal Pages
const AuditorManagementPage = lazy(() => import('@/pages/auditor/AuditorManagementPage'))
const AuditorDashboardPage = lazy(() => import('@/pages/auditor/AuditorDashboardPage'))
const AuditorQueriesPage = lazy(() => import('@/pages/auditor/AuditorQueriesPage'))
const AuditorExportsPage = lazy(() => import('@/pages/auditor/AuditorExportsPage'))
const AuditorActivityPage = lazy(() => import('@/pages/auditor/AuditorActivityPage'))
const ProfitLossPage = lazy(() => import('@/pages/accounting/reports/ProfitLossPage'))
const CashFlowPage = lazy(() => import('@/pages/accounting/reports/CashFlowPage'))
const GeneralLedgerPage = lazy(() => import('@/pages/accounting/reports/GeneralLedgerPage'))
const TrialBalancePage = lazy(() => import('@/pages/accounting/reports/TrialBalancePage'))
const FxReportPage = lazy(() => import('@/pages/accounting/reports/FxReportPage'))
const ComplianceReportsPage = lazy(() => import('@/pages/accounting/reports/ComplianceReportsPage'))
const MyInvoisEinvoicePage = lazy(() => import('@/pages/accounting/MyInvoisEinvoicePage'))
const MyInvoiceDocumentPage = lazy(() => import('@/pages/accounting/MyInvoiceDocumentPage'))
const MyInvoiceSubmissionPage = lazy(() => import('@/pages/accounting/MyInvoiceSubmissionPage'))
const MyInvoisSettingsPage = lazy(() => import('@/pages/accounting/MyInvoisSettingsPage'))
const AccountingPoliciesPage = lazy(() => import('@/pages/accounting/AccountingPoliciesPage'))
const AccountingFindingsPage = lazy(() => import('@/pages/accounting/AccountingFindingsPage'))
const SettlementsPage = lazy(() => import('@/pages/accounting/SettlementsPage'))

// AI Module Pages
const AIChatPage = lazy(() => import('@/pages/AI/AIChatPage').then(m => ({ default: m.AIChatPage })))

// Financial Close Module Pages
const CloseDashboard     = lazy(() => import('@/pages/FinancialClose/CloseDashboard').then(m => ({ default: m.CloseDashboard })))
const CloseCyclesPage    = lazy(() => import('@/pages/FinancialClose/CloseCyclesPage').then(m => ({ default: m.CloseCyclesPage })))
const MyTasksPage        = lazy(() => import('@/pages/FinancialClose/MyTasksPage').then(m => ({ default: m.MyTasksPage })))
const TaskDetailPage     = lazy(() => import('@/pages/FinancialClose/TaskDetailPage').then(m => ({ default: m.TaskDetailPage })))
const ReviewQueuePage    = lazy(() => import('@/pages/FinancialClose/ReviewQueuePage').then(m => ({ default: m.ReviewQueuePage })))
const AiFindingsPage     = lazy(() => import('@/pages/FinancialClose/AiFindingsPage').then(m => ({ default: m.AiFindingsPage })))
const ReadinessCockpitPage = lazy(() => import('@/pages/FinancialClose/ReadinessCockpitPage').then(m => ({ default: m.ReadinessCockpitPage })))
const ForecastPage       = lazy(() => import('@/pages/FinancialClose/ForecastPage').then(m => ({ default: m.ForecastPage })))
const ExecutiveSummaryPage = lazy(() => import('@/pages/FinancialClose/ExecutiveSummaryPage').then(m => ({ default: m.ExecutiveSummaryPage })))
const AuditTrailPage = lazy(() => import('@/pages/FinancialClose/AuditTrailPage'))

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
const MyEventsDashboard = lazy(() => import('@/pages/EventManagement/EventsWorkspacePage'))
const MyBookingsPage = lazy(() => import('@/pages/EventManagement/MyBookingsPage'))
const MyTicketsPage = lazy(() => import('@/pages/EventManagement/MyTicketsPage'))
const FeedbackSystem = lazy(() => import('@/pages/EventManagement/FeedbackSystem'))
const EventPortalPage = lazy(() => import('@/pages/EventPortal').then(m => ({ default: m.EventPortalPage })))

// Storefront Module Pages (tenant admin: orders console + settings)
const StorefrontOrdersPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontOrdersPage })))
const StorefrontOrderDetailPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontOrderDetailPage })))
const StorefrontReturnsPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontReturnsPage })))
const StorefrontPromotionsPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontPromotionsPage })))
const StorefrontReportsPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontReportsPage })))
const StorefrontSettingsPage = lazy(() => import('@/pages/Storefront').then(m => ({ default: m.StorefrontSettingsPage })))

// Loyalty Module Pages
const LoyaltyDashboardPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyDashboardPage })))
const LoyaltyCustomersPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyCustomersPage })))
const LoyaltyCustomerDetailPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyCustomerDetailPage })))
const LoyaltyRewardsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyRewardsPage })))
const LoyaltyRedemptionsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltyRedemptionsPage })))
const LoyaltySettingsPage = lazy(() => import('@/pages/Loyalty').then(m => ({ default: m.LoyaltySettingsPage })))

// CRM Module Pages
const CRMPage = lazy(() => import('@/pages/CRM').then(m => ({ default: m.CRMPage })))
const CRMLeadsPage = lazy(() => import('@/pages/CRM').then(m => ({ default: m.LeadsPage })))
const CRMLeadForm = lazy(() => import('@/pages/CRM').then(m => ({ default: m.LeadForm })))
const CRMLeadDetailPage = lazy(() => import('@/pages/CRM').then(m => ({ default: m.LeadDetailPage })))

// Web Shop Pages
const PaymentCallbackPage = lazy(() => import('@/pages/PaymentCallbackPage'))

// Web Shop Customer Portal

// Website Builder Pages
const WebsitesIndex = lazy(() => import('@/pages/WebsiteBuilder/WebsitesIndex').then(m => ({ default: m.WebsitesIndex })))
const WebsiteEditor = lazy(() => import('@/pages/WebsiteBuilder/WebsiteEditor').then(m => ({ default: m.WebsiteEditor })))
const PageManagement = lazy(() => import('@/pages/WebsiteBuilder/PageManagement').then(m => ({ default: m.PageManagement })))

// Public Site Viewer
const PublicSiteViewer = lazy(() => import('@/pages/PublicSite').then(m => ({ default: m.PublicSiteViewer })))

// Ecommerce Shop Pages (public-facing)

// Legacy /store SPA retired (FR-ARC-06 P2) — client-side 410 notice.
const LegacyStoreRetiredPage = lazy(() =>
  import('@/pages/StoreRetired/LegacyStoreRetiredPage').then(m => ({ default: m.LegacyStoreRetiredPage }))
)

// Inventory pages
const SuppliersPage = lazy(() => import('@/pages/Inventory/SuppliersPage').then(m => ({ default: m.SuppliersPage })))
const InventoryDashboardPage = lazy(() => import('@/pages/Inventory/InventoryDashboard').then(m => ({ default: m.InventoryDashboard })))
const StockManagementPage = lazy(() => import('@/pages/Inventory/StockManagementPage').then(m => ({ default: m.StockManagementPage })))
const StockMovementsPage = lazy(() => import('@/pages/Inventory/StockMovementsPage').then(m => ({ default: m.StockMovementsPage })))
const StockOperationsPage = lazy(() => import('@/pages/Inventory/StockOperationsPage').then(m => ({ default: m.StockOperationsPage })))
const InventoryTrackingPage = lazy(() => import('@/pages/Inventory/InventoryTrackingPage').then(m => ({ default: m.InventoryTrackingPage })))
const InventoryTrackingImportPage = lazy(() => import('@/pages/Inventory/InventoryTrackingImportPage').then(m => ({ default: m.InventoryTrackingImportPage })))
const PurchaseOrdersPage = lazy(() => import('@/pages/Inventory/PurchaseOrdersPage').then(m => ({ default: m.PurchaseOrdersPage })))
const PurchaseOrderCreatePage = lazy(() => import('@/pages/Inventory/PurchaseOrderCreatePage').then(m => ({ default: m.PurchaseOrderCreatePage })))
const WarehouseTransfersPage = lazy(() => import('@/pages/Inventory/WarehouseTransfersPage').then(m => ({ default: m.WarehouseTransfersPage })))
const WarehouseTransferCreatePage = lazy(() => import('@/pages/Inventory/WarehouseTransferCreatePage').then(m => ({ default: m.WarehouseTransferCreatePage })))
const InventorySettingsPage = lazy(() => import('@/pages/Inventory/InventorySettingsPage').then(m => ({ default: m.InventorySettingsPage })))

// Payment Gateway Pages
const PaymentDashboard = lazy(() => import('@/pages/PaymentDashboard').then(m => ({ default: m.PaymentDashboard })))
const PaymentCheckout = lazy(() => import('@/pages/Checkout/PaymentCheckout').then(m => ({ default: m.PaymentCheckout })))
const PaymentTestPage = lazy(() => import('@/pages/PaymentTestPage').then(m => ({ default: m.PaymentTestPage })))
const PaymentSuccess = lazy(() => import('@/pages/PaymentSuccess').then(m => ({ default: m.default })))
const PaymentGatewaySettings = lazy(() => import('@/pages/Settings/PaymentGatewaySettings').then(m => ({ default: m.PaymentGatewaySettings })))
const OfflinePaymentMethods = lazy(() => import('@/pages/Settings/OfflinePaymentMethods').then(m => ({ default: m.OfflinePaymentMethodsPage })))

// Fulfillment Pages
const FulfillmentSettings = lazy(() => import('@/pages/Settings/FulfillmentSettings').then(m => ({ default: m.FulfillmentSettings })))

// Public marketing site + signup
const LandingPage = lazy(() => import('@/pages/landing/LandingPage').then(m => ({ default: m.LandingPage })))
const SignupPage = lazy(() => import('@/pages/SignupPage').then(m => ({ default: m.SignupPage })))

// Root gate: authenticated users go to the dashboard, visitors see the website
function HomeGate() {
  const isAuthenticated = useAuthStore((state) => !!state.token)
  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />
  }
  return <LandingPage />
}

// Loading fallback component
const PageLoader = () => (
  <div className="flex items-center justify-center h-screen">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
  </div>
)

const queryClient = createOptimizedQueryClient()

/**
 * Gate for the reporting area: reports.view is seeded for Owner, Admin,
 * Manager, Accountant and Viewer — Sales/Cashier users get a clear
 * capability message instead of a page that 403s on every request.
 */
function ReportsGate({ children }: { children: ReactNode }) {
  return (
    <RequirePermissionAccess
      permission="reports.view"
      title="Reports access required"
      description="Your role does not include the reports.view permission, which is required for reporting pages. Ask an Owner or Admin if you need access."
    >
      {children}
    </RequirePermissionAccess>
  )
}

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
            {/* Public website + auth */}
            <Route path="/" element={<HomeGate />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/signup" element={<SignupPage />} />
            <Route path="/invoice-accept/:token" element={<InvoiceAcceptancePage />} />
            <Route path="/quotation-accept/:token" element={<QuotationAcceptancePage />} />
            <Route path="/do-accept/:token" element={<DeliveryOrderAcceptancePage />} />
            <Route path="/sign/:token" element={<EsignPage />} />
            
            <Route element={<ProtectedRoute />}>
              {/* Receipt Builder - fullscreen without sidebar */}
              <Route path="/receipt-builder" element={<ReceiptBuilderPage />} />

              {/* Template Builder editor - fullscreen without sidebar */}
              <Route path="/template-builder/:id" element={<TemplateEditorPage />} />

              {/* Main Layout Routes - with sidebar */}
              <Route element={<MainLayout />}>
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/master-contacts" element={<ContactsDashboardPage />} />
                <Route path="/products/dashboard" element={<ProductDashboardPage />} />
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
                <Route path="/inventory/tracking" element={<InventoryTrackingPage />} />
                <Route path="/inventory/tracking/import" element={<InventoryTrackingImportPage />} />
                <Route path="/inventory/purchase-orders" element={<PurchaseOrdersPage />} />
                <Route path="/inventory/purchase-orders/new" element={<PurchaseOrderCreatePage />} />
                <Route path="/inventory/transfers" element={<WarehouseTransfersPage />} />
                <Route path="/inventory/transfers/new" element={<WarehouseTransferCreatePage />} />
                <Route path="/inventory/warehouses" element={<Navigate to="/inventory/settings?tab=warehouses" replace />} />
                <Route path="/inventory/suppliers" element={<SuppliersPage />} />
                <Route path="/inventory/settings" element={<InventorySettingsPage />} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                
                {/* Sales Routes */}
                <Route path="/sales" element={<SalesDashboard />} />
                <Route path="/sales/orders" element={<SalesOrdersPage />} />
                <Route path="/sales/orders/:id" element={<SalesOrderDetailPage />} />
                <Route path="/sales/quotes" element={<QuotesPage />} />
                <Route path="/sales/quotes/create" element={<QuotationCreatePage />} />
                <Route path="/sales/quotes/:id" element={<QuotationDetailPage />} />

                {/* Medical Cases */}
                <Route path="/sales/medical-cases" element={<MedicalCasesPage />} />
                <Route path="/sales/medical-cases/create" element={<MedicalCaseCreatePage />} />
                <Route path="/sales/medical-cases/:id" element={<MedicalCaseDetailPage />} />

                {/* Bonus Schemes */}
                <Route path="/sales/bonus-schemes" element={<BonusSchemesPage />} />
                <Route path="/sales/bonus-schemes/create" element={<BonusSchemeCreatePage />} />
                <Route path="/sales/bonus-schemes/:id" element={<BonusSchemeDetailPage />} />

                {/* Customer Bonus Scheme Assignments */}
                <Route path="/sales/customer-bonus-schemes" element={<CustomerBonusSchemesPage />} />
                <Route path="/sales/customer-bonus-schemes/create" element={<CustomerBonusSchemeCreatePage />} />
                <Route path="/sales/settings" element={<SalesSettingsPage />} />
                <Route path="/sales/price-lists" element={<PriceListsPage />} />

                {/* Quotation Routes (new) */}
                <Route path="/quotations" element={<QuotesPage />} />
                <Route path="/quotations/create" element={<QuotationCreatePage />} />
                <Route path="/quotations/import" element={<QuotationImportPage />} />
                <Route path="/quotations/:id" element={<QuotationDetailPage />} />
                <Route path="/quotations/:id/edit" element={<QuotationEditPage />} />

                {/* Delivery Orders */}
                <Route path="/delivery-orders" element={<DeliveryOrdersPage />} />
                <Route path="/delivery-orders/create" element={<DeliveryOrderCreatePage />} />
                <Route path="/delivery-orders/import" element={<DeliveryOrderImportPage />} />
                <Route path="/delivery-orders/:id/edit" element={<DeliveryOrderEditPage />} />
                <Route path="/delivery-orders/:id" element={<DeliveryOrderDetailPage />} />
                
                {/* Customer Routes */}
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/customers/import" element={<CustomerImportPage />} />
                <Route path="/customers/:id" element={<CustomerDetailsPage />} />
                
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/invoices/import" element={<InvoiceImportPage />} />
                <Route path="/invoices/create" element={<CreateInvoicePage />} />
                <Route path="/invoices/:id/edit" element={<CreateInvoicePage />} />
                <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                <Route path="/invoices/:id/payment" element={<InvoicePaymentPage />} />
                <Route path="/notifications" element={<NotificationsPage />} />
                <Route path="/activities" element={<MyActivitiesPage />} />
                <Route path="/search" element={<SearchResultsPage />} />
                <Route path="/approvals" element={<ApprovalsInboxPage />} />
                <Route path="/accounting" element={<Navigate to="/accounting/dashboard" replace />} />
                
                {/* New Accounting Module Routes */}
                <Route path="/accounting/dashboard" element={<AccountingDashboard />} />
                
                {/* Customer Section */}
                <Route path="/accounting/customers/bills" element={<CustomerBills />} />
                <Route path="/accounting/customers/bills/create" element={<CreateInvoicePage />} />
                <Route path="/accounting/customers/bills/:id/edit" element={<CreateInvoicePage />} />
                <Route path="/accounting/customers/bills/:id" element={<CustomerInvoiceDetail />} />
                <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
                <Route path="/accounting/customers/payments/import" element={<PaymentImportPage />} />
                <Route path="/accounting/customers/credit-notes" element={<CustomerCreditNotesPage />} />
                <Route path="/accounting/customers/credit-notes/:id" element={<CustomerCreditNoteDetail />} />
                <Route path="/accounting/customers/transactions" element={<ARTransactionsPage />} />
                <Route path="/accounting/customers/followup-reports" element={<CustomerFollowupReports />} />
                <Route path="/accounting/customers/statements" element={<CustomerStatements />} />
                
                {/* Vendor Section */}
                <Route path="/accounting/vendors" element={<VendorsPage />} />
                <Route path="/accounting/vendors/import" element={<VendorImportPage />} />
                <Route path="/accounting/vendors/bills" element={<VendorBills />} />
                <Route path="/accounting/vendors/bills/create" element={<CreateVendorBillPage />} />
                <Route path="/accounting/vendors/bills/:id" element={<VendorBillDetail />} />
                <Route path="/accounting/vendors/payments" element={<VendorPayments />} />
                <Route path="/accounting/vendors/credit-notes" element={<VendorCreditNotesPage />} />
                <Route path="/accounting/vendors/credit-notes/:id" element={<VendorCreditNoteDetail />} />
                <Route path="/accounting/vendors/debit-notes" element={<VendorDebitNotesPage />} />
                <Route path="/accounting/vendors/debit-notes/:id" element={<VendorDebitNoteDetail />} />
                <Route path="/accounting/vendors/transactions" element={<APTransactionsPage />} />
                
                {/* Accounting Core Section */}
                <Route path="/accounting/core/chart-of-accounts" element={<Navigate to="/accounting/configuration/company?tab=chart-of-accounts" replace />} />
                <Route path="/accounting/core/chart-of-accounts/import" element={<ChartOfAccountsImportPage />} />
                <Route path="/accounting/core/journal-entries" element={<JournalEntriesPage />} />
                <Route path="/accounting/core/journal-entries/import" element={<JournalEntryImportPage />} />
                <Route path="/accounting/core/journals" element={<JournalsPage />} />
                <Route path="/accounting/core/reconciliation" element={<BankReconciliationPage />} />
                <Route path="/accounting/core/reconciliation/history" element={<BankReconciliationHistoryPage />} />
                <Route path="/accounting/core/reconciliation/import-history" element={<BankStatementImportHistoryPage />} />
                <Route path="/accounting/ap-reconciliation" element={<ApSubledgerReconciliationPage />} />
                <Route path="/accounting/core/assets" element={<AssetsManagementPage />} />
                <Route path="/accounting/core/assets/import" element={<FixedAssetImportPage />} />
                <Route path="/accounting/core/lock-dates" element={<LockDatesPage />} />
                <Route path="/accounting/core/multi-currency" element={<Navigate to="/accounting/configuration/company?tab=multi-currency" replace />} />
                <Route path="/accounting/core/analytic-accounts" element={<AnalyticAccountsPage />} />
                <Route path="/accounting/core/onboarding" element={<Navigate to="/accounting/configuration/company?tab=onboarding" replace />} />
                <Route path="/accounting/core/entities" element={<Navigate to="/accounting/configuration/company?tab=entities" replace />} />
                
                {/* Reporting Section */}
                <Route path="/accounting/reporting" element={<Navigate to="/accounting/reporting/trial-balance" replace />} />
                <Route path="/accounting/reporting/balance-sheet" element={<BalanceSheetPage />} />
                <Route path="/accounting/reporting/financial-statement" element={<FinancialStatementPage />} />
                <Route path="/accounting/reporting/profit-loss" element={<ProfitLossPage />} />
                <Route path="/accounting/reporting/cash-flow" element={<CashFlowPage />} />
                <Route path="/accounting/reporting/general-ledger" element={<GeneralLedgerPage />} />
                <Route path="/accounting/reporting/trial-balance" element={<TrialBalancePage />} />
                <Route path="/accounting/reporting/fx" element={<FxReportPage />} />
                <Route path="/accounting/reporting/aged-receivables" element={<AgedReceivablesPage />} />
                <Route path="/accounting/reporting/aged-payables" element={<AgedPayablesPage />} />
                <Route path="/accounting/reporting/customer-statements" element={<CustomerStatements />} />
                <Route path="/accounting/reporting/compliance" element={<ComplianceReportsPage />} />
                <Route path="/accounting/reporting/myinvoice" element={<MyInvoisEinvoicePage />} />
                <Route path="/accounting/reporting/myinvoice/:id" element={<MyInvoiceDocumentPage />} />
                <Route path="/accounting/reporting/myinvoice/:id/:submissionId" element={<MyInvoiceSubmissionPage />} />
                
                {/* Configuration Section */}
                <Route path="/accounting/configuration/company" element={<CompanySettingsPage />} />
                <Route path="/accounting/configuration/policies" element={<AccountingPoliciesPage />} />
                <Route path="/accounting/configuration/findings" element={<AccountingFindingsPage />} />
                <Route path="/accounting/settlements" element={<SettlementsPage />} />
                <Route path="/accounting/configuration/myinvois" element={<MyInvoisSettingsPage />} />
                <Route path="/accounting/configuration/payment-terms" element={<PaymentTermsPage />} />
                <Route path="/accounting/configuration/taxes" element={<TaxConfigurationPage />} />
                <Route path="/accounting/tax-engine" element={<TaxEnginePage />} />
                <Route path="/accounting/tax-returns" element={<TaxReturnsPage />} />
                <Route path="/accounting/tax-report" element={<TaxReportPage />} />
                <Route path="/ai/tax-advisory" element={<AiTaxAdvisoryPage />} />
                <Route path="/accounting/configuration/bank-accounts" element={<BankAccountsPage />} />
                <Route path="/accounting/configuration/country-plugin" element={<CountryPluginSettings />} />
                
                <Route path="/modules" element={<ModulesPage />} />
                <Route path="/pos" element={<POSPage />} />
                <Route path="/pos/orders" element={<POSOrdersPage />} />
                <Route path="/pos/approvals" element={<POSApprovalQueuePage />} />
                <Route path="/pos/offline-queue" element={<POSOfflineQueuePage />} />
                <Route path="/pos/barcodes" element={<POSBarcodePage />} />
                <Route path="/pos/reports" element={<POSReportsPage />} />
                <Route path="/pos/settings" element={<POSSettingsPage />} />
                <Route path="/ocr/documents" element={<OCRDocumentsPage />} />
                <Route path="/ocr/documents/:uuid" element={<OCRDocumentReviewPage />} />
                <Route path="/reports/financial" element={<ReportsGate><FinancialReportsPage /></ReportsGate>} />
                <Route path="/reports/inventory" element={<ReportsGate><InventoryReportsPage /></ReportsGate>} />
                <Route path="/reports/traceability" element={<ReportsGate><LotTraceabilityPage /></ReportsGate>} />
                <Route path="/products/report" element={<ReportsGate><SalesAnalyticsPage productOnly /></ReportsGate>} />
                <Route path="/reports/sales-analytics" element={<ReportsGate><SalesAnalyticsPage /></ReportsGate>} />
                <Route path="/reports/expense-analytics" element={<ReportsGate><ExpenseAnalyticsPage /></ReportsGate>} />
                <Route path="/dashboards/executive" element={<ExecutiveDashboard />} />
                <Route path="/dashboards/sales" element={<SalesDashboard />} />
                <Route path="/dashboards/inventory" element={<InventoryDashboard />} />
                <Route path="/dashboards/financial" element={<FinancialDashboard />} />
                <Route
                  path="/settings"
                  element={
                    <RequireSettingsAccess>
                      <SettingsPage />
                    </RequireSettingsAccess>
                  }
                />
                <Route
                  path="/settings/users"
                  element={
                    <RequireSettingsAccess permission="users.view">
                      <TenantUsersPage />
                    </RequireSettingsAccess>
                  }
                />
                <Route
                  path="/settings/iot-devices"
                  element={
                    <RequireSettingsAccess>
                      <IoTDevicesPage />
                    </RequireSettingsAccess>
                  }
                />
                <Route path="/iot-hub" element={<IoTHubPage />} />

                {/* Own profile */}
                <Route path="/profile" element={<UserProfilePage />} />
                
                {/* Payment Gateway Routes */}
                <Route path="/payments" element={<PaymentsPage />} />
                <Route path="/payment-analytics" element={<PaymentDashboard />} />
                <Route path="/checkout" element={<PaymentCheckout />} />
                <Route path="/payment-test" element={<PaymentTestPage />} />
                <Route path="/payment/success" element={<PaymentSuccess />} />
                <Route
                  path="/settings/payment-gateways"
                  element={
                    <RequireSettingsAccess>
                      <PaymentGatewaySettings />
                    </RequireSettingsAccess>
                  }
                />
                <Route
                  path="/settings/offline-payments"
                  element={
                    <RequireSettingsAccess>
                      <OfflinePaymentMethods />
                    </RequireSettingsAccess>
                  }
                />
                
                {/* Loyalty Module Routes */}
                <Route path="/loyalty" element={<LoyaltyDashboardPage />} />
                <Route path="/loyalty/customers" element={<LoyaltyCustomersPage />} />
                <Route path="/loyalty/customers/:customerId" element={<LoyaltyCustomerDetailPage />} />
                <Route path="/loyalty/rewards" element={<LoyaltyRewardsPage />} />
                <Route path="/loyalty/redemptions" element={<LoyaltyRedemptionsPage />} />
                <Route path="/loyalty/settings" element={<LoyaltySettingsPage />} />

                {/* Storefront Module Routes (tenant admin console) */}
                <Route path="/storefront/orders" element={<StorefrontOrdersPage />} />
                <Route path="/storefront/orders/:id" element={<StorefrontOrderDetailPage />} />
                <Route path="/storefront/returns" element={<StorefrontReturnsPage />} />
                <Route path="/storefront/promotions" element={<StorefrontPromotionsPage />} />
                <Route path="/storefront/reports" element={<StorefrontReportsPage />} />
                <Route path="/storefront/settings" element={<StorefrontSettingsPage />} />
                
                {/* AI Module Routes */}
                <Route path="/ai/chat" element={<AIChatPage />} />

                {/* Financial Close Module Routes */}
                <Route path="/financial-close" element={<CloseDashboard />} />
                <Route path="/financial-close/cycles" element={<CloseCyclesPage />} />
                <Route path="/financial-close/tasks" element={<MyTasksPage />} />
                <Route path="/financial-close/tasks/:taskId" element={<TaskDetailPage />} />
                <Route path="/financial-close/review-queue" element={<ReviewQueuePage />} />
                <Route path="/financial-close/findings" element={<AiFindingsPage />} />
                <Route path="/financial-close/readiness" element={<ReadinessCockpitPage />} />
                <Route path="/financial-close/forecast" element={<ForecastPage />} />
                <Route path="/financial-close/executive" element={<ExecutiveSummaryPage />} />
                <Route path="/financial-close/audit-trail" element={<AuditTrailPage />} />

                {/* Auditor Portal Routes */}
                <Route path="/auditor" element={<AuditorDashboardPage />} />
                <Route path="/auditor/dashboard" element={<AuditorDashboardPage />} />
                <Route path="/auditor/manage" element={<AuditorManagementPage />} />
                <Route path="/auditor/queries" element={<AuditorQueriesPage />} />
                <Route path="/auditor/exports" element={<AuditorExportsPage />} />
                <Route path="/auditor/activity" element={<AuditorActivityPage />} />

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

                {/* CRM Module Routes */}
                <Route path="/crm" element={<CRMPage />} />
                <Route path="/crm/leads" element={<CRMLeadsPage />} />
                <Route path="/crm/leads/new" element={<CRMLeadForm mode="create" />} />
                <Route path="/crm/leads/:id" element={<CRMLeadDetailPage />} />
                <Route path="/crm/leads/:id/edit" element={<CRMLeadForm mode="edit" />} />

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

                {/* Legacy Template Builder list route */}
                <Route path="/template-builder" element={<Navigate to="/sales/settings" replace />} />
              </Route>

              {/* Website Builder Routes - Full page, no sidebar (still admin-protected) */}
              <Route path="/website-builder/:id" element={<WebsiteEditor />} />
              <Route path="/website-builder/:id/pages" element={<PageManagement />} />

              {/* POS Layout Routes - fullscreen without sidebar */}
              <Route element={<POSLayout />}>
                <Route path="/pos/terminal" element={<POSTerminal />} />
                <Route path="/pos/customer-display" element={<POSCustomerDisplay />} />
                <Route path="/pos/customer-display/:terminalId" element={<POSCustomerDisplayV2 />} />
              </Route>
            </Route>

            {/* ─── PUBLIC ROUTES (no admin auth required) ─────────────── */}

            <Route path="/manuals" element={<ManualsLibraryPage />} />

            {/* Public Website Viewer Routes - No auth required */}
            <Route path="/sites/:websiteSlug" element={<PublicSiteViewer />} />
            <Route path="/sites/:websiteSlug/:pageSlug" element={<PublicSiteViewer />} />
            
            {/* Payment callback for popup-based gateways (HitPay etc) */}
            <Route path="/payment/callback" element={<PaymentCallbackPage />} />

            {/* Legacy /store SPA retired (FR-ARC-06 P2 stop-serving) */}
            <Route path="/store/*" element={<LegacyStoreRetiredPage />} />

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
