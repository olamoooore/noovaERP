import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  CreditCard,
  MapPin,
  Copy,
  Check,
  Loader2,
  ExternalLink,
  ShieldCheck,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCart } from '@/storefront/context/CartContext';
import { useCustomerAuth } from '@/storefront/context/CustomerAuthContext';
import { toast } from 'sonner';
import { storefrontRoutes } from '@/storefront/lib/paths';
import {
  guestCheckout,
  CheckoutPayload,
  CheckoutResponse,
  getPaymentGateways,
  confirmPayment,
  getCustomerAddresses,
  CustomerAddress,
} from '@/storefront/lib/api';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import {
  Elements,
  PaymentElement,
  useStripe,
  useElements,
} from '@stripe/react-stripe-js';
import { cn } from '@/lib/utils';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';

// ── Types ──────────────────────────────────────────────────────────
type Step = 'details' | 'stripe-payment' | 'redirect-payment' | 'processing' | 'success';

interface Gateway {
  id: number;
  name: string;
  gateway: string;
  display_name: string;
  is_test_mode?: boolean;
}

// ── Stripe Payment Form (requires <Elements> wrapper) ──────────────
function StripePaymentForm({
  onSuccess,
  onBack,
}: {
  onSuccess: () => void;
  onBack: () => void;
}) {
  const stripe = useStripe();
  const elements = useElements();
  const [isProcessing, setIsProcessing] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!stripe || !elements) return;
    setIsProcessing(true);
    setErrorMsg(null);

    const { error, paymentIntent } = await stripe.confirmPayment({
      elements,
      redirect: 'if_required',
    });

    if (error) {
      setErrorMsg(error.message || 'Payment failed');
      setIsProcessing(false);
    } else if (
      paymentIntent?.status === 'succeeded' ||
      paymentIntent?.status === 'processing'
    ) {
      onSuccess();
    } else {
      setErrorMsg('Payment was not completed. Please try again.');
      setIsProcessing(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="flex items-center gap-2 text-lg font-medium mb-2">
        <CreditCard className="h-5 w-5 text-primary" />
        <span>Enter Payment Details</span>
      </div>

      <PaymentElement options={{ layout: 'tabs' }} />

      {errorMsg && (
        <div className="p-3 rounded-lg bg-destructive/10 text-destructive text-sm">
          {errorMsg}
        </div>
      )}

      <div className="flex gap-3">
        <Button type="button" variant="outline" onClick={onBack} disabled={isProcessing}>
          Back
        </Button>
        <Button
          variant="hero"
          type="submit"
          className="flex-1"
          disabled={!stripe || isProcessing}
        >
          {isProcessing ? (
            <>
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
              Processing…
            </>
          ) : (
            <>
              <ShieldCheck className="h-4 w-4 mr-2" />
              Pay Securely
            </>
          )}
        </Button>
      </div>
    </form>
  );
}

// ── Gateway label helper ───────────────────────────────────────────
function gatewayDescription(gateway: string) {
  switch (gateway) {
    case 'stripe':
      return 'Credit / Debit Card';
    case 'hitpay':
      return 'HitPay (PayNow, GrabPay, Cards)';
    case 'paypal':
      return 'PayPal';
    default:
      return 'Online Payment';
  }
}

