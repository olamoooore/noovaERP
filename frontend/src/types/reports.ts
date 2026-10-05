// Report Types and Interfaces

/** Issue #595: how a report derives its comparison window. */
export type ComparisonMode = 'previous_period' | 'same_period_last_year' | 'custom';

/** Issue #595: Δ / Δ% for one metric; delta_percent is null when previous is 0. */
export interface MetricComparison {
  previous: number;
  delta: number;
  delta_percent: number | null;
}

export interface ReportComparison {
  mode: ComparisonMode;
  period: { start_date: string; end_date: string };
  /** Summary-level comparison, keyed by summary field (e.g. total_revenue). */
  summary?: Record<string, MetricComparison>;
}

export interface ReportFilters {
  start_date?: string;
  end_date?: string;
  as_of_date?: string;
  compare_period?: boolean;
  compare_date?: string;
  department_id?: number;
  account_id?: number;
  account_type?: string;
  category_id?: number;
  location_id?: number;
  product_id?: number;
  customer_id?: number;
  vendor_id?: number;
  /** Tag slug — filter documents carrying this tag. */
  tag?: string;
  /** MRD FR-8.1: restrict sales analytics to a document currency. */
  currency_code?: string;
  /** Issue #593: the customer's assigned salesperson (customers.salesperson_id). */
  salesperson_id?: number;
  /** Issue #593: sales channel. */
  channel?: 'pos' | 'admin' | 'ecommerce' | 'storefront';
  /** Issue #593: legal entity scope (falls back to the session's entity context). */
  legal_entity_id?: number;
  /**
   * Issue #595: comparison mode. Derived from the primary period
   * (`previous_period`, `same_period_last_year`) or an explicit range.
   */
  comparison?: ComparisonMode;
  /** Issue #595: explicit custom comparison bounds (comparison = custom). */
  compare_start_date?: string;
  compare_end_date?: string;
  movement_type?: string;
  method?: 'direct' | 'indirect';
  interval?: 'day' | 'week' | 'month' | 'year';
  page?: number;
  /** Use 0 when the report must return every row in the filtered period. */
  per_page?: number;
}

// Financial Report Types
export interface ProfitLossAccount {
  account_id?: number;
  code?: string;
  account_name?: string;
  name?: string;
  amount: number;
}

export interface ProfitLossSection {
  total: number;
  accounts: ProfitLossAccount[];
}

export interface ProfitLossSummary {
  amount: number;
  margin_percent?: number;
}

export interface ProfitLossData {
  revenue: ProfitLossSection;
  cost_of_goods_sold?: ProfitLossSection;
  operating_expenses?: ProfitLossSection;
  other_income?: ProfitLossSection;
  other_expenses?: ProfitLossSection;
  /** @deprecated use cost_of_goods_sold/operating_expenses/other_expenses sections */
  expenses?: {
    cogs: number;
    operating: number;
    other: number;
    total: number;
    accounts: ProfitLossAccount[];
  };
  gross_profit: number | ProfitLossSummary;
  operating_profit: number | ProfitLossSummary;
  net_profit: number | ProfitLossSummary;
  comparison?: {
    revenue_change: number;
    expense_change: number;
    profit_change: number;
  };
}

export interface BalanceSheetData {
  assets: {
    current: Array<{
      id?: number;
      account_id?: number;
      name: string;
      code: string;
      balance: number;
      account_group?: string;
      group_name?: string;
    }>;
    non_current: Array<{
      id?: number;
      account_id?: number;
      name: string;
      code: string;
      balance: number;
      account_group?: string;
      group_name?: string;
    }>;
    total: number;
  };
  liabilities: {
    current: Array<{
      id?: number;
      account_id?: number;
      name: string;
      code: string;
      balance: number;
      account_group?: string;
      group_name?: string;
    }>;
    non_current: Array<{
      id?: number;
      account_id?: number;
      name: string;
      code: string;
      balance: number;
      account_group?: string;
      group_name?: string;
    }>;
    total: number;
  };
  equity: {
    accounts?: Array<{
      id?: number;
      account_id?: number;
      name: string;
      code: string;
      balance: number;
      account_group?: string;
      group_name?: string;
    }>;
    groups?: Array<{
      code: string;
      name: string;
      total: number;
      accounts: Array<{
        id?: number;
        account_id?: number;
        name: string;
        code: string;
        balance: number;
      }>;
    }>;
    current_year_earnings?: number;
    total: number;
  };
  total_assets: number;
  total_liabilities: number;
  total_equity: number;
  total_liabilities_and_equity?: number;
  working_capital: number;
  balance_check: number;
}

