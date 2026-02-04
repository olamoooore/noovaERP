import api from './api';

export interface JournalEntryLine {
  id?: number;
  account_id: number;
  debit: number;
  credit: number;
  description?: string;
  reference?: string;
  currency_code?: string;
  original_amount?: number;
  exchange_rate?: number;
  department_id?: number;
  project_id?: number;
  location_id?: number;
  account?: {
    id: number;
    code: string;
    name: string;
    type: string;
  };
}

export interface JournalEntry {
  id: number;
  journal_id?: number;
  entry_number: string;
  entry_date: string;
  type: string;
  reference?: string;
  description: string;
  status: 'draft' | 'posted';
  posted_at?: string;
  posted_by?: number;
  created_by?: number;
  reversed_entry_id?: number;
  is_reversal: boolean;
  is_balanced: boolean;
  lines: JournalEntryLine[];
  journal?: {
    id: number;
    code: string;
    name: string;
  };
  createdBy?: {
    id: number;
    name: string;
  };
  postedBy?: {
    id: number;
    name: string;
  };
  created_at: string;
  updated_at: string;
}

export interface JournalEntryFilters {
  status?: 'draft' | 'posted';
  journal_id?: number;
  start_date?: string;
  end_date?: string;
  search?: string;
  per_page?: number;
  page?: number;
}

export interface CreateJournalEntryRequest {
  journal_id?: number;
  entry_date: string;
  reference?: string;
  description: string;
  lines: Omit<JournalEntryLine, 'id' | 'account'>[];
}

export interface UpdateJournalEntryRequest extends CreateJournalEntryRequest {}

export interface PostJournalEntryRequest {
  override_reason?: string;
}

export interface ReverseJournalEntryRequest {
  reason?: string;
}

class JournalEntriesApi {
  /**
   * Get all journal entries
   */
  async getJournalEntries(filters?: JournalEntryFilters): Promise<{
    data: JournalEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  }> {
    const response = await api.get('/accounting/journal-entries', { params: filters });
    return response.data;
  }

  /**
   * Get single journal entry
   */
  async getJournalEntry(id: number): Promise<JournalEntry> {
    const response = await api.get(`/accounting/journal-entries/${id}`);
    return response.data;
  }

  /**
   * Create new journal entry
   */
  async createJournalEntry(data: CreateJournalEntryRequest): Promise<{
    message: string;
    entry: JournalEntry;
  }> {
    const response = await api.post('/accounting/journal-entries', data);
    return response.data;
  }

  /**
   * Update journal entry (draft only)
   */
  async updateJournalEntry(id: number, data: UpdateJournalEntryRequest): Promise<{
    message: string;
    entry: JournalEntry;
  }> {
    const response = await api.put(`/accounting/journal-entries/${id}`, data);
    return response.data;
  }

  /**
   * Delete journal entry (draft only)
   */
  async deleteJournalEntry(id: number): Promise<void> {
    await api.delete(`/accounting/journal-entries/${id}`);
  }

  /**
   * Post journal entry
   */
  async postJournalEntry(id: number, data?: PostJournalEntryRequest): Promise<{
    message: string;
    entry: JournalEntry;
  }> {
    const response = await api.post(`/accounting/journal-entries/${id}/post`, data);
    return response.data;
  }

  /**
   * Reverse journal entry
   */
  async reverseJournalEntry(id: number, data?: ReverseJournalEntryRequest): Promise<{
    message: string;
    reversal_entry: JournalEntry;
  }> {
    const response = await api.post(`/accounting/journal-entries/${id}/reverse`, data);
    return response.data;
  }

  /**
   * Validate entry date against lock dates
   */
  async validateDate(entryDate: string): Promise<{
    is_locked: boolean;
    message?: string;
    lock_date?: string;
  }> {
    const response = await api.post('/accounting/journal-entries/validate-date', {
      entry_date: entryDate,
    });
    return response.data;
  }
}

const journalEntriesApi = new JournalEntriesApi();
export default journalEntriesApi;
