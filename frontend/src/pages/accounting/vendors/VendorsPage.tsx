import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { useToast } from '@/hooks/use-toast';
import { accountingApi } from '@/lib/api';

interface Vendor {
  id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  company?: string | null;
  tax_number?: string | null;
  company_registration_number?: string | null;
  address?: string | null;
  contact_person?: string | null;
  website?: string | null;
  payment_terms?: number | null;
  notes?: string | null;
  is_active?: boolean;
}

interface VendorForm {
  name: string;
  email: string;
  phone: string;
  company: string;
  tax_number: string;
  company_registration_number: string;
  address: string;
  contact_person: string;
  website: string;
  payment_terms: string;
  notes: string;
}

const emptyForm: VendorForm = {
  name: '',
  email: '',
  phone: '',
  company: '',
  tax_number: '',
  company_registration_number: '',
  address: '',
  contact_person: '',
  website: '',
  payment_terms: '30',
  notes: '',
};

export default function VendorsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editingVendor, setEditingVendor] = useState<Vendor | null>(null);
  const [formData, setFormData] = useState<VendorForm>(emptyForm);
  const [searchTerm, setSearchTerm] = useState('');

  const { data: vendorsData, isLoading } = useQuery({
    queryKey: ['vendors', searchTerm],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '100' });
      if (searchTerm) params.set('search', searchTerm);
      const response = await accountingApi.get(`/accounting/vendors?${params}`);
      return response.data;
    },
  });

  const vendors: Vendor[] = vendorsData?.data || [];

  const createMutation = useMutation({
    mutationFn: async (data: Partial<Vendor>) => {
      const response = await accountingApi.post('/accounting/vendors', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendors'] });
      toast({ title: 'Success', description: 'Vendor created successfully' });
      closeForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create vendor',
        variant: 'destructive',
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<Vendor> }) => {
      const response = await accountingApi.put(`/accounting/vendors/${id}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendors'] });
      toast({ title: 'Success', description: 'Vendor updated successfully' });
      closeForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update vendor',
        variant: 'destructive',
      });
    },
  });

  const closeForm = () => {
    setFormOpen(false);
    setEditingVendor(null);
    setFormData(emptyForm);
  };

  const openCreate = () => {
    setEditingVendor(null);
    setFormData(emptyForm);
    setFormOpen(true);
  };

  const openEdit = (vendor: Vendor) => {
    setEditingVendor(vendor);
    setFormData({
      name: vendor.name || '',
      email: vendor.email || '',
      phone: vendor.phone || '',
      company: vendor.company || '',
      tax_number: vendor.tax_number || '',
      company_registration_number: vendor.company_registration_number || '',
      address: vendor.address || '',
      contact_person: vendor.contact_person || '',
      website: vendor.website || '',
      payment_terms: String(vendor.payment_terms ?? '30'),
      notes: vendor.notes || '',
    });
    setFormOpen(true);
  };

  const handleSubmit = () => {
    if (!formData.name.trim()) {
      toast({ title: 'Validation Error', description: 'Vendor name is required', variant: 'destructive' });
      return;
    }

    const payload = {
      name: formData.name,
      email: formData.email || null,
      phone: formData.phone || null,
      company: formData.company || null,
      tax_number: formData.tax_number || null,
      company_registration_number: formData.company_registration_number || null,
      address: formData.address || null,
      contact_person: formData.contact_person || null,
      website: formData.website || null,
      payment_terms: parseInt(formData.payment_terms) || 30,
      notes: formData.notes || null,
    };

    if (editingVendor) {
      updateMutation.mutate({ id: editingVendor.id, data: payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const isPending = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Vendors</h1>
          <p className="text-muted-foreground mt-1">Manage your vendor/supplier records</p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      <Card className="p-4">
        <div className="flex items-center justify-between gap-4">
          <div className="flex-1 max-w-md">
            <Input
              placeholder="Search vendors..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
          <Button onClick={openCreate}>
            <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
            Add Vendor
          </Button>
        </div>
      </Card>

      <Card>
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading vendors...</p>
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Company</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Phone</TableHead>
                <TableHead>Contact Person</TableHead>
                <TableHead>Payment Terms</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {vendors.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                    <Icon icon="solar:user-cross-bold-duotone" className="w-12 h-12 mx-auto mb-3 opacity-50" />
                    <p className="text-lg font-medium mb-1">No vendors yet</p>
                    <p className="text-sm mb-4">Add your first vendor to start creating bills</p>
                    <Button onClick={openCreate} size="sm">
                      <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                      Add Vendor
                    </Button>
                  </TableCell>
                </TableRow>
              ) : (
                vendors.map((vendor) => (
                  <TableRow key={vendor.id}>
                    <TableCell className="font-medium">{vendor.name}</TableCell>
                    <TableCell>{vendor.company || '-'}</TableCell>
                    <TableCell>{vendor.email || '-'}</TableCell>
                    <TableCell>{vendor.phone || '-'}</TableCell>
                    <TableCell>{vendor.contact_person || '-'}</TableCell>
                    <TableCell>{vendor.payment_terms ? `${vendor.payment_terms} days` : '-'}</TableCell>
                    <TableCell>
                      <Badge variant={vendor.is_active !== false ? 'default' : 'secondary'}>
                        {vendor.is_active !== false ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(vendor)}>
                        <Icon icon="solar:pen-linear" className="w-4 h-4" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        )}
      </Card>

      {/* Create/Edit Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingVendor ? 'Edit Vendor' : 'Add Vendor'}</DialogTitle>
            <DialogDescription>
              {editingVendor ? 'Update vendor details' : 'Create a new vendor record'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Vendor Name *</Label>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Vendor name"
                />
              </div>
              <div className="space-y-2">
                <Label>Company</Label>
                <Input
                  value={formData.company}
                  onChange={(e) => setFormData({ ...formData, company: e.target.value })}
                  placeholder="Company name"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Email</Label>
                <Input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="vendor@example.com"
                />
              </div>
              <div className="space-y-2">
                <Label>Phone</Label>
                <Input
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  placeholder="+1 234 567 890"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Contact Person</Label>
                <Input
                  value={formData.contact_person}
                  onChange={(e) => setFormData({ ...formData, contact_person: e.target.value })}
                  placeholder="Contact name"
                />
              </div>
              <div className="space-y-2">
                <Label>Payment Terms (days)</Label>
                <Input
                  type="number"
                  value={formData.payment_terms}
                  onChange={(e) => setFormData({ ...formData, payment_terms: e.target.value })}
                  placeholder="30"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Tax Number</Label>
                <Input
                  value={formData.tax_number}
                  onChange={(e) => setFormData({ ...formData, tax_number: e.target.value })}
                  placeholder="Tax ID / GST number"
                />
              </div>
              <div className="space-y-2">
                <Label>Registration Number</Label>
                <Input
                  value={formData.company_registration_number}
                  onChange={(e) => setFormData({ ...formData, company_registration_number: e.target.value })}
                  placeholder="Company registration"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Address</Label>
              <Input
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                placeholder="Full address"
              />
            </div>
            <div className="space-y-2">
              <Label>Website</Label>
              <Input
                value={formData.website}
                onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                placeholder="https://example.com"
              />
            </div>
            <div className="space-y-2">
              <Label>Notes</Label>
              <Input
                value={formData.notes}
                onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                placeholder="Additional notes"
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeForm}>Cancel</Button>
            <Button onClick={handleSubmit} disabled={isPending}>
              {isPending ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Saving...
                </>
              ) : (
                editingVendor ? 'Update Vendor' : 'Create Vendor'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
