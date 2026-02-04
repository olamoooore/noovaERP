import api from './api';

export interface ChartOfAccount {
  id: number;
  code: string;
  account_group?: string;
  name: string;
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
  subtype?: string;
  parent_id?: number;
  description?: string;
  is_active: boolean;
  is_system: boolean;
  opening_balance?: number;
  balance?: number;
  parent?: ChartOfAccount;
  children?: ChartOfAccount[];
  created_at: string;
  updated_at: string;
}

export interface ChartOfAccountFilters {
  type?: string;
  is_active?: boolean;
  search?: string;
}

export interface CreateAccountRequest {
  code: string;
  account_group?: string;
  name: string;
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
  subtype?: string;
  parent_id?: number;
  description?: string;
  is_active?: boolean;
  opening_balance?: number;
}

export interface UpdateAccountRequest extends Partial<CreateAccountRequest> {}

class ChartOfAccountsApi {
  /**
   * Get all accounts
   */
  async getAccounts(filters?: ChartOfAccountFilters): Promise<ChartOfAccount[]> {
    const response = await api.get<{ data: ChartOfAccount[] }>(
      '/accounting/chart-of-accounts',
      { params: filters }
    );
    return response.data.data;
  }

  /**
   * Get account tree structure
   */
  async getAccountTree(): Promise<ChartOfAccount[]> {
    const response = await api.get<{ data: ChartOfAccount[] }>(
      '/accounting/chart-of-accounts/hierarchy/tree'
    );
    return response.data.data;
  }

  /**
   * Get single account
   */
  async getAccount(id: number): Promise<ChartOfAccount> {
    const response = await api.get<{ data: ChartOfAccount }>(
      `/accounting/chart-of-accounts/${id}`
    );
    return response.data.data;
  }

  /**
   * Create new account
   */
  async createAccount(data: CreateAccountRequest): Promise<ChartOfAccount> {
    const response = await api.post<{ data: ChartOfAccount }>(
      '/accounting/chart-of-accounts',
      data
    );
    return response.data.data;
  }

  /**
   * Update account
   */
  async updateAccount(id: number, data: UpdateAccountRequest): Promise<ChartOfAccount> {
    const response = await api.put<{ data: ChartOfAccount }>(
      `/accounting/chart-of-accounts/${id}`,
      data
    );
    return response.data.data;
  }

  /**
   * Delete account
   */
  async deleteAccount(id: number): Promise<void> {
    await api.delete(`/accounting/chart-of-accounts/${id}`);
  }

  /**
   * Get account balance
   */
  async getAccountBalance(id: number, asOfDate?: string): Promise<number> {
    const response = await api.get<{ data: { balance: number } }>(
      `/accounting/chart-of-accounts/${id}/balance`,
      { params: { as_of_date: asOfDate } }
    );
    return response.data.data.balance;
  }
}

const chartOfAccountsApi = new ChartOfAccountsApi();
export default chartOfAccountsApi;