export interface CashFlowData {
  operating_activities: {
    items: Array<{
      description: string;
      amount: number;
    }>;
    total: number;
  };
  investing_activities: {
    items: Array<{
      description: string;
      amount: number;
    }>;
    total: number;
  };
  financing_activities: {
    items: Array<{
      description: string;
      amount: number;
    }>;
    total: number;
  };
  net_cash_flow: number;
  opening_cash: number;
  closing_cash: number;
}

export interface TrialBalanceData {
  accounts: Array<{
    id: number;
    code: string;
    name: string;
    account_type: string;
    debit: number;
    credit: number;
  }>;
  total_debits: number;
  total_credits: number;
  is_balanced: boolean;
}

export interface GeneralLedgerData {
  account: {
    id: number;
    code: string;
    name: string;
    type?: string;
  } | null;
  opening_balance: number;
  transactions: Array<{
    date: string;
    reference: string;
    description: string;
    debit: number;
    credit: number;
    balance: number;
  }>;
  closing_balance: number;
  pagination: {
    current_page: number;
    total_pages: number;
    last_page?: number;
    per_page: number;
    total: number;
  } | null;
  hierarchy?: {
    fiscal_year_start_month: number;
    types: Array<{
      type: string;
      total_debit: number;
      total_credit: number;
      groups: Array<{
        code: string;
        name: string;
        total_debit: number;
        total_credit: number;
        accounts: Array<{
          id: number;
          code: string;
          name: string;
          total_debit: number;
          total_credit: number;
          fiscal_years: Array<{
            year: number;
            total_debit: number;
            total_credit: number;
            months: Array<{
              key: string;
              label: string;
              total_debit: number;
              total_credit: number;
              transactions: Array<{
                journal_entry_id: number;
                date: string;
                reference: string;
                description: string;
                debit: number;
                credit: number;
                balance: number;
              }>;
            }>;
          }>;
        }>;
      }>;
    }>;
  };
}

// Inventory Report Types
export interface StockValuationData {
  items: Array<{
    product_id: number;
    product_name: string;
    sku: string;
    category: string;
    quantity: number;
    cost: number;
    value: number;
    location?: string;
  }>;
  total_value: number;
  by_category?: Record<string, number>;
  by_location?: Record<string, number>;
}

export interface StockMovementData {
  movements: Array<{
    id: number;
    date: string;
    product_id: number;
    product_name: string;
    sku: string;
    type: string;
    quantity: number;
    reference: string;
    reference_type?: string;
    reference_id?: number;
    notes?: string;
    running_balance: number;
    created_by?: string;
  }>;
  summary?: {
    opening_balance: number;
    total_in: number;
    total_out: number;
    net_change: number;
    closing_balance: number;
    movement_count: number;
  };
  pagination?: {
    current_page: number;
    total_pages: number;
    per_page: number;
    total: number;
  };
}

export interface ReorderData {
  items: Array<{
    product_id: number;
    product_name: string;
    sku: string;
    current_stock: number;
    reorder_level: number;
    suggested_quantity: number;
    supplier_name?: string;
    last_purchase_price?: number;
  }>;
}

export interface InventoryAgingData {
  items: Array<{
    product_id: number;
    product_name: string;
    sku: string;
    quantity: number;
    age_days: number;
    age_bucket: string;
    value: number;
    carrying_cost: number;
  }>;
  by_age_bucket: {
    '0-30': { quantity: number; value: number };
    '31-60': { quantity: number; value: number };
    '61-90': { quantity: number; value: number };
    '90+': { quantity: number; value: number };
  };
  total_carrying_cost: number;
}

