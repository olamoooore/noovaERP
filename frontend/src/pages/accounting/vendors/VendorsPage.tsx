import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
  code?: string | null;
  name: string;
  email?: string | null;
  phone?: string | null;
  company?: string | null;
  tax_number?: string | null;
  company_registration_number?: string | null;
  address?: string | null;
  billing_address?: string | null;
  shipping_address?: string | null;
  contact_person?: string | null;
  website?: string | null;
  payment_terms?: number | null;
  notes?: string | null;
  is_active?: boolean;
  addresses?: VendorAddress[];
}

interface VendorAddress {
  id?: number;
  type: 'billing' | 'shipping' | 'other';
  label: string;
  street: string;
  street2: string;
  city: string;
  state: string;
  zip: string;
  country: string;
  is_default: boolean;
}

interface VendorForm {
  code: string;
  name: string;
  email: string;
  phone: string;
  company: string;
  tax_number: string;
  company_registration_number: string;
  address: string;
  billing_address: string;
  shipping_address: string;
  contact_person: string;
  website: string;
  payment_terms: string;
  notes: string;
}

const emptyForm: VendorForm = {
  code: '',
  name: '',
  email: '',
  phone: '',
  company: '',
  tax_number: '',
  company_registration_number: '',
  address: '',
  billing_address: '',
  shipping_address: '',
  contact_person: '',
  website: '',
  payment_terms: '30',
  notes: '',
};

const emptyAddress: VendorAddress = {
  type: 'billing',
  label: '',
  street: '',
  street2: '',
  city: '',
  state: '',
  zip: '',
  country: '',
  is_default: false,
};

