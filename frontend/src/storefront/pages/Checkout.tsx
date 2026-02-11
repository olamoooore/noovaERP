import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, CreditCard, Truck, MapPin, Copy, Check } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCart } from '@/storefront/context/CartContext';
import { toast } from 'sonner';
import { storefrontRoutes } from '@/storefront/lib/paths';

const DELIVERY_FEE = 10;

const Checkout = () => {
  const navigate = useNavigate();
  const { items, totalPrice, clearCart } = useCart();
  const [step, setStep] = useState<'details' | 'payment' | 'success'>('details');
  const [copied, setCopied] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    address: '',
    postalCode: '',
    unit: '',
  });

  // Mock HitPay link
  const hitPayLink = 'https://hit-pay.com/payment/abc123xyz';

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

  const handleSubmitDetails = (e: React.FormEvent) => {
    e.preventDefault();
    setStep('payment');
  };

  const handleCompleteOrder = () => {
    clearCart();
    setStep('success');
    toast.success('Order placed successfully!');
  };

  const copyLink = () => {
    navigator.clipboard.writeText(hitPayLink);
    setCopied(true);
    toast.success('Payment link copied!');
    setTimeout(() => setCopied(false), 2000);
  };

  const grandTotal = totalPrice + DELIVERY_FEE;

  return (
    <Layout>
      <div className="container py-8 md:py-12">
        {/* Back Button */}
        {step !== 'success' && (
          <Button variant="ghost" className="mb-6" asChild>
            <Link to={storefrontRoutes.shop}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Continue Shopping
            </Link>
          </Button>
        )}

        {step === 'success' ? (
          /* Success State */
          <div className="max-w-lg mx-auto text-center py-12">
            <div className="w-24 h-24 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center mx-auto mb-6">
              <Check className="h-12 w-12 text-primary-foreground" />
            </div>
            <h1 className="text-3xl font-display font-bold mb-4">Order Confirmed!</h1>
            <p className="text-muted-foreground mb-8">
              Thank you for your order. We've sent a confirmation email with your payment details.
            </p>
            <Button variant="hero" asChild>
              <Link to={storefrontRoutes.shop}>Continue Shopping</Link>
            </Button>
          </div>
        ) : (
          <div className="grid lg:grid-cols-3 gap-8">
            {/* Main Content */}
            <div className="lg:col-span-2">
              <div className="glass-strong rounded-2xl p-6 md:p-8">
                <h1 className="text-2xl font-display font-bold mb-6">
                  {step === 'details' ? 'Delivery Details' : 'Payment'}
                </h1>

                {step === 'details' ? (
                  <form onSubmit={handleSubmitDetails} className="space-y-6">
                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <Label htmlFor="name">Full Name</Label>
                        <Input
                          id="name"
                          value={formData.name}
                          onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                          required
                          placeholder="Enter your full name"
                        />
                      </div>
                      <div>
                        <Label htmlFor="phone">Phone Number</Label>
                        <Input
                          id="phone"
                          type="tel"
                          value={formData.phone}
                          onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                          required
                          placeholder="+65 XXXX XXXX"
                        />
                      </div>
                    </div>

                    <div>
                      <Label htmlFor="email">Email</Label>
                      <Input
                        id="email"
                        type="email"
                        value={formData.email}
                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                        required
                        placeholder="Enter your email"
                      />
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

                    <div className="grid sm:grid-cols-2 gap-4">
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
                      <div>
                        <Label htmlFor="unit">Unit Number</Label>
                        <Input
                          id="unit"
                          value={formData.unit}
                          onChange={(e) => setFormData({ ...formData, unit: e.target.value })}
                          placeholder="#XX-XXX (optional)"
                        />
                      </div>
                    </div>

                    <Button variant="hero" type="submit" className="w-full">
                      Proceed to Payment
                    </Button>
                  </form>
                ) : (
                  <div className="space-y-6">
                    {/* Delivery Summary */}
                    <div className="p-4 rounded-xl bg-muted/50">
                      <div className="flex items-start gap-3">
                        <MapPin className="h-5 w-5 text-primary mt-0.5" />
                        <div>
                          <p className="font-medium">{formData.name}</p>
                          <p className="text-sm text-muted-foreground">
                            {formData.address}, {formData.unit && `${formData.unit}, `}Singapore {formData.postalCode}
                          </p>
                          <p className="text-sm text-muted-foreground">{formData.phone}</p>
                        </div>
                      </div>
                    </div>

                    {/* Payment Instructions */}
                    <div className="space-y-4">
                      <div className="flex items-center gap-2 text-lg font-medium">
                        <CreditCard className="h-5 w-5 text-primary" />
                        <span>Payment via HitPay</span>
                      </div>
                      <p className="text-muted-foreground">
                        Click the button below to complete your payment securely via HitPay. You can also copy the payment link and complete it later.
                      </p>

                      <div className="p-4 rounded-xl bg-muted/50 flex items-center gap-3">
                        <Input
                          value={hitPayLink}
                          readOnly
                          className="bg-background"
                          data-hitpay-link={hitPayLink}
                        />
                        <Button variant="outline" size="icon" onClick={copyLink}>
                          {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        </Button>
                      </div>

                      <div className="flex gap-4">
                        <Button variant="outline" onClick={() => setStep('details')}>
                          Back
                        </Button>
                        <Button variant="hero" className="flex-1" onClick={handleCompleteOrder}>
                          Complete Order
                        </Button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Order Summary */}
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
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground flex items-center gap-2">
                      <Truck className="h-4 w-4" />
                      Local Delivery
                    </span>
                    <span>SGD {DELIVERY_FEE.toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between text-lg font-display font-bold pt-2 border-t border-border">
                    <span>Total</span>
                    <span className="gradient-text">SGD {grandTotal.toFixed(2)}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </Layout>
  );
};

export default Checkout;
