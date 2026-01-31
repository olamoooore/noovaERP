import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Calendar,
  Clock,
  Mail,
  Play,
  Pause,
  Trash2,
  Plus,
  Edit,
  RefreshCw,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import api from '@/lib/api';

interface ReportSchedule {
  id: number;
  name: string;
  report_type: string;
  frequency: string;
  parameters: Record<string, any>;
  recipients: string[];
  next_run_at: string | null;
  last_run_at: string | null;
  is_active: boolean;
  created_at: string;
}

interface ScheduleFormData {
  name: string;
  report_type: string;
  frequency: string;
  parameters: Record<string, any>;
  recipients: string[];
  is_active: boolean;
}

/**
 * Report Schedules Page
 * Manage scheduled report generation and email delivery
 */
export const ReportSchedulesPage: React.FC = () => {
  const [showForm, setShowForm] = useState(false);
  const [editingSchedule, setEditingSchedule] = useState<ReportSchedule | null>(null);
  const queryClient = useQueryClient();

  // Fetch schedules
  const { data: schedules, isLoading, error } = useQuery<ReportSchedule[]>({
    queryKey: ['report-schedules'],
    queryFn: async () => {
      const response = await api.get('/api/reports/schedules');
      return response.data.data;
    },
  });

  // Delete schedule mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/api/reports/schedules/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-schedules'] });
    },
  });

  // Toggle active mutation
  const toggleMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/reports/schedules/${id}/toggle`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-schedules'] });
    },
  });

  // Run now mutation
  const runNowMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/reports/schedules/${id}/run`);
    },
  });

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this schedule?')) {
      deleteMutation.mutate(id);
    }
  };

  const handleToggle = (id: number) => {
    toggleMutation.mutate(id);
  };

  const handleRunNow = (id: number) => {
    if (confirm('Run this report now?')) {
      runNowMutation.mutate(id);
    }
  };

  const handleEdit = (schedule: ReportSchedule) => {
    setEditingSchedule(schedule);
    setShowForm(true);
  };

  const formatReportType = (type: string) => {
    return type
      .split('_')
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');
  };

  const formatDate = (date: string | null) => {
    if (!date) return 'Never';
    return new Date(date).toLocaleString();
  };

  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <h3 className="text-red-800 font-medium">Error Loading Schedules</h3>
          <p className="text-red-600 text-sm mt-1">
            {error instanceof Error ? error.message : 'Failed to load report schedules'}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Report Schedules</h1>
          <p className="text-muted-foreground mt-1">
            Manage automated report generation and email delivery
          </p>
        </div>
        <button
          onClick={() => {
            setEditingSchedule(null);
            setShowForm(true);
          }}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          New Schedule
        </button>
      </div>

      {/* Schedules List */}
      {isLoading ? (
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
        </div>
      ) : schedules && schedules.length > 0 ? (
        <div className="grid grid-cols-1 gap-4">
          {schedules.map((schedule) => (
            <Card key={schedule.id}>
              <CardContent className="p-6">
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <div className="flex items-center gap-3 mb-2">
                      <h3 className="text-lg font-semibold">{schedule.name}</h3>
                      <span
                        className={`px-2 py-1 text-xs rounded-full ${
                          schedule.is_active
                            ? 'bg-green-100 text-green-700'
                            : 'bg-gray-100 text-gray-700'
                        }`}
                      >
                        {schedule.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </div>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                      <div>
                        <p className="text-sm text-gray-500">Report Type</p>
                        <p className="font-medium">{formatReportType(schedule.report_type)}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-500">Frequency</p>
                        <p className="font-medium capitalize">{schedule.frequency}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-500">Next Run</p>
                        <p className="font-medium text-sm">{formatDate(schedule.next_run_at)}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-500">Last Run</p>
                        <p className="font-medium text-sm">{formatDate(schedule.last_run_at)}</p>
                      </div>
                    </div>

                    <div className="mt-4">
                      <p className="text-sm text-gray-500">Recipients</p>
                      <div className="flex flex-wrap gap-2 mt-1">
                        {schedule.recipients.map((email, index) => (
                          <span
                            key={index}
                            className="px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded"
                          >
                            {email}
                          </span>
                        ))}
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 ml-4">
                    <button
                      onClick={() => handleRunNow(schedule.id)}
                      className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                      title="Run Now"
                    >
                      <Play className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleToggle(schedule.id)}
                      className="p-2 text-gray-600 hover:bg-gray-50 rounded-lg transition-colors"
                      title={schedule.is_active ? 'Pause' : 'Activate'}
                    >
                      {schedule.is_active ? (
                        <Pause className="h-4 w-4" />
                      ) : (
                        <Play className="h-4 w-4" />
                      )}
                    </button>
                    <button
                      onClick={() => handleEdit(schedule)}
                      className="p-2 text-gray-600 hover:bg-gray-50 rounded-lg transition-colors"
                      title="Edit"
                    >
                      <Edit className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleDelete(schedule.id)}
                      className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                      title="Delete"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <Card>
          <CardContent className="p-12 text-center">
            <Calendar className="h-12 w-12 text-gray-400 mx-auto mb-4" />
            <h3 className="text-lg font-medium text-gray-900 mb-2">No Schedules Yet</h3>
            <p className="text-gray-500 mb-4">
              Create your first report schedule to automate report generation and delivery.
            </p>
            <button
              onClick={() => setShowForm(true)}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            >
              Create Schedule
            </button>
          </CardContent>
        </Card>
      )}

      {/* Schedule Form Modal */}
      {showForm && (
        <ScheduleFormModal
          schedule={editingSchedule}
          onClose={() => {
            setShowForm(false);
            setEditingSchedule(null);
          }}
          onSuccess={() => {
            setShowForm(false);
            setEditingSchedule(null);
            queryClient.invalidateQueries({ queryKey: ['report-schedules'] });
          }}
        />
      )}
    </div>
  );
};