const formatAddress = (address: VendorAddress) =>
  [address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join(', ');

export default function VendorsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editingVendor, setEditingVendor] = useState<Vendor | null>(null);
  const [formData, setFormData] = useState<VendorForm>(emptyForm);
  const [searchTerm, setSearchTerm] = useState('');
  const [addresses, setAddresses] = useState<VendorAddress[]>([]);
  const [addressOpen, setAddressOpen] = useState(false);
  const [editingAddressIndex, setEditingAddressIndex] = useState<number | null>(null);
  const [addressForm, setAddressForm] = useState<VendorAddress>(emptyAddress);
  const [deletingVendor, setDeletingVendor] = useState<Vendor | null>(null);

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

  const deleteMutation = useMutation({
    mutationFn: async (vendorId: number) => {
      const response = await accountingApi.delete(`/accounting/vendors/${vendorId}`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendors'] });
      toast({ title: 'Vendor deleted', description: 'The vendor was deleted successfully.' });
      setDeletingVendor(null);
    },
    onError: (error: any) => {
      toast({
        title: 'Unable to delete vendor',
        description: error.response?.data?.message || 'Failed to delete vendor',
        variant: 'destructive',
      });
      setDeletingVendor(null);
    },
  });


  const closeForm = () => {
    setFormOpen(false);
    setEditingVendor(null);
    setFormData(emptyForm);
    setAddresses([]);
  };

  const openCreate = () => {
    setEditingVendor(null);
    setFormData(emptyForm);
    setAddresses([]);
    setFormOpen(true);
  };

  const openEdit = (vendor: Vendor) => {
    setEditingVendor(vendor);
    setFormData({
      code: vendor.code || '',
      name: vendor.name || '',
      email: vendor.email || '',
      phone: vendor.phone || '',
      company: vendor.company || '',
      tax_number: vendor.tax_number || '',
      company_registration_number: vendor.company_registration_number || '',
      address: vendor.address || '',
      billing_address: vendor.billing_address || vendor.address || '',
      shipping_address: vendor.shipping_address || vendor.address || '',
      contact_person: vendor.contact_person || '',
      website: vendor.website || '',
      payment_terms: String(vendor.payment_terms ?? '30'),
      notes: vendor.notes || '',
    });
    setAddresses((vendor.addresses || []).map((address) => ({
      ...emptyAddress,
      ...address,
    })));
    setFormOpen(true);
  };

  const openAddressForm = (index?: number) => {
    if (index === undefined) {
      setEditingAddressIndex(null);
      setAddressForm(emptyAddress);
    } else {
      setEditingAddressIndex(index);
      setAddressForm({ ...addresses[index] });
    }
    setAddressOpen(true);
  };

  const saveAddress = () => {
    if (!addressForm.street.trim()) {
      toast({ title: 'Validation Error', description: 'Street is required', variant: 'destructive' });
      return;
    }

    setAddresses((current) => {
      let next = [...current];
      if (addressForm.is_default) {
        next = next.map((address, index) =>
          address.type === addressForm.type && index !== editingAddressIndex
            ? { ...address, is_default: false }
            : address,
        );
      }
      if (editingAddressIndex === null) next.push({ ...addressForm });
      else next[editingAddressIndex] = { ...addressForm };
      return next;
    });
    setAddressOpen(false);
  };

  const handleSubmit = () => {
    if (!formData.name.trim()) {
      toast({ title: 'Validation Error', description: 'Vendor name is required', variant: 'destructive' });
      return;
    }

    const billingAddress = addresses.find((address) => address.type === 'billing' && address.is_default)
      || addresses.find((address) => address.type === 'billing');
    const shippingAddress = addresses.find((address) => address.type === 'shipping' && address.is_default)
      || addresses.find((address) => address.type === 'shipping');

    const payload = {
      code: formData.code || null,
      name: formData.name,
      email: formData.email || null,
      phone: formData.phone || null,
      company: formData.company || null,
      tax_number: formData.tax_number || null,
      company_registration_number: formData.company_registration_number || null,
      address: billingAddress ? formatAddress(billingAddress) : (formData.address || null),
      billing_address: billingAddress ? formatAddress(billingAddress) : (formData.billing_address || null),
      shipping_address: shippingAddress ? formatAddress(shippingAddress) : (formData.shipping_address || null),
      contact_person: formData.contact_person || null,
      website: formData.website || null,
      payment_terms: parseInt(formData.payment_terms) || 30,
      notes: formData.notes || null,
      addresses,
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
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => navigate('/accounting/vendors/import')}>
              <Icon icon="solar:upload-linear" className="w-4 h-4 mr-2" />
              Import Vendors
            </Button>
            <Button onClick={openCreate}>
              <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
              Add Vendor
            </Button>
          </div>
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
                <TableHead>Code</TableHead>
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
                  <TableCell colSpan={9} className="text-center py-8 text-muted-foreground">
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
                    <TableCell>{vendor.code || '-'}</TableCell>
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
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:text-destructive"
                        onClick={() => setDeletingVendor(vendor)}
                      >
                        <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
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
                <Label>Vendor Code</Label>
                <Input
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  placeholder="Auto-generated if empty"
                />
              </div>
              <div className="space-y-2">
                <Label>Vendor Name *</Label>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Vendor name"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Company</Label>
              <Input
                value={formData.company}
                onChange={(e) => setFormData({ ...formData, company: e.target.value })}
                placeholder="Company name"
              />
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
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label>Addresses</Label>
                <Button type="button" variant="outline" size="sm" onClick={() => openAddressForm()}>
                  <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                  Add Address
                </Button>
              </div>
              {addresses.length === 0 ? (
                <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                  No structured addresses added.
                </div>
              ) : (
                <div className="space-y-2">
                  {addresses.map((address, index) => (
                    <div key={`${address.type}-${index}`} className="flex items-start justify-between rounded-md border p-3">
                      <div>
                        <div className="flex items-center gap-2">
                          <Badge variant="secondary" className="capitalize">{address.type}</Badge>
                          <span className="font-medium">{address.label || 'Address'}</span>
                          {address.is_default && <Badge>Default</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{formatAddress(address)}</p>
                      </div>
                      <div className="flex gap-1">
                        <Button type="button" variant="ghost" size="sm" onClick={() => openAddressForm(index)}>
                          <Icon icon="solar:pen-linear" className="w-4 h-4" />
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setAddresses(addresses.filter((_, i) => i !== index))}>
                          <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
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

      <Dialog open={addressOpen} onOpenChange={setAddressOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editingAddressIndex === null ? 'Add Address' : 'Edit Address'}</DialogTitle>
            <DialogDescription>Add a structured vendor billing, shipping, or other address.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Type *</Label>
                <Select value={addressForm.type} onValueChange={(value) => setAddressForm({ ...addressForm, type: value as VendorAddress['type'] })}>
                  <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="billing">Billing</SelectItem>
                    <SelectItem value="shipping">Shipping</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Label</Label>
                <Input value={addressForm.label} onChange={(e) => setAddressForm({ ...addressForm, label: e.target.value })} placeholder="e.g., Main Office" />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Street *</Label>
              <Input value={addressForm.street} onChange={(e) => setAddressForm({ ...addressForm, street: e.target.value })} />
            </div>
            <div className="space-y-2">
              <Label>Street 2</Label>
              <Input value={addressForm.street2} onChange={(e) => setAddressForm({ ...addressForm, street2: e.target.value })} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>City</Label>
                <Input value={addressForm.city} onChange={(e) => setAddressForm({ ...addressForm, city: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>State/Province</Label>
                <Input value={addressForm.state} onChange={(e) => setAddressForm({ ...addressForm, state: e.target.value })} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>ZIP/Postal Code</Label>
                <Input value={addressForm.zip} onChange={(e) => setAddressForm({ ...addressForm, zip: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Country</Label>
                <Input value={addressForm.country} onChange={(e) => setAddressForm({ ...addressForm, country: e.target.value })} />
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Checkbox id="vendor-address-default" checked={addressForm.is_default} onCheckedChange={(checked) => setAddressForm({ ...addressForm, is_default: checked === true })} />
              <Label htmlFor="vendor-address-default" className="font-normal">Set as default address for this type</Label>
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setAddressOpen(false)}>Cancel</Button>
            <Button type="button" onClick={saveAddress}>{editingAddressIndex === null ? 'Add Address' : 'Update Address'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={deletingVendor !== null} onOpenChange={(open) => !open && setDeletingVendor(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete vendor?</AlertDialogTitle>
            <AlertDialogDescription>
              This will delete {deletingVendor?.code ? `${deletingVendor.code} — ` : ''}{deletingVendor?.name}.
              Vendors with bills or payments cannot be deleted and should be deactivated instead.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleteMutation.isPending}
              onClick={() => deletingVendor && deleteMutation.mutate(deletingVendor.id)}
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete Vendor'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

    </div>
  );
}