export interface InventoryTurnoverData {
  items: Array<{
    product_id: number;
    product_name: string;
    category: string;
    turnover_ratio: number;
    days_in_inventory: number;
    classification: 'fast' | 'medium' | 'slow';
  }>;
  average_turnover: number;
  average_days: number;
}

// Analytics Types
export interface SalesByProductData {
  products: Array<{
    id: number | null;
    name: string | null;
    product_name?: string | null;
    sku: string | null;
    total_quantity: number;
    quantity_sold?: number;
    total_revenue: number;
    total_sales?: number;
    total_cost: number;
    total_profit: number;
    profit_margin: number;
  }>;
  /** Issue #595: present only when a comparison period was requested. */
  comparison?: ReportComparison;
}

export interface SalesByCustomerData {
  customers: Array<{
    id: number | null;
    /** Analytics API exposes the joined customer name as `customer_name`. */
    customer_name?: string | null;
    customer_email?: string | null;
    name?: string;
    total_orders: number;
    total_revenue: number;
    average_order_value: number;
    last_purchase_date: string;
  }>;
  comparison?: ReportComparison;
}

export interface SalesTrendsData {
  trends: Array<{
    period: string;
    order_count: number;
    total_sales: number;
    revenue: number;
    average_order_value: number;
    growth_rate: number | null;
  }>;
  comparison?: ReportComparison;
}

/**
 * Issue #601: pivot-style sales analysis. Dimensions and measures are a
 * backend-validated whitelist; `values[i]` aligns with `columns[i]` and
 * `children` carries the expandable row hierarchy (parents aggregate children).
 *
 * Issue #602 adds the Odoo lifecycle measures: ordered / delivered / invoiced
 * / to-invoice, as quantity and amount. `qty_to_invoice`/`amount_to_invoice`
 * are delivered − invoiced and are NOT clamped (a negative value means more
 * was invoiced than delivered).
 */
export type PivotDimension =
  | 'salesperson'
  | 'team'
  | 'customer'
  | 'product'
  | 'category'
  | 'country'
  | 'month';

export type PivotMeasure =
  | 'paid_qty'
  | 'foc_qty'
  | 'net_revenue'
  | 'cost'
  | 'margin'
  | 'qty_ordered'
  | 'qty_delivered'
  | 'qty_invoiced'
  | 'qty_to_invoice'
  | 'amount_ordered'
  | 'amount_delivered'
  | 'amount_invoiced'
  | 'amount_to_invoice';

/** Only the measures requested are present, so every key is optional. */
export type PivotMeasureValues = Partial<Record<PivotMeasure, number>>;

export interface PivotColumn {
  /** Dimension values for this column group ([] for the single Total column). */
  key: string[];
  label: string;
}

export interface PivotRowNode {
  /** Full dimension path, unique per node (used for expand/collapse state). */
  key: string[];
  label: string;
  depth: number;
  values: PivotMeasureValues[];
  totals: PivotMeasureValues;
  children: PivotRowNode[];
}

export interface SalesPivotData {
  title: string;
  row_dims: PivotDimension[];
  col_dims: PivotDimension[];
  measures: PivotMeasure[];
  columns: PivotColumn[];
  rows: PivotRowNode[];
  totals: PivotMeasureValues;
  summary: {
    row_count: number;
    column_count: number;
    base_currency?: string;
    revenue_basis?: string;
    includes_tax?: boolean;
  };
  period?: { start_date: string; end_date: string };
  pos_excluded_by_tag?: boolean;
}

/**
 * Issue #602: one completed delivery-order line whose delivered quantity still
 * exceeds what has been invoiced (the DO-side companion of the pivot's
 * `qty_to_invoice`). `amount_to_invoice` is base-currency and tax excluded.
 */
