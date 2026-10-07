import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/hooks/use-toast';
import fixedAssetsApi, { type FixedAsset, type CreateAssetRequest, type DisposeAssetRequest } from '@/lib/fixedAssetsApi';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { SalesDocumentExportMenu, type SalesExportColumn } from '@/components/sales/SalesDocumentExportMenu';
import { formatCurrency } from '@/lib/utils';

const ASSET_EXPORT_COLUMNS = [
  { key: 'asset_number', label: 'Asset No', group: 'Asset', default: true }, { key: 'name', label: 'Asset Name', group: 'Asset', default: true },
  { key: 'category', label: 'Category', group: 'Asset', default: true }, { key: 'purchase_date', label: 'Purchase Date', group: 'Asset', default: true },
  { key: 'purchase_cost', label: 'Purchase Cost', group: 'Values', default: true }, { key: 'salvage_value', label: 'Salvage Value', group: 'Values' },
  { key: 'accumulated_depreciation', label: 'Accumulated Depreciation', group: 'Values', default: true }, { key: 'book_value', label: 'Book Value', group: 'Values', default: true },
  { key: 'depreciation_method', label: 'Method', group: 'Depreciation' }, { key: 'depreciation_frequency', label: 'Frequency', group: 'Depreciation' },
  { key: 'useful_life_years', label: 'Useful Life', group: 'Depreciation' }, { key: 'depreciation_start_date', label: 'Start Date', group: 'Depreciation' },
  { key: 'status', label: 'Status', group: 'Asset', default: true }, { key: 'location', label: 'Location', group: 'Asset' }, { key: 'serial_number', label: 'Serial Number', group: 'Asset' },
  { key: 'asset_account_code', label: 'Asset Account', group: 'Accounts' }, { key: 'depreciation_account_code', label: 'Depreciation Account', group: 'Accounts' },
] as const satisfies readonly SalesExportColumn[];

