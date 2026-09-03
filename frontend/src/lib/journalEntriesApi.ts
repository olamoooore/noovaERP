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
  remark?: string;
  status: 'draft' | 'posted';
  posted_at?: string;
  posted_by?: number;
  created_by?: number;
  reversed_entry_id?: number;
  is_reversal: boolean;
  is_balanced: boolean;
  myinvois_type?: string | null;
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
  is_reversal?: boolean;
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
  remark?: string;
  lines: Omit<JournalEntryLine, 'id' | 'account'>[];
}

export interface UpdateJournalEntryRequest extends CreateJournalEntryRequest {}

export interface PostJournalEntryRequest {
  override_reason?: string;
}

export interface ReverseJournalEntryRequest {
  reversal_date: string;
  reason: string;
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
    // Client-side basic validation to catch simple issues early
    if (!Array.isArray(data.lines) || data.lines.length < 2) {
      throw new Error('Journal entry must contain at least two lines.');
    }
    for (let i = 0; i < data.lines.length; i++) {
      const line = data.lines[i] as any;
      if (typeof line.account_id !== 'number' ||
          (typeof line.debit !== 'number' && typeof line.credit !== 'number')) {
        throw new Error(`Line ${i + 1} must include an account_id and either debit or credit.`);
      }
    }
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
   * Reverse journal entry (full reversal)
   */
  async reverseJournalEntry(id: number, data?: ReverseJournalEntryRequest): Promise<{
    message: string;
    reversal_entry: JournalEntry;
  }> {
    const response = await api.post(`/accounting/journal-entries/${id}/reverse`, data);
    return response.data;
  }

  /**
   * Partial reversal of specific lines
   */
  async partialReverseJournalEntry(id: number, data: {
    reversal_date: string;
    reason: string;
    line_amounts: { line_id: number; amount: number }[];
  }): Promise<{
    message: string;
    reversal_entry: JournalEntry;
    reversed_amount: number;
    remaining_amount: number;
  }> {
    const response = await api.post(`/accounting/journal-entries/${id}/partial-reverse`, data);
    return response.data;
  }

  /**
   * Set or clear the MyInvois type on a journal entry (any status).
   */
  async updateMyInvoisType(id: number, myinvoisType: string | null): Promise<{
    message: string;
    entry: JournalEntry;
  }> {
    const response = await api.patch(`/accounting/journal-entries/${id}/myinvois-type`, {
      myinvois_type: myinvoisType,
    });
    return response.data;
  }

  /**
   * Submit journal entry to MyInvois as self-billed invoice.
   */
  async submitToMyInvois(id: number, countryCode = 'MY'): Promise<{
    success: boolean;
    message: string;
  }> {
    const response = await api.post(`/accounting/compliance/journal-entries/${id}/submit`, {
      country_code: countryCode,
    });
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
