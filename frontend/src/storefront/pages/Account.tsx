import { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft,
  User,
  Package,
  Heart,
  LogOut,
  Loader2,
  MapPin,
  Plus,
  Pencil,
  Trash2,
  Star,
  ChevronRight,
  Ticket,
  FileText,
  Download,
  Eye,
  X,
  Save,
  Shield,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCustomerAuth } from '@/storefront/context/CustomerAuthContext';
import { useWishlist } from '@/storefront/context/WishlistContext';
import {
  getCustomerOrders,
  getCustomerOrder,
  getCustomerTickets,
  getCustomerAddresses,
  addCustomerAddress,
  updateCustomerAddress,
  deleteCustomerAddress,
  setDefaultAddress,
  updateCustomerProfile,
  cancelOrder,
  retryOrderPayment,
  getPaymentGateways,
  getOrderInvoice,
  CustomerOrder,
  CustomerTicket,
  CustomerAddress,
  CheckoutResponse,
  WebInvoice,
} from '@/storefront/lib/api';
import { toast } from 'sonner';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { cn } from '@/lib/utils';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';
import { CreditCard, AlertTriangle, XCircle } from 'lucide-react';

// ── Types ──────────────────────────────────────────────────────
type Tab = 'profile' | 'orders' | 'tickets' | 'addresses' | 'wishlist';

const STATUS_COLORS: Record<string, string> = {
  pending: 'bg-yellow-500/20 text-yellow-500',
  confirmed: 'bg-blue-500/20 text-blue-500',
  processing: 'bg-purple-500/20 text-purple-500',
  shipped: 'bg-indigo-500/20 text-indigo-500',
  delivered: 'bg-green-500/20 text-green-500',
  completed: 'bg-green-500/20 text-green-500',
  cancelled: 'bg-red-500/20 text-red-500',
  refunded: 'bg-orange-500/20 text-orange-500',
  paid: 'bg-green-500/20 text-green-500',
  failed: 'bg-red-500/20 text-red-500',
};

function statusBadge(status: string) {
  return (
    <span
      className={cn(
        'text-xs px-2.5 py-1 rounded-full font-medium capitalize',
        STATUS_COLORS[status] || 'bg-muted text-muted-foreground',
      )}
    >
      {status}
    </span>
  );
}

/** Derive effective order status: if payment is "paid" but order status is "pending", show "confirmed" */
function effectiveOrderStatus(order: CustomerOrder): string {
  if (order.status === 'pending' && order.payment_status?.toLowerCase() === 'paid') {
    return 'confirmed';
  }
  return order.status;
}

function getCountdownText(dateValue?: string | null): string {
  if (!dateValue) return 'Date TBA';

  const target = new Date(dateValue);
  if (Number.isNaN(target.getTime())) return 'Date TBA';

  const diffMs = target.getTime() - Date.now();
  if (diffMs <= 0) return 'Happening now';

  const totalMinutes = Math.floor(diffMs / (1000 * 60));
  const days = Math.floor(totalMinutes / (60 * 24));
  const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
  const minutes = totalMinutes % 60;

  if (days > 0) return `Starts in ${days}d ${hours}h`;
  if (hours > 0) return `Starts in ${hours}h ${minutes}m`;
  return `Starts in ${Math.max(minutes, 1)}m`;
}