export interface UnbilledDeliveryRow {
  delivery_order_id: number;
  delivery_order_number: string;
  delivery_order_line_id: number;
  delivery_date: string | null;
  sales_order_id: number | null;
  customer_id: number | null;
  customer_name: string | null;
  product_id: number | null;
  product_name: string | null;
  sku: string | null;
  quantity_ordered: number;
  quantity_delivered: number;
  quantity_invoiced: number;
  quantity_to_invoice: number;
  unit_price: number;
  amount_to_invoice: number;
  currency_code: string | null;
}

export interface UnbilledDeliveriesData {
  title: string;
  deliveries: UnbilledDeliveryRow[];
  summary: {
    line_count: number;
    delivery_order_count: number;
    total_quantity_delivered: number;
    total_quantity_invoiced: number;
    total_quantity_to_invoice: number;
    total_amount_to_invoice: number;
    base_currency?: string;
    revenue_basis?: string;
    includes_tax?: boolean;
  };
  period?: { start_date: string; end_date: string };
  pos_excluded_by_tag?: boolean;
}

export interface SalesByCategoryData {
  categories: Array<{
    category_id: number | null;
    category_name: string;
    product_count: number;
    quantity_sold: number;
    revenue: number;
    total_sales: number;
    cost: number;
    profit: number;
    profit_margin: number;
    revenue_percentage: number;
  }>;
  comparison?: ReportComparison;
}

/**
 * Issue #604 (RPT-D1): one document explaining a report-vs-GL difference,
 * linkable from the reconciliation footer.
 */
export interface SalesReconciliationDocument {
  type: 'invoice' | 'journal_entry' | string;
  id: number;
  label: string;
  url: string;
}

/** Issue #604: one named cause of the difference, with its documents. */
export interface SalesReconciliationCause {
  code: 'unposted_invoice' | 'manual_journal' | 'other_difference' | string;
  label: string;
  direction: 'report_only' | 'gl_only' | string;
  amount: number;
  document_count: number;
  documents: SalesReconciliationDocument[];
}

/**
 * Issue #604: the sales reports' ties-to-GL footer payload — the report's net
 * revenue, the posted GL revenue for the same filters, and (when they differ)
 * the difference broken down by cause.
 */
export interface SalesReconciliationData {
  period: { start_date: string; end_date: string };
  base_currency: string;
  report_net_revenue: number;
  gl_revenue: number;
  difference: number;
  reconciled: boolean;
  report_breakdown: { invoice: number; pos: number; credit_note: number; booking: number };
  causes: SalesReconciliationCause[];
  notes: string[];
}

export interface ExpensesByCategoryData {
  categories: Array<{
    account_id: number;
    account_name: string;
    account_type: string;
    total_amount: number;
    percentage: number;
  }>;
}

export interface ExpensesByVendorData {
  vendors: Array<{
    vendor_id: number;
    vendor_name: string;
    total_spending: number;
    invoice_count: number;
    average_invoice: number;
  }>;
}

export interface ExpensesByDepartmentData {
  departments: Array<{
    department_id: number;
    department_name: string;
    total_expenses: number;
    budget?: number;
    variance?: number;
  }>;
}

export interface CustomerLifetimeValueData {
  customers: Array<{
    customer_id: number;
    customer_name: string;
    lifetime_value: number;
    total_orders: number;
    average_order_value: number;
    purchase_frequency: number;
    predicted_value: number;
  }>;
}

export interface CustomerPurchasePatternsData {
  patterns: Array<{
    customer_id: number;
    customer_name: string;
    purchase_frequency: number;
    seasonal_pattern?: string;
    preferred_products: string[];
    retention_rate: number;
  }>;
}

export interface CustomerSegmentationData {
  segments: Array<{
    segment: string;
    customer_count: number;
    total_revenue: number;
    average_clv: number;
    recency_score: number;
    frequency_score: number;
    monetary_score: number;
  }>;
}

