import api from './api';

export interface Journal {
  id: number;
  code: string;
  name: string;
  type: 'general' | 'sales' | 'purchase' | 'cash' | 'bank';
  default_debit_account_id?: number;
  default_credit_account_id?: number;
  sequence_prefix: string;
  sequence_number: number;
  is_active: boolean;
  defaultDebitAccount?: {
    id: number;
    code: string;
    name: string;
  };
  defaultCreditAccount?: {
    id: number;
    code: string;
    name: string;
  };
  created_at: string;
  updated_at: string;
}

export interface JournalFilters {
  type?: string;
  is_active?: boolean;
  search?: string;
}

export interface CreateJournalRequest {
  code: string;
  name: string;
  type: 'general' | 'sales' | 'purchase' | 'cash' | 'bank';
  default_debit_account_id?: number;
  default_credit_account_id?: number;
  sequence_prefix: string;
  sequence_number?: number;
  is_active?: boolean;
}

export interface UpdateJournalRequest extends Partial<CreateJournalRequest> {}

class JournalsApi {
  /**
   * Get all journals
   */
  async getJournals(filters?: JournalFilters): Promise<Journal[]> {
    const response = await api.get<{ data: Journal[] }>(
      '/accounting/journals',
      { params: filters }
    );
    return response.data.data;
  }

  /**
   * Get single journal
   */
  async getJournal(id: number): Promise<Journal> {
    const response = await api.get<{ data: Journal }>(
      `/accounting/journals/${id}`
    );
    return response.data.data;
  }

  /**
   * Create new journal
   */
  async createJournal(data: CreateJournalRequest): Promise<Journal> {
    const response = await api.post<{ data: Journal }>(
      '/accounting/journals',
      data
    );
    return response.data.data;
  }

  /**
   * Update journal
   */
  async updateJournal(id: number, data: UpdateJournalRequest): Promise<Journal> {
    const response = await api.put<{ data: Journal }>(
      `/accounting/journals/${id}`,
      data
    );
    return response.data.data;
  }

  /**
   * Delete journal
   */
  async deleteJournal(id: number): Promise<void> {
    await api.delete(`/accounting/journals/${id}`);
  }
}

const journalsApi = new JournalsApi();
export default journalsApi;
