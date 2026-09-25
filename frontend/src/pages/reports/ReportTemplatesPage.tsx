import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  FileText,
  Plus,
  Edit,
  Trash2,
  Copy,
  Share2,
  Lock,
  Search,
  Filter,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import api from '@/lib/api';

interface ReportTemplate {
  id: number;
  name: string;
  description: string | null;
  report_type: string;
  configuration: {
    columns?: string[];
    filters?: Record<string, any>;
    grouping?: string[];
    sorting?: Record<string, string>;
    formatting?: Record<string, any>;
    shared?: boolean;
    shared_by?: number;
    shared_at?: string;
  };
  is_system: boolean;
  created_by: number;
  created_at: string;
  updated_at: string;
}

interface TemplateFormData {
  name: string;
  description: string;
  report_type: string;
  configuration: {
    columns?: string[];
    filters?: Record<string, any>;
    grouping?: string[];
    sorting?: Record<string, string>;
    formatting?: Record<string, any>;
  };
}

/**
 * Report Templates Page
 * Manage custom report templates for reusable configurations
 */
export const ReportTemplatesPage: React.FC = () => {
  const [showForm, setShowForm] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<ReportTemplate | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [filterType, setFilterType] = useState<string>('all');
  const queryClient = useQueryClient();

  // Fetch templates
  const { data: templates, isLoading, error } = useQuery<ReportTemplate[]>({
    queryKey: ['report-templates', filterType],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filterType !== 'all') {
        params.append('report_type', filterType);
      }
      const response = await api.get(`/api/reports/templates?${params.toString()}`);
      return response.data.data;
    },
  });

  // Delete template mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/api/reports/templates/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-templates'] });
    },
  });

  // Duplicate template mutation
  const duplicateMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/reports/templates/${id}/duplicate`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-templates'] });
    },
  });

  // Share template mutation
  const shareMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/reports/templates/${id}/share`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-templates'] });
    },
  });

  // Unshare template mutation
  const unshareMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/reports/templates/${id}/unshare`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-templates'] });
    },
  });

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this template?')) {
      deleteMutation.mutate(id);
    }
  };

  const handleDuplicate = (id: number) => {
    duplicateMutation.mutate(id);
  };

  const handleShare = (template: ReportTemplate) => {
    if (template.configuration.shared) {
      unshareMutation.mutate(template.id);
    } else {
      shareMutation.mutate(template.id);
    }
  };

  const handleEdit = (template: ReportTemplate) => {
    setEditingTemplate(template);
    setShowForm(true);
  };

  const formatReportType = (type: string) => {
    return type
      .split('_')
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');
  };

  const filteredTemplates = templates?.filter((template) =>
    template.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    template.description?.toLowerCase().includes(searchQuery.toLowerCase())
  );

  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <h3 className="text-red-800 font-medium">Error Loading Templates</h3>
          <p className="text-red-600 text-sm mt-1">
            {error instanceof Error ? error.message : 'Failed to load report templates'}
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
          <h1 className="text-3xl font-bold">Report Templates</h1>
          <p className="text-muted-foreground mt-1">
            Create and manage reusable report configurations
          </p>
        </div>
        <button
          onClick={() => {
            setEditingTemplate(null);
            setShowForm(true);
          }}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          New Template
        </button>
      </div>

      {/* Search and Filter */}
      <div className="flex gap-4">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            placeholder="Search templates..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
        <select
          value={filterType}
          onChange={(e) => setFilterType(e.target.value)}
          className="px-4 py-2 border rounded-lg"
        >
          <option value="all">All Types</option>
          <option value="profit_loss">Profit & Loss</option>
          <option value="balance_sheet">Balance Sheet</option>
          <option value="cash_flow">Cash Flow</option>
          <option value="trial_balance">Trial Balance</option>
          <option value="stock_valuation">Stock Valuation</option>
          <option value="sales_by_product">Sales by Product</option>
          <option value="expenses_by_category">Expenses by Category</option>
        </select>
      </div>

      {/* Templates Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
        </div>
      ) : filteredTemplates && filteredTemplates.length > 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {filteredTemplates.map((template) => (
            <Card key={template.id} className="hover:shadow-lg transition-shadow">
              <CardHeader>
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <CardTitle className="text-lg flex items-center gap-2">
                      <FileText className="h-5 w-5 text-blue-600" />
                      {template.name}
                    </CardTitle>
                    <p className="text-sm text-gray-500 mt-1">
                      {formatReportType(template.report_type)}
                    </p>
                  </div>
                  <div className="flex items-center gap-1">
                    {template.is_system && (
                      <span className="px-2 py-1 text-xs bg-purple-100 text-purple-700 rounded">
                        System
                      </span>
                    )}
                    {template.configuration.shared && (
                      <Share2 className="h-4 w-4 text-green-600" title="Shared" />
                    )}
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                {template.description && (
                  <p className="text-sm text-gray-600 mb-4">{template.description}</p>
                )}

                <div className="space-y-2 text-sm">
                  {template.configuration.columns && (
                    <div>
                      <span className="font-medium">Columns:</span>{' '}
                      {template.configuration.columns.length}
                    </div>
                  )}
                  {template.configuration.filters && (
                    <div>
                      <span className="font-medium">Filters:</span>{' '}
                      {Object.keys(template.configuration.filters).length}
                    </div>
                  )}
                </div>

                <div className="flex items-center gap-2 mt-4 pt-4 border-t">
                  {!template.is_system && (
                    <>
                      <button
                        onClick={() => handleEdit(template)}
                        className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        title="Edit"
                      >
                        <Edit className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => handleShare(template)}
                        className="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                        title={template.configuration.shared ? 'Unshare' : 'Share'}
                      >
                        {template.configuration.shared ? (
                          <Lock className="h-4 w-4" />
                        ) : (
                          <Share2 className="h-4 w-4" />
                        )}
                      </button>
                    </>
                  )}
                  <button
                    onClick={() => handleDuplicate(template.id)}
                    className="p-2 text-gray-600 hover:bg-gray-50 rounded-lg transition-colors"
                    title="Duplicate"
                  >
                    <Copy className="h-4 w-4" />
                  </button>
                  {!template.is_system && (
                    <button
                      onClick={() => handleDelete(template.id)}
                      className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors ml-auto"
                      title="Delete"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <Card>
          <CardContent className="p-12 text-center">
            <FileText className="h-12 w-12 text-gray-400 mx-auto mb-4" />
            <h3 className="text-lg font-medium text-gray-900 mb-2">No Templates Found</h3>
            <p className="text-gray-500 mb-4">
              {searchQuery
                ? 'No templates match your search criteria.'
                : 'Create your first report template to get started.'}
            </p>
            {!searchQuery && (
              <button
                onClick={() => setShowForm(true)}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
              >
                Create Template
              </button>
            )}
          </CardContent>
        </Card>
      )}

      {/* Template Form Modal */}
      {showForm && (
        <TemplateFormModal
          template={editingTemplate}
          onClose={() => {
            setShowForm(false);
            setEditingTemplate(null);
          }}
          onSuccess={() => {
            setShowForm(false);
            setEditingTemplate(null);
            queryClient.invalidateQueries({ queryKey: ['report-templates'] });
          }}
        />
      )}
    </div>
  );
};

/**
 * Template Form Modal Component
 */
interface TemplateFormModalProps {
  template: ReportTemplate | null;
  onClose: () => void;
  onSuccess: () => void;
}

const TemplateFormModal: React.FC<TemplateFormModalProps> = ({
  template,
  onClose,
  onSuccess,
}) => {
  const [formData, setFormData] = useState<TemplateFormData>({
    name: template?.name || '',
    description: template?.description || '',
    report_type: template?.report_type || 'profit_loss',
    configuration: template?.configuration || {
      columns: [],
      filters: {},
      grouping: [],
      sorting: {},
      formatting: {},
    },
  });

  const saveMutation = useMutation({
    mutationFn: async (data: TemplateFormData) => {
      if (template) {
        await api.put(`/api/reports/templates/${template.id}`, data);
      } else {
        await api.post('/api/reports/templates', data);
      }
    },
    onSuccess: () => {
      onSuccess();
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    saveMutation.mutate(formData);
  };

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{template ? 'Edit Template' : 'Create Template'}</DialogTitle>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label className="mb-1 block">Template Name</Label>
            <Input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              required
            />
          </div>

          <div>
            <Label className="mb-1 block">Description</Label>
            <Textarea
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              rows={3}
            />
          </div>

          <div>
            <Label className="mb-1 block">Report Type</Label>
            <select
              value={formData.report_type}
              onChange={(e) => setFormData({ ...formData, report_type: e.target.value })}
              className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="profit_loss">Profit & Loss</option>
              <option value="balance_sheet">Balance Sheet</option>
              <option value="cash_flow">Cash Flow</option>
              <option value="trial_balance">Trial Balance</option>
              <option value="general_ledger">General Ledger</option>
              <option value="stock_valuation">Stock Valuation</option>
              <option value="stock_movement">Stock Movement</option>
              <option value="sales_by_product">Sales by Product</option>
              <option value="sales_by_customer">Sales by Customer</option>
              <option value="expenses_by_category">Expenses by Category</option>
            </select>
          </div>

          <div className="rounded-lg bg-muted/50 p-4">
            <h3 className="font-medium mb-2">Configuration</h3>
            <p className="text-sm text-muted-foreground">
              Template configuration will be customizable in future updates. For now, templates
              will use default configurations for the selected report type.
            </p>
          </div>

          <DialogFooter className="gap-2 border-t pt-4">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? 'Saving...' : template ? 'Update' : 'Create'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export default ReportTemplatesPage;
