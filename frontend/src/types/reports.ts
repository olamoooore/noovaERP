// Report Types and Interfaces

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
    id: number;
    name: string;
    sku: string;
    total_quantity: number;
    total_revenue: number;
    total_cost: number;
    total_profit: number;
    profit_margin: number;
  }>;
}

export interface SalesByCustomerData {
  customers: Array<{
    id: number;
    name: string;
    total_orders: number;
    total_revenue: number;
    average_order_value: number;
    last_purchase_date: string;
  }>;
}

export interface SalesTrendsData {
  trends: Array<{
    period: string;
    invoice_count: number;
    total_revenue: number;
    avg_order_value: number;
  }>;
}

export interface SalesByCategoryData {
  categories: Array<{
    category_id: number;
    category_name: string;
    total_revenue: number;
    percentage: number;
  }>;
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