/**
 * Schedule Form Modal Component
 */
interface ScheduleFormModalProps {
  schedule: ReportSchedule | null;
  onClose: () => void;
  onSuccess: () => void;
}

const ScheduleFormModal: React.FC<ScheduleFormModalProps> = ({
  schedule,
  onClose,
  onSuccess,
}) => {
  const [formData, setFormData] = useState<ScheduleFormData>({
    name: schedule?.name || '',
    report_type: schedule?.report_type || 'profit_loss',
    frequency: schedule?.frequency || 'monthly',
    parameters: schedule?.parameters || {},
    recipients: schedule?.recipients || [''],
    is_active: schedule?.is_active ?? true,
  });

  const saveMutation = useMutation({
    mutationFn: async (data: ScheduleFormData) => {
      if (schedule) {
        await api.put(`/api/reports/schedules/${schedule.id}`, data);
      } else {
        await api.post('/api/reports/schedules', data);
      }
    },
    onSuccess: () => {
      onSuccess();
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    // Filter out empty recipients
    const cleanedData = {
      ...formData,
      recipients: formData.recipients.filter((email) => email.trim() !== ''),
    };
    saveMutation.mutate(cleanedData);
  };

  const addRecipient = () => {
    setFormData({
      ...formData,
      recipients: [...formData.recipients, ''],
    });
  };

  const removeRecipient = (index: number) => {
    setFormData({
      ...formData,
      recipients: formData.recipients.filter((_, i) => i !== index),
    });
  };

  const updateRecipient = (index: number, value: string) => {
    const newRecipients = [...formData.recipients];
    newRecipients[index] = value;
    setFormData({
      ...formData,
      recipients: newRecipients,
    });
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div className="p-6 border-b">
          <h2 className="text-2xl font-bold">
            {schedule ? 'Edit Schedule' : 'Create Schedule'}
          </h2>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Schedule Name</label>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border rounded-lg"
              required
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium mb-1">Report Type</label>
              <select
                value={formData.report_type}
                onChange={(e) => setFormData({ ...formData, report_type: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg"
              >
                <option value="profit_loss">Profit & Loss</option>
                <option value="balance_sheet">Balance Sheet</option>
                <option value="cash_flow">Cash Flow</option>
                <option value="trial_balance">Trial Balance</option>
                <option value="general_ledger">General Ledger</option>
                <option value="stock_valuation">Stock Valuation</option>
                <option value="stock_movement">Stock Movement</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">Frequency</label>
              <select
                value={formData.frequency}
                onChange={(e) => setFormData({ ...formData, frequency: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg"
              >
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Recipients</label>
            {formData.recipients.map((email, index) => (
              <div key={index} className="flex gap-2 mb-2">
                <input
                  type="email"
                  value={email}
                  onChange={(e) => updateRecipient(index, e.target.value)}
                  className="flex-1 px-3 py-2 border rounded-lg"
                  placeholder="email@example.com"
                  required
                />
                {formData.recipients.length > 1 && (
                  <button
                    type="button"
                    onClick={() => removeRecipient(index)}
                    className="px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </div>
            ))}
            <button
              type="button"
              onClick={addRecipient}
              className="text-sm text-blue-600 hover:text-blue-700"
            >
              + Add Recipient
            </button>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="is_active"
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
              className="rounded"
            />
            <label htmlFor="is_active" className="text-sm font-medium">
              Active
            </label>
          </div>

          <div className="flex justify-end gap-2 pt-4 border-t">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saveMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {saveMutation.isPending ? 'Saving...' : schedule ? 'Update' : 'Create'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default ReportSchedulesPage;
