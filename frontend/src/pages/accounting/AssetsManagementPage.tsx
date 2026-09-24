import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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
import fixedAssetsApi, { type FixedAsset, type CreateAssetRequest } from '@/lib/fixedAssetsApi';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { SalesDocumentExportMenu, type SalesExportColumn } from '@/components/sales/SalesDocumentExportMenu';

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
  const { data: assets = [], isLoading } = useQuery({
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['depreciation-schedule'] });
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
      // Re-fetch the schedule for the currently selected asset so the table updates
      if (selectedAssetId) {
        queryClient.refetchQueries({ queryKey: ['depreciation-schedule', selectedAssetId] });
      }
      toast({ title: 'Success', description: 'Depreciation calculated successfully' });
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

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to deactivate this asset?')) {
      deleteMutation.mutate(id);
    }
  };

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
                <HeaderActionButton icon="solar:upload-linear" variant="outline" onClick={() => navigate('/accounting/core/assets/import')}>
                  Import CSV
                </HeaderActionButton>
                <Input
                  placeholder="Search assets..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-64"
                />
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="w-40">
                    <SelectValue placeholder="All Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="disposed">Disposed</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
                <HeaderActionButton icon="solar:add-circle-linear" onClick={() => handleOpenForm()}>
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
                        ${asset.purchase_cost.toLocaleString()}
                      </TableCell>
                      <TableCell className="font-mono">
                        ${asset.book_value.toLocaleString()}
                      </TableCell>
                      <TableCell>
                        <Badge className={getStatusBadge(asset.status)}>
                          {asset.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleOpenForm(asset)}
                          >
                            <Icon icon="solar:pen-linear" className="w-4 h-4" />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleDelete(asset.id)}
                          >
                            <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                          </Button>
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
                        ${Number(entry.beginning_value || entry.opening_value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        ${Number(entry.depreciation_amount || entry.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        ${Number(entry.accumulated_depreciation || entry.accumulated || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        ${Number(entry.ending_book_value || entry.book_value || entry.closing_value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
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
    </div>
  );
}
