<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\View;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class CustomerOrderConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public $order;
    public $type; // 'Web' or 'POS'
    public $invoice;

    public function __construct($order, $type = 'Web')
    {
        $this->order = $order;
        $this->type = $type;
        $this->invoice = $order->invoice ?? null;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        // Load items relationship if not already loaded
        if (!$this->order->relationLoaded('items')) {
            $this->order->load('items');
        }

        // Get customer name
        $customerName = $this->order->first_name . ' ' . $this->order->last_name;
        if (empty(trim($customerName))) {
            $customerName = $notifiable->name ?? 'Valued Customer';
        }

        // Get customer email
        $customerEmail = $this->order->email ?? $notifiable->email;

        // Get currency
        $currency = $this->order->currency ?? config('app.currency', '$');
        if ($currency === 'NGN' || $currency === 'NGN ') {
            $currency = '#';
        } elseif (strlen($currency) > 2) {
            $currency = '$';
        }

        // Format shipping address
        $shippingAddress = null;
        if ($this->order->shipping_address) {
            $addr = $this->order->shipping_address;
            $shippingAddress = [
                'name' => trim(($addr['first_name'] ?? $this->order->first_name) . ' ' . ($addr['last_name'] ?? $this->order->last_name)),
                'address' => $addr['address'] ?? $addr['street'] ?? '',
                'address2' => $addr['address2'] ?? $addr['street2'] ?? '',
                'city' => $addr['city'] ?? '',
                'state' => $addr['state'] ?? '',
                'zip' => $addr['zip'] ?? $addr['postal_code'] ?? '',
                'country' => $addr['country'] ?? '',
                'phone' => $addr['phone'] ?? $this->order->phone ?? '',
            ];
        }

        // Build the email
        $mail = (new MailMessage)
            ->subject("Order Confirmation: #{$this->order->order_number}")
            ->view('emails.orders.confirmation', [
                'order' => $this->order,
                'items' => $this->order->items,
                'customerName' => $customerName,
                'customerEmail' => $customerEmail,
                'currency' => $currency,
                'shippingAddress' => $shippingAddress,
            ]);

        // Attach invoice PDF if available
        if ($this->invoice) {
            try {
                $pdf = $this->generateInvoicePdf($currency);
                $mail->attachData(
                    $pdf,
                    "invoice-{$this->invoice->invoice_number}.pdf",
                    ['mime' => 'application/pdf']
                );
            } catch (\Exception $e) {
                \Log::error('Failed to generate invoice PDF for order confirmation', [
                    'order_id' => $this->order->id,
                    'invoice_id' => $this->invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $mail;
    }

    /**
     * Generate invoice PDF
     */
    protected function generateInvoicePdf($currency = '$')
    {
        $invoice = $this->invoice;
        
        // Load relationships
        if (!$invoice->relationLoaded('lines')) {
            $invoice->load('lines.product', 'customer');
        }

        $customer = $invoice->customer;

        // If no customer, create a dummy object from order data
        if (!$customer) {
            $customer = new \stdClass();
            $customer->name = $this->order->first_name . ' ' . $this->order->last_name;
            $customer->first_name = $this->order->first_name;
            $customer->last_name = $this->order->last_name;
            $customer->email = $this->order->email;
            $customer->phone = $this->order->phone;
            $customer->billing_address = null;
        }

        $html = View::make('emails.orders.invoice', [
            'invoice' => $invoice,
            'customer' => $customer,
            'currency' => $currency,
        ])->render();

        return Pdf::loadHTML($html)->output();
    }
    
    /**
     * Future placeholder for SMS
     */
    public function toSms($notifiable)
    {
        return "Your NoovaERP order #{$this->order->order_number} is confirmed. Total: {$this->order->total}";
    }
}