// ── Main Component ─────────────────────────────────────────────
const Account = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { customer, token, isAuthenticated, isLoading, logout, refreshProfile } =
    useCustomerAuth();
  const { items: wishlistItems } = useWishlist();

  // Tab
  const tabFromUrl = searchParams.get('tab') as Tab | null;
  const [activeTab, setActiveTab] = useState<Tab>(tabFromUrl || 'profile');

  // Orders
  const [orders, setOrders] = useState<CustomerOrder[]>([]);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<CustomerOrder | null>(null);
  const [orderDetailLoading, setOrderDetailLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<WebInvoice | null>(null);
  const [invoiceLoading, setInvoiceLoading] = useState(false);
  const [tickets, setTickets] = useState<CustomerTicket[]>([]);
  const [ticketsLoading, setTicketsLoading] = useState(false);

  // Addresses
  const [addresses, setAddresses] = useState<CustomerAddress[]>([]);
  const [addressesLoading, setAddressesLoading] = useState(false);
  const [addressModal, setAddressModal] = useState<{
    open: boolean;
    editing: CustomerAddress | null;
  }>({ open: false, editing: null });
  const [addressForm, setAddressForm] = useState({
    type: 'shipping' as 'shipping' | 'billing',
    name: '',
    company: '',
    address: '',
    city: 'Singapore',
    state: '',
    postal_code: '',
    country: 'Singapore',
    phone: '',
    is_default: false,
  });
  const [addressSaving, setAddressSaving] = useState(false);

  // Profile editing
  const [editingProfile, setEditingProfile] = useState(false);
  const [profileForm, setProfileForm] = useState({ name: '', phone: '' });
  const [profileSaving, setProfileSaving] = useState(false);

  // ── URL sync ────────────────────────────────────────────────
  useEffect(() => {
    if (tabFromUrl && ['profile', 'orders', 'tickets', 'addresses', 'wishlist'].includes(tabFromUrl)) {
      setActiveTab(tabFromUrl);
    }
  }, [tabFromUrl]);

  // Auth guard (wishlist tab doesn't need auth)
  useEffect(() => {
    if (!isLoading && !isAuthenticated && activeTab !== 'wishlist') {
      navigate(storefrontRoutes.login, { replace: true });
    }
  }, [isLoading, isAuthenticated, navigate, activeTab]);

  // ── Load orders ─────────────────────────────────────────────
  const loadOrders = useCallback(async () => {
    if (!token) return;
    setOrdersLoading(true);
    try {
      const data = await getCustomerOrders(token);
      setOrders(data);
    } catch {
      toast.error('Failed to load orders');
    } finally {
      setOrdersLoading(false);
    }
  }, [token]);

  useEffect(() => {
    if (activeTab === 'orders' && token && orders.length === 0) {
      loadOrders();
    }
  }, [activeTab, token, orders.length, loadOrders]);

  const loadTickets = useCallback(async () => {
    if (!token) return;
    setTicketsLoading(true);
    try {
      const data = await getCustomerTickets(token);
      setTickets(data);
    } catch {
      toast.error('Failed to load tickets');
    } finally {
      setTicketsLoading(false);
    }
  }, [token]);

  useEffect(() => {
    if (activeTab === 'tickets' && token && tickets.length === 0) {
      loadTickets();
    }
  }, [activeTab, token, tickets.length, loadTickets]);

  useEffect(() => {
    if (activeTab === 'profile' && token) {
      if (orders.length === 0) {
        loadOrders();
      }
      if (tickets.length === 0) {
        loadTickets();
      }
    }
  }, [activeTab, token, orders.length, tickets.length, loadOrders, loadTickets]);

  // ── Load addresses ──────────────────────────────────────────
  const loadAddresses = useCallback(async () => {
    if (!token) return;
    setAddressesLoading(true);
    try {
      const data = await getCustomerAddresses(token);
      setAddresses(data);
    } catch {
      toast.error('Failed to load addresses');
    } finally {
      setAddressesLoading(false);
    }
  }, [token]);

  useEffect(() => {
    if (activeTab === 'addresses' && token && addresses.length === 0) {
      loadAddresses();
    }
  }, [activeTab, token, addresses.length, loadAddresses]);

  const viewOrderDetail = async (order: CustomerOrder) => {
    if (!token) return;
    setOrderDetailLoading(true);
    try {
      const detail = await getCustomerOrder(token, order.id);
      setSelectedOrder(detail);
    } catch {
      toast.error('Failed to load order details');
    } finally {
      setOrderDetailLoading(false);
    }
  };

  // ── View Invoice ────────────────────────────────────────────
  const handleViewInvoice = async (orderId: number) => {
    if (!token) return;
    setInvoiceLoading(true);
    try {
      const invoice = await getOrderInvoice(token, orderId);
      setSelectedInvoice(invoice);
    } catch (err: any) {
      toast.error(err.response?.data?.error || 'Failed to load invoice');
    } finally {
      setInvoiceLoading(false);
    }
  };

  // ── Cancel Order ────────────────────────────────────────────
  const handleCancelOrder = async (orderId: number) => {
    if (!token) return;
    if (!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) return;
    setIsSubmitting(true);
    try {
      const result = await cancelOrder(token, orderId);
      toast.success(result.message || 'Order cancelled');
      setSelectedOrder(result.order);
      // Refresh orders list
      loadOrders();
    } catch (err: any) {
      toast.error(err.response?.data?.error || 'Failed to cancel order');
    } finally {
      setIsSubmitting(false);
    }
  };

  // ── Retry Payment ───────────────────────────────────────────
  const handleRetryPayment = async (order: CustomerOrder) => {
    if (!token) return;
    setIsSubmitting(true);
    try {
      // Get available gateways first and use the first one
      const gateways = await getPaymentGateways();
      const gatewayId = gateways.length > 0 ? gateways[0].id : undefined;

      const response = await retryOrderPayment(token, order.id, gatewayId);
      const pi = response.payment_intent;

      if (pi?.payment_url) {
        // HitPay / PayPal → open in new window
        const w = 500, h = 700;
        const left = window.screen.width / 2 - w / 2;
        const top = window.screen.height / 2 - h / 2;
        window.open(pi.payment_url, 'PaymentWindow', `width=${w},height=${h},left=${left},top=${top},scrollbars=yes`);
        toast.info('Complete payment in the opened window');
      } else if (pi?.client_secret) {
        // Stripe → redirect to checkout page with the order context
        navigate(`/store/checkout?retry_order=${order.id}`);
      } else {
        toast.error('No payment method available');
      }
    } catch (err: any) {
      toast.error(err.response?.data?.error || err.response?.data?.message || 'Failed to initiate payment');
    } finally {
      setIsSubmitting(false);
    }
  };

  // ── Address CRUD ────────────────────────────────────────────
  const openAddressForm = (addr?: CustomerAddress) => {
    if (addr) {
      setAddressForm({
        type: addr.type,
        name: addr.name || '',
        company: addr.company || '',
        address: addr.address || '',
        city: addr.city || '',
        state: addr.state || '',
        postal_code: addr.postal_code || '',
        country: addr.country || 'Singapore',
        phone: addr.phone || '',
        is_default: addr.is_default,
      });
      setAddressModal({ open: true, editing: addr });
    } else {
      setAddressForm({
        type: 'shipping',
        name: '',
        company: '',
        address: '',
        city: 'Singapore',
        state: '',
        postal_code: '',
        country: 'Singapore',
        phone: '',
        is_default: false,
      });
      setAddressModal({ open: true, editing: null });
    }
  };

  const handleSaveAddress = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setAddressSaving(true);
    try {
      if (addressModal.editing) {
        await updateCustomerAddress(token, addressModal.editing.id, addressForm);
        toast.success('Address updated');
      } else {
        await addCustomerAddress(token, addressForm);
        toast.success('Address added');
      }
      setAddressModal({ open: false, editing: null });
      setAddresses([]);
      loadAddresses();
    } catch (err: any) {
      const msg = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join('\n')
        : 'Failed to save address';
      toast.error(msg);
    } finally {
      setAddressSaving(false);
    }
  };

  const handleDeleteAddress = async (id: number) => {
    if (!token) return;
    try {
      await deleteCustomerAddress(token, id);
      setAddresses((prev) => prev.filter((a) => a.id !== id));
      toast.success('Address deleted');
    } catch {
      toast.error('Failed to delete address');
    }
  };

  const handleSetDefault = async (id: number) => {
    if (!token) return;
    try {
      await setDefaultAddress(token, id);
      setAddresses([]);
      loadAddresses();
      toast.success('Default address updated');
    } catch {
      toast.error('Failed to set default');
    }
  };

  // ── Profile save ────────────────────────────────────────────
  const handleSaveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setProfileSaving(true);
    try {
      await updateCustomerProfile(token, {
        name: profileForm.name,
        phone: profileForm.phone || null,
      });
      await refreshProfile();
      setEditingProfile(false);
      toast.success('Profile updated');
    } catch {
      toast.error('Failed to update profile');
    } finally {
      setProfileSaving(false);
    }
  };

  const handleLogout = async () => {
    await logout();
    toast.success('Logged out successfully');
    navigate(storefrontRoutes.home);
  };

  // ── Loading guard ───────────────────────────────────────────
  if (isLoading && activeTab !== 'wishlist') {
    return (
      <Layout>
        <div className="container py-16 flex items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      </Layout>
    );
  }

  if (!isAuthenticated && activeTab !== 'wishlist') return null;

  // ── Tab definitions ─────────────────────────────────────────
  const tabs = isAuthenticated
    ? [
        { id: 'profile' as Tab, label: 'Profile', icon: User },
        { id: 'orders' as Tab, label: 'Orders', icon: Package },
        {
          id: 'tickets' as Tab,
          label: 'Tickets',
          icon: Ticket,
          count: tickets.reduce((sum, booking) => sum + (booking.ticket_count || 0), 0),
        },
        { id: 'addresses' as Tab, label: 'Addresses', icon: MapPin },
        { id: 'wishlist' as Tab, label: 'Wishlist', icon: Heart, count: wishlistItems.length },
      ]
    : [{ id: 'wishlist' as Tab, label: 'Wishlist', icon: Heart, count: wishlistItems.length }];

  // ── Render ──────────────────────────────────────────────────
  return (
    <Layout>
      <div className="container py-8">
        <Link
          to={storefrontRoutes.shop}
          className="inline-flex items-center text-sm text-muted-foreground hover:text-foreground mb-8"
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          Back to Shop
        </Link>

        <div className="grid md:grid-cols-4 gap-8">
          {/* ── Sidebar ──────────────────────────────────── */}
          <div className="md:col-span-1">
            <div className="glass-strong rounded-2xl p-4 sticky top-24">
              <div className="text-center mb-6">
                <div className="w-16 h-16 rounded-full bg-primary/20 flex items-center justify-center mx-auto mb-3">
                  {isAuthenticated ? (
                    <User className="h-8 w-8 text-primary" />
                  ) : (
                    <Heart className="h-8 w-8 text-primary" />
                  )}
                </div>
                {isAuthenticated && customer ? (
                  <>
                    <h2 className="font-display font-bold">{customer.name}</h2>
                    <p className="text-sm text-muted-foreground">{customer.email}</p>
                    {customer.loyalty_points !== undefined && customer.loyalty_points > 0 && (
                      <p className="text-xs text-primary mt-1 flex items-center justify-center gap-1">
                        <Star className="h-3 w-3" />
                        {customer.loyalty_points} loyalty points
                      </p>
                    )}
                  </>
                ) : (
                  <>
                    <h2 className="font-display font-bold">My Wishlist</h2>
                    <Link
                      to={storefrontRoutes.login}
                      className="text-sm text-primary hover:underline"
                    >
                      Sign in for full account access
                    </Link>
                  </>
                )}
              </div>

              <nav className="space-y-1">
                {tabs.map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id)}
                    className={cn(
                      'w-full flex items-center justify-between px-4 py-2.5 rounded-lg transition-colors',
                      activeTab === tab.id
                        ? 'bg-primary/20 text-primary'
                        : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                    )}
                  >
                    <span className="flex items-center gap-2">
                      <tab.icon className="h-4 w-4" />
                      {tab.label}
                    </span>
                    {'count' in tab && tab.count !== undefined && tab.count > 0 && (
                      <span className="text-xs bg-primary/20 text-primary px-2 py-0.5 rounded-full">
                        {tab.count}
                      </span>
                    )}
                  </button>
                ))}
                {isAuthenticated && (
                  <button
                    onClick={handleLogout}
                    className="w-full flex items-center gap-2 px-4 py-2.5 rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors"
                  >
                    <LogOut className="h-4 w-4" />
                    Sign Out
                  </button>
                )}
              </nav>
            </div>
          </div>

          {/* ── Main Content ─────────────────────────────── */}
          <div className="md:col-span-3 space-y-6">
            {/* ━━━ PROFILE TAB ━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {activeTab === 'profile' && customer && (
              <div className="glass-strong rounded-2xl p-6">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-xl font-display font-bold flex items-center gap-2">
                    <Shield className="h-5 w-5 text-primary" />
                    My Profile
                  </h2>
                  {!editingProfile && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setProfileForm({
                          name: customer.name || '',
                          phone: customer.phone || '',
                        });
                        setEditingProfile(true);
                      }}
                    >
                      <Pencil className="h-4 w-4 mr-1" />
                      Edit
                    </Button>
                  )}
                </div>

                {editingProfile ? (
                  <form onSubmit={handleSaveProfile} className="space-y-4">
                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <Label>Full Name</Label>
                        <Input
                          value={profileForm.name}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, name: e.target.value })
                          }
                          required
                        />
                      </div>
                      <div>
                        <Label>Phone</Label>
                        <Input
                          value={profileForm.phone}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, phone: e.target.value })
                          }
                          placeholder="+65 XXXX XXXX"
                        />
                      </div>
                    </div>
                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <Label>Email</Label>
                        <Input value={customer.email} disabled className="opacity-60" />
                        <p className="text-xs text-muted-foreground mt-1">
                          Email cannot be changed
                        </p>
                      </div>
                      <div>
                        <Label>Member Since</Label>
                        <Input
                          value={new Date(customer.created_at).toLocaleDateString('en-SG', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                          })}
                          disabled
                          className="opacity-60"
                        />
                      </div>
                    </div>
                    <div className="flex gap-3 pt-2">
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => setEditingProfile(false)}
                      >
                        Cancel
                      </Button>
                      <Button variant="hero" type="submit" disabled={profileSaving}>
                        {profileSaving ? (
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        ) : (
                          <Save className="h-4 w-4 mr-2" />
                        )}
                        Save Changes
                      </Button>
                    </div>
                  </form>
                ) : (
                  <div className="space-y-4">
                    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                      <div className="rounded-xl border border-border bg-muted/20 p-4">
                        <p className="text-xs text-muted-foreground">Total Orders</p>
                        <p className="text-2xl font-display font-bold">{orders.length}</p>
                      </div>
                      <div className="rounded-xl border border-border bg-muted/20 p-4">
                        <p className="text-xs text-muted-foreground">Total Spent</p>
                        <p className="text-2xl font-display font-bold">
                          SGD {orders.reduce((sum, order) => sum + (order.total || 0), 0).toFixed(2)}
                        </p>
                      </div>
                      <div className="rounded-xl border border-border bg-muted/20 p-4">
                        <p className="text-xs text-muted-foreground">Wishlist</p>
                        <p className="text-2xl font-display font-bold">{wishlistItems.length}</p>
                      </div>
                      <div className="rounded-xl border border-border bg-muted/20 p-4">
                        <p className="text-xs text-muted-foreground">Event Tickets</p>
                        <p className="text-2xl font-display font-bold">
                          {tickets.reduce((sum, booking) => sum + (booking.ticket_count || 0), 0)}
                        </p>
                      </div>
                    </div>

                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <label className="text-sm text-muted-foreground">Name</label>
                        <p className="font-medium">{customer.name}</p>
                      </div>
                      <div>
                        <label className="text-sm text-muted-foreground">Email</label>
                        <p className="font-medium">{customer.email}</p>
                      </div>
                    </div>
                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <label className="text-sm text-muted-foreground">Phone</label>
                        <p className="font-medium">{customer.phone || 'Not provided'}</p>
                      </div>
                      <div>
                        <label className="text-sm text-muted-foreground">Member Since</label>
                        <p className="font-medium">
                          {new Date(customer.created_at).toLocaleDateString('en-SG', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                          })}
                        </p>
                      </div>
                    </div>
                    {customer.loyalty_points !== undefined && (
                      <div className="mt-4 p-4 rounded-xl bg-primary/5 border border-primary/20">
                        <div className="flex items-center gap-2">
                          <Star className="h-5 w-5 text-primary" />
                          <span className="font-medium">Loyalty Points</span>
                        </div>
                        <p className="text-3xl font-display font-bold text-primary mt-2">
                          {customer.loyalty_points}
                        </p>
                        <p className="text-xs text-muted-foreground mt-1">
                          Earn points with every purchase
                        </p>
                      </div>
                    )}

                    <div className="mt-4 p-4 rounded-xl border border-border bg-muted/20">
                      <div className="flex items-center justify-between gap-3 mb-3">
                        <h3 className="text-sm font-medium flex items-center gap-2">
                          <Ticket className="h-4 w-4 text-primary" />
                          My Event Tickets
                        </h3>
                        <Button size="sm" variant="outline" onClick={() => setActiveTab('tickets')}>
                          View all
                        </Button>
                      </div>

                      {ticketsLoading ? (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                          <Loader2 className="h-4 w-4 animate-spin" />
                          Loading tickets...
                        </div>
                      ) : tickets.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                          No event tickets yet. Your confirmed event purchases will appear here.
                        </p>
                      ) : (
                        <div className="space-y-2">
                          {tickets.slice(0, 5).map((booking) => (
                            <div
                              key={booking.id}
                              className="p-3 rounded-lg bg-background/60 border border-border/70"
                            >
                              <div className="flex items-center justify-between gap-3">
                                <div>
                                  <p className="font-medium text-sm">{booking.event_title || 'Event Ticket'}</p>
                                  <p className="text-xs text-muted-foreground">
                                    {booking.event_date
                                      ? new Date(booking.event_date).toLocaleDateString('en-SG', {
                                          year: 'numeric',
                                          month: 'short',
                                          day: 'numeric',
                                        })
                                      : 'Date TBA'}
                                    {booking.event_venue ? ` • ${booking.event_venue}` : ''}
                                  </p>
                                </div>
                                {statusBadge(booking.status)}
                              </div>
                              <p className="text-xs text-muted-foreground mt-1">
                                Ref: {booking.booking_reference} • {booking.ticket_count} ticket{booking.ticket_count > 1 ? 's' : ''}
                              </p>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* ━━━ ORDERS TAB ━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {activeTab === 'orders' && (
              <div className="glass-strong rounded-2xl p-6">
                {/* Order Detail View */}
                {selectedOrder ? (
                  <div>
                    <button
                      onClick={() => setSelectedOrder(null)}
                      className="text-sm text-muted-foreground hover:text-foreground flex items-center gap-1 mb-4"
                    >
                      <ArrowLeft className="h-4 w-4" />
                      Back to Orders
                    </button>

                    <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                      <div>
                        <h2 className="text-xl font-display font-bold">
                          Order {selectedOrder.order_number}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                          Placed{' '}
                          {new Date(selectedOrder.created_at).toLocaleDateString('en-SG', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                          })}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        {statusBadge(effectiveOrderStatus(selectedOrder))}
                        {statusBadge(selectedOrder.payment_status)}
                      </div>
                    </div>

                    {/* Timeline */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                      {[
                        { label: 'Confirmed', date: selectedOrder.confirmed_at || (selectedOrder.payment_status === 'paid' ? selectedOrder.paid_at || selectedOrder.created_at : undefined) },
                        { label: 'Paid', date: selectedOrder.paid_at || (selectedOrder.payment_status === 'paid' ? selectedOrder.created_at : undefined) },
                        { label: 'Shipped', date: selectedOrder.shipped_at },
                        { label: 'Delivered', date: selectedOrder.delivered_at },
                      ].map((step) => (
                        <div
                          key={step.label}
                          className={cn(
                            'p-3 rounded-lg border text-center',
                            step.date
                              ? 'border-primary/30 bg-primary/5'
                              : 'border-border bg-muted/30 opacity-50',
                          )}
                        >
                          <p className="text-xs font-medium">{step.label}</p>
                          {step.date && (
                            <p className="text-[10px] text-muted-foreground mt-1">
                              {new Date(step.date).toLocaleDateString('en-SG')}
                            </p>
                          )}
                        </div>
                      ))}
                    </div>

                    {/* Shipping Address */}
                    {selectedOrder.shipping_address && (
                      <div className="p-4 rounded-xl bg-muted/30 mb-6">
                        <h3 className="text-sm font-medium mb-2 flex items-center gap-2">
                          <MapPin className="h-4 w-4 text-primary" />
                          Shipping Address
                        </h3>
                        <p className="text-sm text-muted-foreground">
                          {selectedOrder.shipping_address.first_name}{' '}
                          {selectedOrder.shipping_address.last_name}
                          <br />
                          {selectedOrder.shipping_address.address}
                          <br />
                          {selectedOrder.shipping_address.city}{' '}
                          {selectedOrder.shipping_address.postal_code}
                          <br />
                          {selectedOrder.shipping_address.country}
                        </p>
                      </div>
                    )}

                    {/* Order Items */}
                    {selectedOrder.items && selectedOrder.items.length > 0 && (
                      <div className="mb-6">
                        <h3 className="text-sm font-medium mb-3">Items</h3>
                        <div className="space-y-3">
                          {selectedOrder.items.map((item) => (
                            <div
                              key={item.id}
                              className="flex gap-3 items-center p-3 rounded-lg bg-muted/20"
                            >
                              {item.product_image ? (
                                <img
                                  src={item.product_image}
                                  alt={item.product_name}
                                  className="w-14 h-14 object-cover rounded-lg"
                                  onError={(e) => {
                                    (e.target as HTMLImageElement).src =
                                      PLACEHOLDER_PRODUCT_URL;
                                  }}
                                />
                              ) : (
                                <div className="w-14 h-14 rounded-lg bg-muted flex items-center justify-center">
                                  <Package className="h-6 w-6 text-muted-foreground" />
                                </div>
                              )}
                              <div className="flex-1 min-w-0">
                                <p className="font-medium text-sm truncate">
                                  {item.product_name}
                                </p>
                                {item.product_sku && (
                                  <p className="text-xs text-muted-foreground">
                                    SKU: {item.product_sku}
                                  </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                  SGD {item.unit_price.toFixed(2)} × {item.quantity}
                                </p>
                              </div>
                              <p className="font-medium text-sm">
                                SGD {item.line_total.toFixed(2)}
                              </p>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Event Bookings */}
                    {selectedOrder.event_bookings && selectedOrder.event_bookings.length > 0 && (
                      <div className="mb-6">
                        <h3 className="text-sm font-medium mb-3">Event Tickets</h3>
                        <div className="space-y-3">
                          {selectedOrder.event_bookings.map((booking) => (
                            <div key={booking.id} className="p-3 rounded-lg bg-muted/20 border border-border/70">
                              <div className="flex items-start justify-between gap-3">
                                <div>
                                  <p className="font-medium text-sm">
                                    {booking.event?.title || 'Event Ticket'}
                                  </p>
                                  <p className="text-xs text-muted-foreground">
                                    {booking.event?.event_date
                                      ? new Date(booking.event.event_date).toLocaleDateString('en-SG', {
                                          year: 'numeric',
                                          month: 'short',
                                          day: 'numeric',
                                        })
                                      : 'Date TBA'}
                                    {booking.event?.venue ? ` • ${booking.event.venue}` : ''}
                                  </p>
                                </div>
                                {statusBadge(booking.state)}
                              </div>

                              <p className="text-xs text-muted-foreground mt-2">
                                Ref: {booking.booking_reference} • {booking.ticket_count} ticket{booking.ticket_count > 1 ? 's' : ''}
                              </p>

                              {booking.tickets?.length > 0 && (
                                <div className="mt-2 rounded-lg bg-background/60 border border-border/60 p-2 space-y-1">
                                  {booking.tickets.map((ticket) => (
                                    <div key={ticket.id} className="flex items-center justify-between text-xs">
                                      <span className="font-mono">{ticket.ticket_code}</span>
                                      <span className="text-muted-foreground capitalize">{ticket.status}</span>
                                    </div>
                                  ))}
                                </div>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Order Totals */}
                    <div className="space-y-2 border-t border-border pt-4">
                      <div className="flex justify-between text-sm">
                        <span className="text-muted-foreground">Subtotal</span>
                        <span>SGD {(selectedOrder.subtotal || 0).toFixed(2)}</span>
                      </div>
                      {(selectedOrder.shipping_cost ?? 0) > 0 && (
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Shipping</span>
                          <span>SGD {selectedOrder.shipping_cost!.toFixed(2)}</span>
                        </div>
                      )}
                      {selectedOrder.shipping_method?.label && (
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Shipping Method</span>
                          <span className="text-right">{selectedOrder.shipping_method.label}</span>
                        </div>
                      )}
                      {(selectedOrder.tax_amount ?? 0) > 0 && (
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Tax</span>
                          <span>SGD {selectedOrder.tax_amount!.toFixed(2)}</span>
                        </div>
                      )}
                      {(selectedOrder.discount_amount ?? 0) > 0 && (
                        <div className="flex justify-between text-sm text-green-500">
                          <span>Discount</span>
                          <span>-SGD {selectedOrder.discount_amount!.toFixed(2)}</span>
                        </div>
                      )}
                      {(selectedOrder.loyalty_discount ?? 0) > 0 && (
                        <div className="flex justify-between text-sm text-green-500">
                          <span>Loyalty Discount</span>
                          <span>-SGD {selectedOrder.loyalty_discount!.toFixed(2)}</span>
                        </div>
                      )}
                      <div className="flex justify-between text-lg font-bold pt-2 border-t border-border">
                        <span>Total</span>
                        <span className="gradient-text">
                          SGD {selectedOrder.total.toFixed(2)} {selectedOrder.currency}
                        </span>
                      </div>
                    </div>

                    {/* Action Buttons for pending/unpaid orders */}
                    {selectedOrder.status === 'pending' && selectedOrder.payment_status?.toLowerCase() !== 'paid' && (
                      <div className="flex gap-3 mt-6 pt-4 border-t border-border">
                        <Button
                          variant="neon"
                          className="flex-1"
                          onClick={() => handleRetryPayment(selectedOrder)}
                          disabled={isSubmitting}
                        >
                          {isSubmitting ? (
                            <Loader2 className="h-4 w-4 animate-spin mr-2" />
                          ) : (
                            <CreditCard className="h-4 w-4 mr-2" />
                          )}
                          Continue to Pay
                        </Button>
                        <Button
                          variant="outline"
                          className="text-red-500 border-red-500/30 hover:bg-red-500/10"
                          onClick={() => handleCancelOrder(selectedOrder.id)}
                          disabled={isSubmitting}
                        >
                          <XCircle className="h-4 w-4 mr-2" />
                          Cancel Order
                        </Button>
                      </div>
                    )}

                    {/* View Invoice Button for paid orders */}
                    {selectedOrder.payment_status?.toLowerCase() === 'paid' && (
                      <div className="flex gap-3 mt-6 pt-4 border-t border-border">
                        <Button
                          variant="outline"
                          className="flex-1"
                          onClick={() => handleViewInvoice(selectedOrder.id)}
                          disabled={invoiceLoading}
                        >
                          {invoiceLoading ? (
                            <Loader2 className="h-4 w-4 animate-spin mr-2" />
                          ) : (
                            <FileText className="h-4 w-4 mr-2" />
                          )}
                          View Invoice
                        </Button>
                      </div>
                    )}
                   </div>
                ) : (
                  /* Order List */
                  <div>
                    <h2 className="text-xl font-display font-bold mb-6 flex items-center gap-2">
                      <Package className="h-5 w-5 text-primary" />
                      My Orders
                    </h2>
                    {ordersLoading ? (
                      <div className="flex items-center justify-center py-12">
                        <Loader2 className="h-8 w-8 animate-spin text-primary" />
                      </div>
                    ) : orders.length === 0 ? (
                      <div className="text-center py-12">
                        <Package className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                        <p className="text-muted-foreground">No orders yet</p>
                        <Button variant="neon" asChild className="mt-4">
                          <Link to={storefrontRoutes.shop}>Start Shopping</Link>
                        </Button>
                      </div>
                    ) : (
                      <div className="space-y-3">
                        {orders.map((order) => (
                          <button
                            key={order.id}
                            onClick={() => viewOrderDetail(order)}
                            className="w-full flex items-center justify-between p-4 rounded-xl bg-muted/30 hover:bg-muted/50 transition-colors text-left"
                          >
                            <div className="flex-1 min-w-0">
                              <p className="font-medium">{order.order_number}</p>
                              <p className="text-sm text-muted-foreground">
                                {new Date(order.created_at).toLocaleDateString('en-SG', {
                                  year: 'numeric',
                                  month: 'short',
                                  day: 'numeric',
                                })}
                              </p>
                            </div>
                            <div className="flex items-center gap-3">
                              <div className="text-right">
                                <p className="font-bold">
                                  SGD {order.total.toFixed(2)}
                                </p>
                                {statusBadge(effectiveOrderStatus(order))}
                              </div>
                              <ChevronRight className="h-4 w-4 text-muted-foreground" />
                            </div>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                {orderDetailLoading && (
                  <div className="fixed inset-0 bg-background/50 flex items-center justify-center z-50">
                    <Loader2 className="h-8 w-8 animate-spin text-primary" />
                  </div>
                )}

                {/* Invoice Modal */}
                {selectedInvoice && (
                  <>
                    <div
                      className="fixed inset-0 bg-background/80 backdrop-blur-sm z-50"
                      onClick={() => setSelectedInvoice(null)}
                    />
                    <div className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[95%] max-w-lg max-h-[90vh] overflow-y-auto z-50 rounded-2xl bg-card border border-border shadow-2xl p-6">
                      <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-display font-bold flex items-center gap-2">
                          <FileText className="h-5 w-5 text-primary" />
                          Invoice {selectedInvoice.invoice_number}
                        </h3>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => setSelectedInvoice(null)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>

                      {/* Invoice Status */}
                      <div className="mb-4">
                        {statusBadge(selectedInvoice.status)}
                      </div>

                      {/* Invoice Details */}
                      <div className="grid grid-cols-2 gap-3 text-sm mb-4">
                        <div>
                          <span className="text-muted-foreground">Invoice Date:</span>
                          <p className="font-medium">
                            {new Date(selectedInvoice.invoice_date).toLocaleDateString('en-SG')}
                          </p>
                        </div>
                        <div>
                          <span className="text-muted-foreground">Due Date:</span>
                          <p className="font-medium">
                            {new Date(selectedInvoice.due_date).toLocaleDateString('en-SG')}
                          </p>
                        </div>
                      </div>

                      {/* Customer Info */}
                      {selectedInvoice.customer && (
                        <div className="p-3 bg-muted/50 rounded-lg mb-4">
                          <p className="font-medium">{selectedInvoice.customer.name}</p>
                          {selectedInvoice.customer.email && (
                            <p className="text-sm text-muted-foreground">{selectedInvoice.customer.email}</p>
                          )}
                          {selectedInvoice.customer.phone && (
                            <p className="text-sm text-muted-foreground">{selectedInvoice.customer.phone}</p>
                          )}
                        </div>
                      )}

                      {/* Line Items */}
                      {selectedInvoice.lines && selectedInvoice.lines.length > 0 && (
                        <div className="mb-4">
                          <h4 className="font-medium mb-2 text-sm">Items</h4>
                          <div className="space-y-2">
                            {selectedInvoice.lines.map((line) => (
                              <div key={line.id} className="flex justify-between text-sm p-2 rounded bg-muted/50">
                                <div>
                                  <p className="font-medium">{line.description}</p>
                                  <p className="text-xs text-muted-foreground">
                                    {line.quantity} x SGD {line.unit_price.toFixed(2)}
                                  </p>
                                </div>
                                <span className="font-medium">SGD {line.line_total.toFixed(2)}</span>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      {/* Totals */}
                      <div className="space-y-2 border-t pt-4">
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Subtotal</span>
                          <span>SGD {selectedInvoice.subtotal.toFixed(2)}</span>
                        </div>
                        {selectedInvoice.tax_amount > 0 && (
                          <div className="flex justify-between text-sm">
                            <span className="text-muted-foreground">Tax</span>
                            <span>SGD {selectedInvoice.tax_amount.toFixed(2)}</span>
                          </div>
                        )}
                        {selectedInvoice.discount_amount > 0 && (
                          <div className="flex justify-between text-sm text-green-500">
                            <span>Discount</span>
                            <span>-SGD {selectedInvoice.discount_amount.toFixed(2)}</span>
                          </div>
                        )}
                        <div className="flex justify-between font-bold text-lg border-t pt-2">
                          <span>Total</span>
                          <span>SGD {selectedInvoice.total.toFixed(2)}</span>
                        </div>
                        {selectedInvoice.paid_amount > 0 && (
                          <div className="flex justify-between text-green-500">
                            <span>Paid</span>
                            <span>SGD {selectedInvoice.paid_amount.toFixed(2)}</span>
                          </div>
                        )}
                        {selectedInvoice.balance > 0 && (
                          <div className="flex justify-between text-orange-500">
                            <span>Balance Due</span>
                            <span>SGD {selectedInvoice.balance.toFixed(2)}</span>
                          </div>
                        )}
                      </div>

                      {/* Notes */}
                      {selectedInvoice.notes && (
                        <div className="mt-4 p-3 bg-muted/30 rounded-lg">
                          <h4 className="font-medium mb-1 text-sm">Notes</h4>
                          <p className="text-xs text-muted-foreground">{selectedInvoice.notes}</p>
                        </div>
                      )}

                      <div className="flex gap-3 mt-6">
                        <Button
                          variant="outline"
                          className="flex-1"
                          onClick={() => setSelectedInvoice(null)}
                        >
                          Close
                        </Button>
                      </div>
                    </div>
                  </>
                )}
              </div>
            )}

            {/* ━━━ TICKETS TAB ━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {activeTab === 'tickets' && (
              <div className="glass-strong rounded-2xl p-6">
                <h2 className="text-xl font-display font-bold mb-6 flex items-center gap-2">
                  <Ticket className="h-5 w-5 text-primary" />
                  My Event Tickets
                </h2>

                {ticketsLoading ? (
                  <div className="flex items-center justify-center py-12">
                    <Loader2 className="h-8 w-8 animate-spin text-primary" />
                  </div>
                ) : tickets.length === 0 ? (
                  <div className="text-center py-12">
                    <Ticket className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <p className="text-muted-foreground">No event tickets yet</p>
                    <p className="text-sm text-muted-foreground mt-1">
                      Tickets from your confirmed event purchases will appear here.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-3">
                    {tickets.map((booking) => (
                      <div
                        key={booking.id}
                        className="relative overflow-hidden rounded-2xl border border-primary/15 bg-gradient-to-br from-primary/10 via-background to-background p-4 shadow-sm space-y-3"
                      >
                        <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-primary via-fuchsia-500 to-amber-400" />

                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="text-[11px] uppercase tracking-[0.2em] text-muted-foreground">My Event Tickets</p>
                            <p className="mt-1 font-display text-lg font-bold leading-tight">
                              {booking.event_title || 'Event Ticket'}
                            </p>
                            <p className="text-sm text-muted-foreground mt-1">
                              {booking.event_date
                                ? new Date(booking.event_date).toLocaleDateString('en-SG', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                  })
                                : 'Date TBA'}
                              {booking.event_venue ? ` • ${booking.event_venue}` : ''}
                            </p>
                          </div>
                          <div className="shrink-0 text-right">
                            {statusBadge(booking.status)}
                            <p className="mt-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-primary">
                              {getCountdownText(booking.event_date)}
                            </p>
                          </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                          <div className="rounded-xl bg-background/70 border border-border/70 p-3">
                            <p className="text-[11px] uppercase tracking-[0.2em] text-muted-foreground">Booking Ref</p>
                            <p className="mt-1 font-mono text-sm font-semibold">{booking.booking_reference}</p>
                            <p className="mt-1 text-xs text-muted-foreground">
                              {booking.ticket_count} ticket{booking.ticket_count > 1 ? 's' : ''}
                            </p>
                          </div>
                          <div className="rounded-xl bg-primary/10 border border-primary/20 p-3 text-right sm:min-w-[120px]">
                            <p className="text-[11px] uppercase tracking-[0.2em] text-primary/80">Countdown</p>
                            <p className="mt-1 text-sm font-bold text-primary">
                              {getCountdownText(booking.event_date)}
                            </p>
                          </div>
                        </div>

                        {booking.tickets?.length > 0 && (
                          <div className="rounded-xl bg-background/70 border border-border/70 p-3">
                            <p className="text-xs font-medium mb-2 uppercase tracking-[0.2em] text-muted-foreground">Issued Ticket IDs</p>
                            <div className="grid gap-2 sm:grid-cols-2">
                              {booking.tickets.map((ticket) => (
                                <div key={ticket.id} className="flex items-center justify-between rounded-lg border border-border/60 bg-background px-3 py-2 text-xs">
                                  <span className="font-mono font-semibold tracking-wide">{ticket.ticket_code}</span>
                                  <span className="text-muted-foreground capitalize">{ticket.status}</span>
                                </div>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* ━━━ ADDRESSES TAB ━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {activeTab === 'addresses' && (
              <div className="glass-strong rounded-2xl p-6">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-xl font-display font-bold flex items-center gap-2">
                    <MapPin className="h-5 w-5 text-primary" />
                    My Addresses
                  </h2>
                  <Button variant="neon" size="sm" onClick={() => openAddressForm()}>
                    <Plus className="h-4 w-4 mr-1" />
                    Add Address
                  </Button>
                </div>

                {addressesLoading ? (
                  <div className="flex items-center justify-center py-12">
                    <Loader2 className="h-8 w-8 animate-spin text-primary" />
                  </div>
                ) : addresses.length === 0 ? (
                  <div className="text-center py-12">
                    <MapPin className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <p className="text-muted-foreground mb-2">No addresses saved</p>
                    <p className="text-sm text-muted-foreground">
                      Add your shipping and billing addresses for faster checkout
                    </p>
                  </div>
                ) : (
                  <div className="grid sm:grid-cols-2 gap-4">
                    {addresses.map((addr) => (
                      <div
                        key={addr.id}
                        className={cn(
                          'relative p-4 rounded-xl border',
                          addr.is_default
                            ? 'border-primary/40 bg-primary/5'
                            : 'border-border bg-muted/20',
                        )}
                      >
                        {addr.is_default && (
                          <span className="absolute top-3 right-3 text-[10px] bg-primary text-primary-foreground px-2 py-0.5 rounded-full">
                            Default
                          </span>
                        )}
                        <p className="text-xs uppercase tracking-wider text-muted-foreground mb-1 font-medium">
                          {addr.type}
                        </p>
                        <p className="font-medium">{addr.name}</p>
                        {addr.company && (
                          <p className="text-sm text-muted-foreground">{addr.company}</p>
                        )}
                        <p className="text-sm text-muted-foreground mt-1">
                          {addr.address}
                          <br />
                          {addr.city}
                          {addr.state ? `, ${addr.state}` : ''} {addr.postal_code}
                          <br />
                          {addr.country}
                        </p>
                        {addr.phone && (
                          <p className="text-sm text-muted-foreground mt-1">{addr.phone}</p>
                        )}

                        <div className="flex gap-2 mt-3">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openAddressForm(addr)}
                          >
                            <Pencil className="h-3 w-3 mr-1" />
                            Edit
                          </Button>
                          {!addr.is_default && (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleSetDefault(addr.id)}
                            >
                              <Star className="h-3 w-3 mr-1" />
                              Default
                            </Button>
                          )}
                          <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10"
                            onClick={() => handleDeleteAddress(addr.id)}
                          >
                            <Trash2 className="h-3 w-3" />
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {/* Address Modal */}
                {addressModal.open && (
                  <>
                    <div
                      className="fixed inset-0 bg-background/80 backdrop-blur-sm z-50"
                      onClick={() => setAddressModal({ open: false, editing: null })}
                    />
                    <div className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[95%] max-w-lg max-h-[90vh] overflow-y-auto z-50 rounded-2xl bg-card border border-border shadow-2xl p-6">
                      <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-display font-bold">
                          {addressModal.editing ? 'Edit Address' : 'Add Address'}
                        </h3>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => setAddressModal({ open: false, editing: null })}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                      <form onSubmit={handleSaveAddress} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                          {(['shipping', 'billing'] as const).map((t) => (
                            <label
                              key={t}
                              className={cn(
                                'flex items-center justify-center gap-2 p-3 rounded-xl border cursor-pointer capitalize transition-colors',
                                addressForm.type === t
                                  ? 'border-primary bg-primary/10 text-primary'
                                  : 'border-border',
                              )}
                            >
                              <input
                                type="radio"
                                name="addrType"
                                value={t}
                                checked={addressForm.type === t}
                                onChange={() =>
                                  setAddressForm({ ...addressForm, type: t })
                                }
                                className="sr-only"
                              />
                              {t}
                            </label>
                          ))}
                        </div>

                        <div>
                          <Label>Label / Name</Label>
                          <Input
                            value={addressForm.name}
                            onChange={(e) =>
                              setAddressForm({ ...addressForm, name: e.target.value })
                            }
                            required
                            placeholder="e.g. Home, Office"
                          />
                        </div>

                        <div>
                          <Label>Address</Label>
                          <Input
                            value={addressForm.address}
                            onChange={(e) =>
                              setAddressForm({ ...addressForm, address: e.target.value })
                            }
                            required
                            placeholder="Block, Street"
                          />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <Label>City</Label>
                            <Input
                              value={addressForm.city}
                              onChange={(e) =>
                                setAddressForm({ ...addressForm, city: e.target.value })
                              }
                              required
                            />
                          </div>
                          <div>
                            <Label>Postal Code</Label>
                            <Input
                              value={addressForm.postal_code}
                              onChange={(e) =>
                                setAddressForm({
                                  ...addressForm,
                                  postal_code: e.target.value,
                                })
                              }
                              required
                            />
                          </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <Label>State (optional)</Label>
                            <Input
                              value={addressForm.state}
                              onChange={(e) =>
                                setAddressForm({ ...addressForm, state: e.target.value })
                              }
                            />
                          </div>
                          <div>
                            <Label>Country</Label>
                            <Input
                              value={addressForm.country}
                              onChange={(e) =>
                                setAddressForm({ ...addressForm, country: e.target.value })
                              }
                              required
                            />
                          </div>
                        </div>

                        <div>
                          <Label>Phone (optional)</Label>
                          <Input
                            value={addressForm.phone}
                            onChange={(e) =>
                              setAddressForm({ ...addressForm, phone: e.target.value })
                            }
                            placeholder="+65 XXXX XXXX"
                          />
                        </div>

                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={addressForm.is_default}
                            onChange={(e) =>
                              setAddressForm({
                                ...addressForm,
                                is_default: e.target.checked,
                              })
                            }
                            className="accent-primary"
                          />
                          <span className="text-sm">Set as default address</span>
                        </label>

                        <div className="flex gap-3 pt-2">
                          <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                              setAddressModal({ open: false, editing: null })
                            }
                          >
                            Cancel
                          </Button>
                          <Button
                            variant="hero"
                            type="submit"
                            className="flex-1"
                            disabled={addressSaving}
                          >
                            {addressSaving ? (
                              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                            ) : (
                              <Save className="h-4 w-4 mr-2" />
                            )}
                            {addressModal.editing ? 'Update' : 'Save'} Address
                          </Button>
                        </div>
                      </form>
                    </div>
                  </>
                )}
              </div>
            )}

            {/* ━━━ WISHLIST TAB ━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {activeTab === 'wishlist' && (
              <div className="glass-strong rounded-2xl p-6">
                <h2 className="text-xl font-display font-bold mb-6 flex items-center gap-2">
                  <Heart className="h-5 w-5 text-primary" />
                  My Wishlist
                </h2>
                {wishlistItems.length === 0 ? (
                  <div className="text-center py-12">
                    <Heart className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <p className="text-muted-foreground">Your wishlist is empty</p>
                    <Button variant="neon" asChild className="mt-4">
                      <Link to={storefrontRoutes.shop}>Discover Products</Link>
                    </Button>
                  </div>
                ) : (
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {wishlistItems.map((product) => (
                      <ProductCard key={product.id} product={product} />
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default Account;
