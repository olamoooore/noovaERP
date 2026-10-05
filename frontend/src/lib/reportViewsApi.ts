import api from './api';
import type {
  ReportFilters,
  ReportSavedView,
  ReportViewSchedule,
  SalesViewGrouping,
  SalesViewTab,
} from '@/types/reports';

/**
 * Issue #598 (RPT-UX3) — saved report views and their schedules.
 * All routes live under the reports.view permission gate; owner-only
 * operations are enforced server-side (403).
 */
export interface SavedViewsPayload {
  views: ReportSavedView[];
  default_view_id: number | null;
}

export interface RecipientUser {
  id: number;
  name: string;
  email: string;
}

export interface SavedViewInput {
  name: string;
  report_key?: string;
  tab: SalesViewTab;
  filters: ReportFilters;
  grouping?: SalesViewGrouping | null;
  visibility?: 'personal' | 'shared';
  is_default?: boolean;
}

export interface SavedViewScheduleInput {
  frequency: 'daily' | 'weekly' | 'monthly';
  day_of_week?: number;
  day_of_month?: number;
  time_of_day?: string;
  recipients: number[];
  format: 'csv' | 'pdf';
  is_active?: boolean;
}

const reportViewsApi = {
  async list(): Promise<SavedViewsPayload> {
    const response = await api.get('/reports/saved-views');
    return response.data.data;
  },

  async create(input: SavedViewInput): Promise<ReportSavedView> {
    const response = await api.post('/reports/saved-views', input);
    return response.data.data;
  },

  async update(id: number, input: Partial<SavedViewInput>): Promise<ReportSavedView> {
    const response = await api.put(`/reports/saved-views/${id}`, input);
    return response.data.data;
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/reports/saved-views/${id}`);
  },

  async setDefault(id: number): Promise<ReportSavedView> {
    const response = await api.post(`/reports/saved-views/${id}/default`);
    return response.data.data;
  },

  async recipients(): Promise<RecipientUser[]> {
    const response = await api.get('/reports/saved-views/recipients');
    return response.data.data;
  },

  async schedules(viewId: number): Promise<ReportViewSchedule[]> {
    const response = await api.get(`/reports/saved-views/${viewId}/schedules`);
    return response.data.data;
  },

  async createSchedule(viewId: number, input: SavedViewScheduleInput): Promise<ReportViewSchedule> {
    const response = await api.post(`/reports/saved-views/${viewId}/schedules`, input);
    return response.data.data;
  },

  async deleteSchedule(viewId: number, scheduleId: number): Promise<void> {
    await api.delete(`/reports/saved-views/${viewId}/schedules/${scheduleId}`);
  },
};

export default reportViewsApi;