// Dashboard Types
export interface ExecutiveDashboardData {
  /**
   * Reconciliation basis for the selected period:
   *  - booked_*  → sales orders + POS line items (what the charts show)
   *  - posted_*  → the general ledger (P&L) for profitability and cash
   */
  basis?: {
    booked_revenue: number;
    booked_profit: number;
    booked_orders: number;
    posted_revenue: number;
  };
  kpis: {
    revenue: {
      current: number;
      previous: number;
      change_percentage: number;
    };
    profit: {
      current: number;
      previous: number;
      change_percentage: number;
    };
    cash: {
      current: number;
      previous: number;
      change_percentage: number;
    };
  };
  sales_trends: Array<{
    period: string;
    revenue: number;
  }>;
  revenue_by_category: Array<{
    category: string;
    amount: number;
  }>;
  expense_breakdown: Array<{
    category: string;
    amount: number;
  }>;
  top_products: Array<{
    name: string;
    sku?: string;
    quantity?: number;
    revenue: number;
  }>;
}

export interface SalesDashboardData {
  metrics: {
    today: number;
    week: number;
    month: number;
    year: number;
  };
  sales_by_product: Array<{
    product: string;
    amount: number;
  }>;
  sales_by_customer: Array<{
    customer: string;
    amount: number;
  }>;
  sales_trends: Array<{
    period: string;
    amount: number;
  }>;
  top_selling_products: Array<{
    name: string;
    quantity: number;
  }>;
  sales_by_category: Array<{
    category: string;
    amount: number;
  }>;
}

export interface InventoryDashboardData {
  metrics: {
    total_value: number;
    low_stock_count: number;
    out_of_stock_count: number;
  };
  low_stock_alerts: Array<{
    product: string;
    current_stock: number;
    reorder_level: number;
  }>;
  stock_movements: Array<{
    period: string;
    in: number;
    out: number;
  }>;
  inventory_turnover: number;
  top_moving_products: Array<{
    product: string;
    quantity: number;
  }>;
  reorder_recommendations: Array<{
    product: string;
    suggested_quantity: number;
  }>;
}

export interface FinancialDashboardData {
  profit_loss_summary: {
    revenue: number;
    expenses: number;
    net_profit: number;
  };
  balance_sheet_summary: {
    total_assets: number;
    total_liabilities: number;
    total_equity: number;
  };
  cash_flow: Array<{
    period: string;
    amount: number;
  }>;
  expense_breakdown: Array<{
    category: string;
    amount: number;
  }>;
  revenue_vs_expenses: Array<{
    period: string;
    revenue: number;
    expenses: number;
  }>;
  budget_vs_actual: Array<{
    category: string;
    budget: number;
    actual: number;
  }>;
}

// Bank Reconciliation Types
export interface ReconciliationTransaction {
  id: number;
  date: string;
  description: string;
  reference: string | null;
  debit: number;
  credit: number;
  statement_matched_amount?: number;
  available_amount?: number;
  payment?: {
    id: number;
    payment_number: string;
    reference: string | null;
    invoice_id: number | null;
    invoice_number: string | null;
    customer_id: number | null;
    customer_code: string | null;
    customer_name: string | null;
  } | null;
  reconciled: boolean;
  reconciliation_date: string | null;
}

export interface ReconciliationTransactionsData {
  data: ReconciliationTransaction[];
  opening_balance: number;
  bank_account_id: number;
  gl_account_id: number;
}

export interface BankAccountsData {
  data: Array<{
    id: number;
    name: string;
    account_number: string;
    bank_name: string;
    currency_code: string;
    gl_account_id: number;
    gl_account_name: string | null;
    last_reconciliation_date: string | null;
    last_reconciliation_balance: number | null;
    current_balance?: number;
  }>;
}

export interface ReconciliationCompleteRequest {
  bank_account_id: number;
  statement_date: string;
  statement_balance: number;
  reconciled_transaction_ids: number[];
}

export interface AddTransactionRequest {
  bank_account_id: number;
  date: string;
  description: string;
  amount: number;
  type: 'debit' | 'credit';
  reference?: string;
  contra_account_id?: number;
}

// Export Types
export interface ExportRequest {
  report_type: string;
  report_data: any;
  filters: ReportFilters;
}

export interface ExportResponse {
  success: boolean;
  file_path?: string;
  download_url?: string;
  filename?: string;
  message?: string;
}

// API Response wrapper
export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
  errors?: Record<string, string[]>;
}