export default function AssetsManagementPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('assets');
  const [formOpen, setFormOpen] = useState(false);
  const [editingAsset, setEditingAsset] = useState<FixedAsset | null>(null);
  const [deactivateTarget, setDeactivateTarget] = useState<FixedAsset | null>(null);
  const [disposeTarget, setDisposeTarget] = useState<FixedAsset | null>(null);
  const [disposeForm, setDisposeForm] = useState<Omit<DisposeAssetRequest, 'disposal_amount'> & { disposal_amount: number | '' }>({
    disposal_date: new Date().toLocaleDateString('en-CA'),
    disposal_amount: '',
    disposal_method: 'sold',
    notes: '',
  });
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [selectedAssetId, setSelectedAssetId] = useState<number | null>(null);
  const [depreciationPeriod, setDepreciationPeriod] = useState(new Date().toISOString().slice(0, 10));
  const [formData, setFormData] = useState<Omit<CreateAssetRequest, 'purchase_cost' | 'salvage_value'> & { purchase_cost: number | ''; salvage_value: number | '' }>({
    asset_number: '',
    name: '',
    category: '',
    purchase_date: '',
    purchase_cost: '',
    depreciation_method: 'straight_line',
    useful_life_years: 5,
    salvage_value: '',
    depreciation_start_date: '',
    asset_account_id: 0,
    depreciation_account_id: 0,
    accumulated_depreciation_account_id: 0,
  });

  // Fetch assets
  const { data: assets = [], isLoading } = useQuery<FixedAsset[]>({
    queryKey: ['fixed-assets', { search: searchTerm, status: statusFilter }],
    queryFn: () => fixedAssetsApi.getAssets({ 
      search: searchTerm, 
      status: statusFilter === 'all' ? undefined : statusFilter 
    }),
  });

  // Fetch accounts for dropdowns
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  // Fetch depreciation schedule for selected asset
  const { data: depreciationData, isLoading: depreciationLoading } = useQuery({
    queryKey: ['depreciation-schedule', selectedAssetId],
    queryFn: () => fixedAssetsApi.getDepreciationSchedule(selectedAssetId!),
    enabled: !!selectedAssetId,
  });

  const depreciationSchedule = (() => {
    if (!depreciationData) return [];
    const candidates = [
      depreciationData.schedule,
      depreciationData.data?.schedule,
      Array.isArray(depreciationData.data) ? depreciationData.data : undefined,
      depreciationData.schedules,
    ];
    for (const candidate of candidates) {
      if (Array.isArray(candidate)) return candidate;
    }
    return [];
  })();

  // Debug logging for depreciation schedule data shape
  if (depreciationData) {
    console.log('Depreciation data shape:', {
      keys: Object.keys(depreciationData),
      scheduleType: typeof depreciationData.schedule,
      scheduleLength: Array.isArray(depreciationData.schedule) ? depreciationData.schedule.length : 'N/A',
      dataType: typeof depreciationData.data,
      dataIsArray: Array.isArray(depreciationData.data),
    });
  }

  // Calculate depreciation mutation
  const calculateDepreciationMutation = useMutation({
    mutationFn: (data: { period_date: string; asset_ids?: number[] }) =>
      fixedAssetsApi.calculateDepreciation(data),
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['depreciation-schedule'] });
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      // Re-fetch the schedule for the currently selected asset so the table updates
      if (selectedAssetId) {
        queryClient.refetchQueries({ queryKey: ['depreciation-schedule', selectedAssetId] });
      }

      // The backend returns per-asset results. Surface the real outcome
      // instead of a blanket success toast: an asset can produce schedule
      // rows whose journal entries could not be posted (fiscal period
      // closed), and a run can fail entirely (e.g. no active assets).
      const results: any[] = Array.isArray(data?.results) ? data.results : [];
      const errorResults = results.filter((r) => r.status === 'error');
      const pendingCount = results.reduce((sum, r) => sum + (Number(r.pending_posting) || 0), 0);
      const scheduleCount = results.reduce(
        (sum, r) => sum + (Number(r.schedule_count ?? r.draft_count) || 0),
        0,
      );

      if (data?.success === false && errorResults.length > 0) {
        toast({
          title: 'Depreciation failed',
          description: errorResults[0]?.message || data?.message || 'Failed to calculate depreciation',
          variant: 'destructive',
        });
        return;
      }

      if (pendingCount > 0) {
        toast({
          title: 'Schedule created — posting pending',
          description:
            `${scheduleCount} schedule entr${scheduleCount === 1 ? 'y' : 'ies'} created. ` +
            `${pendingCount} period(s) could not be posted because their fiscal period is closed — the schedule is saved and can be posted once the period is open.`,
        });
        return;
      }

      toast({
        title: 'Success',
        description: data?.message || `Depreciation calculated${scheduleCount > 0 ? ` — ${scheduleCount} schedule entries` : ''}.`,
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || error.response?.data?.message || 'Failed to calculate depreciation',
        variant: 'destructive',
      });
    },
  });

  // Create asset mutation
  const createMutation = useMutation({
    mutationFn: fixedAssetsApi.createAsset,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      toast({ title: 'Success', description: 'Asset created successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      console.error('Create asset error:', error.response?.data);
      const errorMessage = error.response?.data?.error 
        || error.response?.data?.message
        || (error.response?.data?.errors ? Object.values(error.response.data.errors).flat().join(', ') : null)
        || 'Failed to create asset';
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      });
    },
  });

  // Update asset mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<CreateAssetRequest> }) =>
      fixedAssetsApi.updateAsset(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      toast({ title: 'Success', description: 'Asset updated successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      console.error('Update asset error:', error.response?.data);
      const errorMessage = error.response?.data?.error 
        || error.response?.data?.message
        || (error.response?.data?.errors ? Object.values(error.response.data.errors).flat().join(', ') : null)
        || 'Failed to update asset';
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      });
    },
  });

  // Delete asset mutation
  const deleteMutation = useMutation({
    mutationFn: fixedAssetsApi.deleteAsset,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      toast({ title: 'Success', description: 'Asset deactivated successfully' });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to deactivate asset',
        variant: 'destructive',
      });
    },
  });

  const disposeMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: DisposeAssetRequest }) =>
      fixedAssetsApi.disposeAsset(id, data),
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      queryClient.invalidateQueries({ queryKey: ['depreciation-schedule'] });
      const gainLoss = Number(data?.gain_loss || 0);
      const outcome = gainLoss > 0
        ? `Gain: $${gainLoss.toLocaleString()}`
        : gainLoss < 0
          ? `Loss: $${Math.abs(gainLoss).toLocaleString()}`
          : 'No gain or loss';
      toast({ title: 'Asset disposed', description: `The disposal journal entry was posted. ${outcome}.` });
      setDisposeTarget(null);
    },
    onError: (error: any) => {
      const payload = error.response?.data;
      toast({
        title: 'Disposal failed',
        description: payload?.error
          || payload?.message
          || (payload?.errors ? Object.values(payload.errors).flat().join(', ') : null)
          || 'Failed to dispose asset',
        variant: 'destructive',
      });
    },
  });

  const resetForm = () => {
    setFormData({
      asset_number: '',
      name: '',
      category: '',
      purchase_date: '',
      purchase_cost: '',
      depreciation_method: 'straight_line',
      useful_life_years: 5,
      salvage_value: '',
      depreciation_start_date: '',
      asset_account_id: 0,
      depreciation_account_id: 0,
      accumulated_depreciation_account_id: 0,
    });
    setEditingAsset(null);
  };

  const handleOpenForm = (asset?: FixedAsset) => {
    if (asset) {
      setEditingAsset(asset);
      setFormData({
        asset_number: asset.asset_number,
        name: asset.name,
        category: asset.category,
        purchase_date: asset.purchase_date ? asset.purchase_date.split('T')[0] : '',
        purchase_cost: asset.purchase_cost || '',
      depreciation_method: asset.depreciation_method,
      useful_life_years: asset.useful_life_years,
      useful_life_units: asset.useful_life_units,
      salvage_value: asset.salvage_value || '',
        depreciation_start_date: asset.depreciation_start_date ? asset.depreciation_start_date.split('T')[0] : '',
        asset_account_id: asset.asset_account_id,
        depreciation_account_id: asset.depreciation_account_id,
        accumulated_depreciation_account_id: asset.accumulated_depreciation_account_id,
        location: asset.location,
        responsible_person: asset.responsible_person,
        serial_number: asset.serial_number,
        description: asset.description,
      });
    } else {
      resetForm();
    }
    setFormOpen(true);
  };

  const handleSubmit = () => {
    if (!formData.asset_number || !formData.name || !formData.category) {
      toast({
        title: 'Validation Error',
        description: 'Please fill in all required fields',
        variant: 'destructive',
      });
      return;
    }

    if (!formData.asset_account_id || !formData.depreciation_account_id || !formData.accumulated_depreciation_account_id) {
      toast({
        title: 'Validation Error',
        description: 'Please select all required account assignments',
        variant: 'destructive',
      });
      return;
    }

    const payload: CreateAssetRequest = {
      ...formData,
      purchase_cost: formData.purchase_cost === '' ? 0 : formData.purchase_cost,
      salvage_value: formData.salvage_value === '' ? 0 : formData.salvage_value,
    };

    if (editingAsset) {
      updateMutation.mutate({ id: editingAsset.id, data: payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const handleDelete = (asset: FixedAsset) => {
    setDeactivateTarget(asset);
  };

  const handleOpenDispose = (asset: FixedAsset) => {
    setDisposeTarget(asset);
    setDisposeForm({
      disposal_date: new Date().toLocaleDateString('en-CA'),
      disposal_amount: '',
      disposal_method: 'sold',
      notes: '',
    });
  };

  const estimatedGainLoss = disposeTarget
    ? Number(disposeForm.disposal_amount || 0) - Number(disposeTarget.book_value || 0)
    : 0;

  const getStatusBadge = (status: string) => {
    const colors: Record<string, string> = {
      active: 'bg-green-100 text-green-800',
      disposed: 'bg-red-100 text-red-800',
      inactive: 'bg-gray-100 text-gray-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Fixed Assets Management</h1>
          <p className="text-muted-foreground mt-1">
            Track and depreciate fixed assets
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="assets">Assets</TabsTrigger>
          <TabsTrigger value="depreciation">Depreciation Schedule</TabsTrigger>
        </TabsList>

        <TabsContent value="assets" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Fixed Assets</h2>
              <div className="flex gap-2">
                <SalesDocumentExportMenu endpoint="/accounting/assets/export" filenamePrefix="fixed_assets" columns={ASSET_EXPORT_COLUMNS} filters={{ search: searchTerm || undefined, status: statusFilter === 'all' ? undefined : statusFilter }} />
                <HeaderActionButton icon="solar:upload-bold-duotone" variant="outline" onClick={() => navigate('/accounting/core/assets/import')}>
                  Import CSV
                </HeaderActionButton>
                <Input
                  placeholder="Search assets..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="h-8 w-64 rounded-full px-3 text-xs"
                />
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="h-8 w-40 rounded-full text-xs">
                    <SelectValue placeholder="All Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="disposed">Disposed</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
                <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => handleOpenForm()}>
                  Add Asset
                </HeaderActionButton>
              </div>
            </div>

            {isLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading assets...</p>
              </div>
            ) : assets.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:buildings-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No assets found</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Asset Number</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Category</TableHead>
                    <TableHead>Purchase Cost</TableHead>
                    <TableHead>Book Value</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assets.map((asset) => (
                    <TableRow key={asset.id}>
                      <TableCell className="font-mono">{asset.asset_number}</TableCell>
                      <TableCell className="font-medium">{asset.name}</TableCell>
                      <TableCell>{asset.category}</TableCell>
                      <TableCell className="font-mono">
                        {formatCurrency(Number(asset.purchase_cost) || 0)}
                      </TableCell>
                      <TableCell className="font-mono">
                        {formatCurrency(Number(asset.book_value) || 0)}
                      </TableCell>
                      <TableCell>
                        <Badge className={getStatusBadge(asset.status)}>
                          {asset.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          {asset.status === 'active' && asset.is_active && (
                            <>
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7"
                                title="Edit"
                                aria-label="Edit asset"
                                onClick={() => handleOpenForm(asset)}
                              >
                                <Icon icon="solar:pen-linear" className="h-4 w-4" />
                              </Button>
                              <Button
                                size="sm"
                                variant="outline"
                                className="h-7 gap-1 px-2 text-xs"
                                onClick={() => handleOpenDispose(asset)}
                              >
                                <Icon icon="solar:hand-money-linear" className="h-4 w-4" />
                                Dispose
                              </Button>
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                title="Deactivate"
                                aria-label="Deactivate asset"
                                onClick={() => handleDelete(asset)}
                              >
                                <Icon icon="solar:trash-bin-trash-linear" className="h-4 w-4" />
                              </Button>
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="depreciation" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Depreciation Schedule</h2>
              <div className="flex items-center gap-2">
                <Input
                  type="date"
                  value={depreciationPeriod}
                  onChange={(e) => setDepreciationPeriod(e.target.value)}
                  className="w-44"
                />
                <Button
                  onClick={() => calculateDepreciationMutation.mutate({
                    period_date: depreciationPeriod,
                    asset_ids: selectedAssetId ? [selectedAssetId] : undefined,
                  })}
                  disabled={calculateDepreciationMutation.isPending}
                >
                  {calculateDepreciationMutation.isPending ? (
                    <><Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />Calculating...</>
                  ) : (
                    <><Icon icon="solar:calculator-linear" className="w-4 h-4 mr-2" />Calculate Depreciation</>
                  )}
                </Button>
              </div>
            </div>

            {/* Asset Selector */}
            <div className="mb-4">
              <Label className="mb-2 block text-sm">Select Asset</Label>
              <Select
                value={selectedAssetId?.toString() || ''}
                onValueChange={(v) => setSelectedAssetId(v ? parseInt(v) : null)}
              >
                <SelectTrigger className="w-80">
                  <SelectValue placeholder="Choose an asset to view schedule" />
                </SelectTrigger>
                <SelectContent>
                  {assets.map((asset) => (
                    <SelectItem key={asset.id} value={asset.id.toString()}>
                      {asset.asset_number} - {asset.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {!selectedAssetId ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:calendar-mark-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>Select an asset above to view its depreciation schedule</p>
                {assets.length === 0 && <p className="text-sm mt-2">No assets created yet. Add an asset from the Assets tab first.</p>}
              </div>
            ) : depreciationLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading depreciation schedule...</p>
              </div>
            ) : Array.isArray(depreciationSchedule) && depreciationSchedule.length > 0 ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">Beginning Value</TableHead>
                    <TableHead className="text-right">Depreciation</TableHead>
                    <TableHead className="text-right">Accumulated</TableHead>
                    <TableHead className="text-right">Book Value</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {depreciationSchedule.map((entry: any, idx: number) => (
                    <TableRow key={entry.id || idx}>
                      <TableCell>{entry.period_date || entry.period || entry.date || '-'}</TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(Number(entry.beginning_value || entry.opening_value || 0))}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(Number(entry.depreciation_amount || entry.amount || 0))}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(Number(entry.accumulated_depreciation || entry.accumulated || 0))}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(Number(entry.ending_book_value || entry.book_value || entry.closing_value || 0))}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            ) : (
              <div className="text-center py-8 text-muted-foreground">
                <p>No depreciation entries yet for this asset.</p>
                <p className="text-sm mt-1">Use "Calculate Depreciation" to generate the schedule.</p>
              </div>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      {/* Asset Form Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingAsset ? 'Edit Asset' : 'Add Asset'}</DialogTitle>
            <DialogDescription>
              {editingAsset ? 'Update asset details' : 'Create a new fixed asset'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="asset_number">Asset Number *</Label>
                <Input
                  id="asset_number"
                  value={formData.asset_number}
                  onChange={(e) => setFormData({ ...formData, asset_number: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="category">Category *</Label>
                <Input
                  id="category"
                  value={formData.category}
                  onChange={(e) => setFormData({ ...formData, category: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">Asset Name *</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="purchase_date">Purchase Date *</Label>
                <Input
                  id="purchase_date"
                  type="date"
                  value={formData.purchase_date}
                  onChange={(e) => setFormData({ ...formData, purchase_date: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="purchase_cost">Purchase Cost *</Label>
                <Input
                  id="purchase_cost"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                  value={formData.purchase_cost === '' || formData.purchase_cost === 0 ? '' : formData.purchase_cost}
                  onChange={(e) => setFormData({ ...formData, purchase_cost: e.target.value === '' ? '' : parseFloat(e.target.value) })}
                />
              </div>
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label htmlFor="depreciation_method">Depreciation Method *</Label>
                <Select
                  value={formData.depreciation_method}
                  onValueChange={(value: any) => setFormData({ ...formData, depreciation_method: value })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="straight_line">Straight Line</SelectItem>
                    <SelectItem value="declining_balance">Declining Balance</SelectItem>
                    <SelectItem value="units_of_production">Units of Production</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="useful_life_years">Useful Life (Years)</Label>
                <Input
                  id="useful_life_years"
                  type="number"
                  value={formData.useful_life_years || ''}
                  onChange={(e) => setFormData({ ...formData, useful_life_years: parseInt(e.target.value) || undefined })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="salvage_value">Salvage Value</Label>
                <Input
                  id="salvage_value"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                  value={formData.salvage_value === '' || formData.salvage_value === 0 ? '' : formData.salvage_value}
                  onChange={(e) => setFormData({ ...formData, salvage_value: e.target.value === '' ? '' : parseFloat(e.target.value) })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="depreciation_start_date">Depreciation Start Date *</Label>
              <Input
                id="depreciation_start_date"
                type="date"
                value={formData.depreciation_start_date}
                onChange={(e) => setFormData({ ...formData, depreciation_start_date: e.target.value })}
              />
            </div>
            
            {/* Account Selection Fields */}
            <div className="space-y-4 border-t pt-4">
              <h3 className="font-semibold text-sm">Account Assignments *</h3>
              <div className="grid grid-cols-1 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="asset_account_id">Asset Account *</Label>
                  <Select
                    value={formData.asset_account_id?.toString() || 'none'}
                    onValueChange={(value) => setFormData({ ...formData, asset_account_id: parseInt(value) })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select asset account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none" disabled>Select asset account</SelectItem>
                      {accounts
                        .filter(acc => acc.type === 'asset' && acc.is_active)
                        .map(acc => (
                          <SelectItem key={acc.id} value={acc.id.toString()}>
                            {acc.code} - {acc.name}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="depreciation_account_id">Depreciation Expense Account *</Label>
                  <Select
                    value={formData.depreciation_account_id?.toString() || 'none'}
                    onValueChange={(value) => setFormData({ ...formData, depreciation_account_id: parseInt(value) })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select depreciation expense account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none" disabled>Select depreciation expense account</SelectItem>
                      {accounts
                        .filter(acc => acc.type === 'expense' && acc.is_active)
                        .map(acc => (
                          <SelectItem key={acc.id} value={acc.id.toString()}>
                            {acc.code} - {acc.name}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="accumulated_depreciation_account_id">Accumulated Depreciation Account *</Label>
                  <Select
                    value={formData.accumulated_depreciation_account_id?.toString() || 'none'}
                    onValueChange={(value) => setFormData({ ...formData, accumulated_depreciation_account_id: parseInt(value) })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select accumulated depreciation account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none" disabled>Select accumulated depreciation account</SelectItem>
                      {accounts
                        .filter(acc => acc.type === 'asset' && acc.is_active)
                        .map(acc => (
                          <SelectItem key={acc.id} value={acc.id.toString()}>
                            {acc.code} - {acc.name}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Input
                id="description"
                value={formData.description || ''}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              {createMutation.isPending || updateMutation.isPending ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Saving...
                </>
              ) : (
                'Save Asset'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={disposeTarget !== null} onOpenChange={(open) => !open && !disposeMutation.isPending && setDisposeTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Dispose {disposeTarget?.name || 'asset'}</DialogTitle>
            <DialogDescription>
              This posts a disposal journal entry, removes the asset from the ledger, and permanently stops depreciation.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="grid grid-cols-2 gap-3 rounded-md border bg-muted/30 p-3 text-sm">
              <div><span className="text-muted-foreground">Asset number</span><div className="font-medium">{disposeTarget?.asset_number}</div></div>
              <div><span className="text-muted-foreground">Current book value</span><div className="font-medium">${Number(disposeTarget?.book_value || 0).toLocaleString()}</div></div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="disposal_date">Disposal date *</Label>
                <Input id="disposal_date" type="date" value={disposeForm.disposal_date} onChange={(e) => setDisposeForm({ ...disposeForm, disposal_date: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Method *</Label>
                <Select value={disposeForm.disposal_method} onValueChange={(value: DisposeAssetRequest['disposal_method']) => setDisposeForm({ ...disposeForm, disposal_method: value, disposal_amount: value === 'sold' ? '' : 0 })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="sold">Sold</SelectItem>
                    <SelectItem value="scrapped">Scrapped</SelectItem>
                    <SelectItem value="donated">Donated</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="disposal_amount">Disposal proceeds *</Label>
              <Input id="disposal_amount" type="number" min="0" step="0.01" disabled={disposeForm.disposal_method !== 'sold'} value={disposeForm.disposal_amount} onChange={(e) => setDisposeForm({ ...disposeForm, disposal_amount: e.target.value === '' ? '' : Math.max(0, Number(e.target.value)) })} />
              {disposeForm.disposal_method !== 'sold' && <p className="text-xs text-muted-foreground">Scrapped and donated assets have zero proceeds.</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="disposal_notes">Notes</Label>
              <Input id="disposal_notes" maxLength={1000} value={disposeForm.notes || ''} onChange={(e) => setDisposeForm({ ...disposeForm, notes: e.target.value })} placeholder="Reason, buyer, or supporting reference" />
            </div>
            <div className={`rounded-md border p-3 text-sm ${estimatedGainLoss < 0 ? 'border-amber-300 bg-amber-50 text-amber-900' : 'bg-muted/30'}`}>
              Estimated {estimatedGainLoss >= 0 ? 'gain' : 'loss'}: <strong>${Math.abs(estimatedGainLoss).toLocaleString()}</strong>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" disabled={disposeMutation.isPending} onClick={() => setDisposeTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              disabled={disposeMutation.isPending || !disposeForm.disposal_date || (disposeForm.disposal_method === 'sold' && disposeForm.disposal_amount === '')}
              onClick={() => disposeTarget && disposeMutation.mutate({ id: disposeTarget.id, data: { ...disposeForm, disposal_amount: Number(disposeForm.disposal_amount) } })}
            >
              {disposeMutation.isPending && <Icon icon="svg-spinners:ring-resize" className="mr-2 h-4 w-4" />}
              Post disposal
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deactivateTarget !== null}
        onOpenChange={(open) => !open && setDeactivateTarget(null)}
        title={`Deactivate ${deactivateTarget?.name || 'asset'}?`}
        description="The asset is removed from the register and stops depreciating. Assets with posted depreciation stay in the ledger and can be reactivated later."
        confirmLabel="Deactivate"
        tone="warning"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          if (!deactivateTarget) return;
          deleteMutation.mutate(deactivateTarget.id);
          setDeactivateTarget(null);
        }}
      />
    </div>
  );
}