// ── Main Checkout Component ────────────────────────────────────────
const Checkout = () => {
  const { items, totalPrice, clearCart } = useCart();
  const { customer, token, isAuthenticated } = useCustomerAuth();

  // Steps
  const [step, setStep] = useState<Step>('details');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [copied, setCopied] = useState(false);

  // Payment
  const [gateways, setGateways] = useState<Gateway[]>([]);
  const [selectedGatewayId, setSelectedGatewayId] = useState<number | null>(null);
  const [orderData, setOrderData] = useState<CheckoutResponse | null>(null);
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null);
  const orderDataRef = useRef<CheckoutResponse | null>(null);

  // Saved addresses
  const [savedAddresses, setSavedAddresses] = useState<CustomerAddress[]>([]);
  const [selectedAddressId, setSelectedAddressId] = useState<number | 'new' | null>(null);
  const [addressesLoaded, setAddressesLoaded] = useState(false);

  // Form
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    address: '',
    city: 'Singapore',
    postalCode: '',
    unit: '',
  });

  // Keep ref in sync for event listener closures
  useEffect(() => {
    orderDataRef.current = orderData;
  }, [orderData]);

  // Pre-fill email/phone from customer profile
  useEffect(() => {
    if (isAuthenticated && customer) {
      setFormData((prev) => ({
        ...prev,
        email: prev.email || customer.email || '',
        phone: prev.phone || customer.phone || '',
        firstName: prev.firstName || customer.first_name || customer.name?.split(' ')[0] || '',
        lastName: prev.lastName || customer.last_name || customer.name?.split(' ').slice(1).join(' ') || '',
      }));
    }
  }, [isAuthenticated, customer]);

  // Load saved addresses for logged-in users
  useEffect(() => {
    if (isAuthenticated && token && !addressesLoaded) {
      getCustomerAddresses(token)
        .then((addrs) => {
          setSavedAddresses(addrs);
          setAddressesLoaded(true);
          // Auto-select default or first shipping address
          const defaultAddr = addrs.find((a) => a.is_default && a.type === 'shipping')
            || addrs.find((a) => a.type === 'shipping')
            || addrs[0];
          if (defaultAddr) {
            setSelectedAddressId(defaultAddr.id);
            applyAddress(defaultAddr);
          } else {
            setSelectedAddressId('new');
          }
        })
        .catch(() => {
          setAddressesLoaded(true);
          setSelectedAddressId('new');
        });
    }
  }, [isAuthenticated, token, addressesLoaded]);

  // Apply a saved address to the form
  const applyAddress = (addr: CustomerAddress) => {
    setFormData((prev) => ({
      ...prev,
      // Use customer profile for name/email, not the address label
      firstName: prev.firstName || customer?.first_name || customer?.name?.split(' ')[0] || '',
      lastName: prev.lastName || customer?.last_name || customer?.name?.split(' ').slice(1).join(' ') || '',
      email: prev.email || customer?.email || '',
      address: addr.address || '',
      city: addr.city || 'Singapore',
      postalCode: addr.postal_code || '',
      unit: '',
      phone: prev.phone || addr.phone || customer?.phone || '',
    }));
  };

  // Handle address selection change
  const handleSelectAddress = (id: number | 'new') => {
    setSelectedAddressId(id);
    if (id === 'new') {
      setFormData((prev) => ({
        ...prev,
        address: '',
        city: 'Singapore',
        postalCode: '',
        unit: '',
      }));
    } else {
      const addr = savedAddresses.find((a) => a.id === id);
      if (addr) applyAddress(addr);
    }
  };

  // Fetch available payment gateways
  useEffect(() => {
    getPaymentGateways()
      .then((data) => {
        setGateways(data || []);
        if (data && data.length > 0) setSelectedGatewayId(data[0].id);
      })
      .catch(() => {});
  }, []);

  // Listen for external payment-window messages (HitPay / PayPal callback)
  useEffect(() => {
    const handleMessage = async (event: MessageEvent) => {
      if (event.data?.type === 'PAYMENT_COMPLETE') {
        await doConfirmPayment();
        clearCart();
        setStep('success');
        toast.success('Payment completed successfully!');
      } else if (event.data?.type === 'PAYMENT_FAILED') {
        toast.error('Payment failed. Please try again.');
        setStep('redirect-payment');
      }
    };
    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Helpers ──────────────────────────────────────────────────────
  const doConfirmPayment = async () => {
    const od = orderDataRef.current;
    if (!od || !od.payment_intent?.transaction_id) return;
    try {
      await confirmPayment({
        order_id: od.order_id,
        payment_intent_id: od.payment_intent.transaction_id,
        guest_token: od.guest_token,
      });
    } catch {
      // Backend webhook may have already confirmed
    }
  };

  const handlePaymentComplete = async () => {
    await doConfirmPayment();
    clearCart();
    setStep('success');
    toast.success('Payment completed successfully!');
  };

  // ── Empty cart guard ─────────────────────────────────────────────
  if (items.length === 0 && step !== 'success') {
    return (
      <Layout>
        <div className="container py-16 text-center">
          <h1 className="text-2xl font-display font-bold mb-4">Your cart is empty</h1>
          <Button variant="neon" asChild>
            <Link to={storefrontRoutes.shop}>Continue Shopping</Link>
          </Button>
        </div>
      </Layout>
    );
  }

  // ── Submit shipping details + place order ────────────────────────
  const handleSubmitDetails = async (e: React.FormEvent) => {
    e.preventDefault();
    if (gateways.length > 0 && !selectedGatewayId) {
      toast.error('Please select a payment method');
      return;
    }
    setIsSubmitting(true);

    try {
      const payload: CheckoutPayload = {
        email: formData.email || customer?.email || '',
        phone: formData.phone || customer?.phone || undefined,
        shipping_address: {
          first_name: formData.firstName || customer?.first_name || customer?.name?.split(' ')[0] || '',
          last_name: formData.lastName || customer?.last_name || customer?.name?.split(' ').slice(1).join(' ') || '',
          address: `${formData.address}${formData.unit ? `, ${formData.unit}` : ''}`,
          city: formData.city || 'Singapore',
          postal_code: formData.postalCode,
          country: 'Singapore',
        },
        items: items.map((item) => ({
          product_id: parseInt(item.product.id),
          quantity: item.quantity,
        })),
        gateway_id: selectedGatewayId || undefined,
      };

      const response = await guestCheckout(payload);
      setOrderData(response);
      orderDataRef.current = response;

      const pi = response.payment_intent;

      if (pi?.client_secret && pi?.publishable_key) {
        // Stripe → show Elements
        setStripePromise(loadStripe(pi.publishable_key));
        setStep('stripe-payment');
      } else if (pi?.payment_url) {
        // HitPay / PayPal → redirect
        setStep('redirect-payment');
      } else if (response.success) {
        // No payment needed
        clearCart();
        setStep('success');
      } else {
        throw new Error(response.message || 'Checkout failed');
      }
    } catch (error: any) {
      console.error('Checkout error:', error);
      console.error('Checkout response:', JSON.stringify(error.response?.data, null, 2));
      const errData = error.response?.data;

      if (errData?.error === 'Stock issues found') {
        const issues = (errData.items || [])
          .map((i: any) => `${i.product}: only ${i.available} left`)
          .join(', ');
        toast.error(`Stock issue: ${issues || 'Some items are out of stock'}`);
      } else if (errData?.errors) {
        const msgs = Object.values(errData.errors).flat().join('\n');
        toast.error(msgs || 'Validation failed');
      } else if (errData?.error === 'Payment initialization failed') {
        toast.error(
          `Payment setup failed: ${errData?.message || 'No payment gateway configured. Please contact the store.'}`,
        );
      } else {
        toast.error(
          errData?.error || errData?.message || error.message || 'Failed to process checkout',
        );
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // ── Open payment in popup (HitPay / PayPal) ─────────────────────
  const handleOpenPayment = () => {
    const url = orderData?.payment_intent?.payment_url;
    if (url) {
      const w = 500;
      const h = 700;
      const left = window.screen.width / 2 - w / 2;
      const top = window.screen.height / 2 - h / 2;
      window.open(url, 'PaymentWindow', `width=${w},height=${h},left=${left},top=${top},scrollbars=yes`);
      setStep('processing');
    }
  };

  const copyLink = () => {
    const url = orderData?.payment_intent?.payment_url;
    if (url) {
      navigator.clipboard.writeText(url);
      setCopied(true);
      toast.success('Payment link copied!');
      setTimeout(() => setCopied(false), 2000);
    }
  };

  // ── Order Summary Sidebar ───────────────────────────────────────
  const orderSummary = (
    <div className="lg:col-span-1">
      <div className="glass-strong rounded-2xl p-6 sticky top-24">
        <h2 className="text-lg font-display font-bold mb-4">Order Summary</h2>

        <div className="space-y-3 mb-6">
          {items.map((item) => (
            <div key={item.product.id} className="flex gap-3">
              <img
                src={item.product.image}
                alt={item.product.title}
                className="w-16 h-16 object-cover rounded-lg"
                onError={(e) => {
                  (e.target as HTMLImageElement).src = PLACEHOLDER_PRODUCT_URL;
                }}
              />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{item.product.title}</p>
                <p className="text-xs text-muted-foreground">Qty: {item.quantity}</p>
                <p className="text-sm text-primary font-medium">
                  SGD {(item.product.price * item.quantity).toFixed(2)}
                </p>
              </div>
            </div>
          ))}
        </div>

        <div className="space-y-2 border-t border-border pt-4">
          <div className="flex justify-between text-sm">
            <span className="text-muted-foreground">Subtotal</span>
            <span>SGD {totalPrice.toFixed(2)}</span>
          </div>
          <div className="flex justify-between text-lg font-display font-bold pt-2 border-t border-border">
            <span>Total</span>
            <span className="gradient-text">SGD {totalPrice.toFixed(2)}</span>
          </div>
        </div>
      </div>
    </div>
  );

  // ── Render ──────────────────────────────────────────────────────
  return (
    <Layout>
      <div className="container py-8 md:py-12">
        {/* Back button */}
        {step !== 'success' && (
          <Button variant="ghost" className="mb-6" asChild>
            <Link to={storefrontRoutes.shop}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Continue Shopping
            </Link>
          </Button>
        )}

        {/* ─── SUCCESS ──────────────────────────────────────────── */}
        {step === 'success' ? (
          <div className="max-w-lg mx-auto text-center py-12">
            <div className="w-24 h-24 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center mx-auto mb-6">
              <Check className="h-12 w-12 text-primary-foreground" />
            </div>
            <h1 className="text-3xl font-display font-bold mb-2">Order Confirmed!</h1>
            {orderData?.order_number && (
              <p className="text-primary font-medium mb-2">Order #{orderData.order_number}</p>
            )}
            <p className="text-muted-foreground mb-8">
              Thank you for your purchase! You will receive a confirmation email shortly.
            </p>
            <Button variant="hero" asChild>
              <Link to={storefrontRoutes.shop}>Continue Shopping</Link>
            </Button>
          </div>
        ) : (
          <div className="grid lg:grid-cols-3 gap-8">
            {/* ── Main Column ──────────────────────────────────── */}
            <div className="lg:col-span-2">
              <div className="glass-strong rounded-2xl p-6 md:p-8">
                {/* ─── STEP: Shipping + Gateway Selection ─────── */}
                {step === 'details' && (
                  <>
                    <h1 className="text-2xl font-display font-bold mb-6">Checkout</h1>
                    <form onSubmit={handleSubmitDetails} className="space-y-8">
                      {/* Shipping Details */}
                      <div>
                        <h2 className="text-lg font-medium mb-4 flex items-center gap-2">
                          <MapPin className="h-5 w-5 text-primary" />
                          Shipping Details
                        </h2>

                        {/* Saved Address Selector (logged-in users) */}
                        {isAuthenticated && savedAddresses.length > 0 && (
                          <div className="mb-6">
                            <p className="text-sm text-muted-foreground mb-3">Select a saved address or add a new one</p>
                            <div className="grid sm:grid-cols-2 gap-3">
                              {savedAddresses.map((addr) => (
                                <label
                                  key={addr.id}
                                  className={cn(
                                    'relative flex flex-col p-4 rounded-xl border cursor-pointer transition-colors',
                                    selectedAddressId === addr.id
                                      ? 'border-primary bg-primary/5'
                                      : 'border-border hover:border-primary/50',
                                  )}
                                >
                                  <input
                                    type="radio"
                                    name="savedAddress"
                                    value={addr.id}
                                    checked={selectedAddressId === addr.id}
                                    onChange={() => handleSelectAddress(addr.id)}
                                    className="sr-only"
                                  />
                                  {addr.is_default && (
                                    <span className="absolute top-2 right-2 text-[10px] bg-primary text-primary-foreground px-1.5 py-0.5 rounded-full">Default</span>
                                  )}
                                  <span className="text-xs uppercase tracking-wider text-muted-foreground font-medium">{addr.type}</span>
                                  <span className="font-medium text-sm mt-1">{addr.name}</span>
                                  <span className="text-xs text-muted-foreground mt-0.5">
                                    {addr.address}, {addr.city} {addr.postal_code}
                                  </span>
                                </label>
                              ))}
                              {/* Add New option */}
                              <label
                                className={cn(
                                  'flex flex-col items-center justify-center p-4 rounded-xl border cursor-pointer transition-colors gap-1 min-h-[90px]',
                                  selectedAddressId === 'new'
                                    ? 'border-primary bg-primary/5'
                                    : 'border-dashed border-border hover:border-primary/50',
                                )}
                              >
                                <input
                                  type="radio"
                                  name="savedAddress"
                                  value="new"
                                  checked={selectedAddressId === 'new'}
                                  onChange={() => handleSelectAddress('new')}
                                  className="sr-only"
                                />
                                <MapPin className="h-5 w-5 text-muted-foreground" />
                                <span className="text-sm font-medium">New Address</span>
                              </label>
                            </div>
                          </div>
                        )}

                        {/* Only render manual form when no saved address is selected */}
                        {!(isAuthenticated && savedAddresses.length > 0 && selectedAddressId !== 'new') && (
                        <div className="space-y-4">
                          <div className="grid sm:grid-cols-2 gap-4">
                            <div>
                              <Label htmlFor="firstName">First Name</Label>
                              <Input
                                id="firstName"
                                value={formData.firstName}
                                onChange={(e) => setFormData({ ...formData, firstName: e.target.value })}
                                required
                                placeholder="First name"
                              />
                            </div>
                            <div>
                              <Label htmlFor="lastName">Last Name</Label>
                              <Input
                                id="lastName"
                                value={formData.lastName}
                                onChange={(e) => setFormData({ ...formData, lastName: e.target.value })}
                                required
                                placeholder="Last name"
                              />
                            </div>
                          </div>

                          <div className="grid sm:grid-cols-2 gap-4">
                            <div>
                              <Label htmlFor="email">Email</Label>
                              <Input
                                id="email"
                                type="email"
                                value={formData.email}
                                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                required
                                placeholder="your@email.com"
                              />
                            </div>
                            <div>
                              <Label htmlFor="phone">Phone</Label>
                              <Input
                                id="phone"
                                type="tel"
                                value={formData.phone}
                                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                                placeholder="+65 XXXX XXXX (optional)"
                              />
                            </div>
                          </div>

                          <div>
                            <Label htmlFor="address">Delivery Address</Label>
                            <Input
                              id="address"
                              value={formData.address}
                              onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                              required
                              placeholder="Block, Street Name"
                            />
                          </div>

                          <div className="grid sm:grid-cols-3 gap-4">
                            <div>
                              <Label htmlFor="unit">Unit Number</Label>
                              <Input
                                id="unit"
                                value={formData.unit}
                                onChange={(e) => setFormData({ ...formData, unit: e.target.value })}
                                placeholder="#XX-XXX (optional)"
                              />
                            </div>
                            <div>
                              <Label htmlFor="city">City</Label>
                              <Input
                                id="city"
                                value={formData.city}
                                onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                                required
                                placeholder="Singapore"
                              />
                            </div>
                            <div>
                              <Label htmlFor="postalCode">Postal Code</Label>
                              <Input
                                id="postalCode"
                                value={formData.postalCode}
                                onChange={(e) => setFormData({ ...formData, postalCode: e.target.value })}
                                required
                                placeholder="XXXXXX"
                              />
                            </div>
                          </div>
                        </div>
                        )}
                      </div>

                      {/* Payment Method Selection */}
                      {gateways.length > 0 && (
                        <div>
                          <h2 className="text-lg font-medium mb-4 flex items-center gap-2">
                            <CreditCard className="h-5 w-5 text-primary" />
                            Payment Method
                          </h2>
                          <div className="space-y-2">
                            {gateways.map((gw) => (
                              <label
                                key={gw.id}
                                className={cn(
                                  'flex items-center gap-3 p-4 rounded-xl border cursor-pointer transition-colors',
                                  selectedGatewayId === gw.id
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border hover:border-primary/50',
                                )}
                              >
                                <input
                                  type="radio"
                                  name="gateway"
                                  value={gw.id}
                                  checked={selectedGatewayId === gw.id}
                                  onChange={() => setSelectedGatewayId(gw.id)}
                                  className="accent-primary"
                                />
                                <div className="flex-1">
                                  <p className="font-medium capitalize">{gw.name || gw.gateway}</p>
                                  <p className="text-xs text-muted-foreground">
                                    {gatewayDescription(gw.gateway)}
                                  </p>
                                </div>
                                <CreditCard className="h-5 w-5 text-muted-foreground" />
                              </label>
                            ))}
                          </div>
                        </div>
                      )}

                      {gateways.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                          Loading payment methods…
                        </p>
                      )}

                      <Button
                        variant="hero"
                        type="submit"
                        className="w-full"
                        disabled={isSubmitting}
                      >
                        {isSubmitting ? (
                          <>
                            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                            Processing…
                          </>
                        ) : (
                          <>
                            <CreditCard className="h-4 w-4 mr-2" />
                            Place Order &amp; Pay
                          </>
                        )}
                      </Button>
                    </form>
                  </>
                )}

                {/* ─── STEP: Stripe Payment ────────────────────── */}
                {step === 'stripe-payment' &&
                  stripePromise &&
                  orderData?.payment_intent?.client_secret && (
                    <Elements
                      stripe={stripePromise}
                      options={{
                        clientSecret: orderData.payment_intent.client_secret,
                        appearance: {
                          theme: 'night',
                          variables: { colorPrimary: '#a855f7', borderRadius: '12px' },
                        },
                      }}
                    >
                      <StripePaymentForm
                        onSuccess={handlePaymentComplete}
                        onBack={() => setStep('details')}
                      />
                    </Elements>
                  )}

                {/* ─── STEP: Redirect Payment (HitPay / PayPal) ── */}
                {step === 'redirect-payment' && (
                  <div className="space-y-6">
                    {/* Address summary */}
                    <div className="p-4 rounded-xl bg-muted/50">
                      <div className="flex items-start gap-3">
                        <MapPin className="h-5 w-5 text-primary mt-0.5" />
                        <div>
                          <p className="font-medium">
                            {formData.firstName} {formData.lastName}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {formData.address}
                            {formData.unit && `, ${formData.unit}`}, {formData.city}{' '}
                            {formData.postalCode}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {formData.email}
                            {formData.phone && ` · ${formData.phone}`}
                          </p>
                        </div>
                      </div>
                    </div>

                    <div className="space-y-4">
                      <div className="flex items-center gap-2 text-lg font-medium">
                        <CreditCard className="h-5 w-5 text-primary" />
                        <span>Complete Payment</span>
                      </div>
                      <p className="text-muted-foreground">
                        Click below to complete your payment securely. You can also copy the
                        payment link.
                      </p>

                      {orderData?.payment_intent?.payment_url && (
                        <div className="p-4 rounded-xl bg-muted/50 flex items-center gap-3">
                          <Input
                            value={orderData.payment_intent.payment_url}
                            readOnly
                            className="bg-background text-sm"
                          />
                          <Button variant="outline" size="icon" onClick={copyLink}>
                            {copied ? (
                              <Check className="h-4 w-4" />
                            ) : (
                              <Copy className="h-4 w-4" />
                            )}
                          </Button>
                        </div>
                      )}

                      <div className="flex gap-4">
                        <Button variant="outline" onClick={() => setStep('details')}>
                          Back
                        </Button>
                        <Button variant="hero" className="flex-1" onClick={handleOpenPayment}>
                          <ExternalLink className="h-4 w-4 mr-2" />
                          Pay Now
                        </Button>
                      </div>
                    </div>
                  </div>
                )}

                {/* ─── STEP: Processing ────────────────────────── */}
                {step === 'processing' && (
                  <div className="text-center py-8 space-y-6">
                    <Loader2 className="h-12 w-12 mx-auto animate-spin text-primary" />
                    <div>
                      <h2 className="text-xl font-display font-bold mb-2">
                        Processing Payment
                      </h2>
                      <p className="text-muted-foreground">
                        Please complete your payment in the popup window.
                      </p>
                    </div>
                    <div className="flex gap-4 justify-center">
                      <Button variant="outline" onClick={() => setStep('redirect-payment')}>
                        Cancel
                      </Button>
                      <Button variant="hero" onClick={handlePaymentComplete}>
                        I've Completed Payment
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* ── Order Summary Sidebar ──────────────────────── */}
            {orderSummary}
          </div>
        )}
      </div>
    </Layout>
  );
};

export default Checkout;
