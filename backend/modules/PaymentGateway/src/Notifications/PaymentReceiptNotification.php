<?php

namespace Modules\PaymentGateway\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\PaymentGateway\Models\PaymentTransaction;

class PaymentReceiptNotification extends Notification
{
    use Queueable;

    protected $transaction;

    protected $invoice;

    public function __construct(PaymentTransaction $transaction)
    {
        $this->transaction = $transaction;
        $this->invoice = $transaction->invoice;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $invoiceNumber = $this->invoice->invoice_number ?? 'N/A';
        $amount = number_format($this->transaction->amount, 2);
        $currency = strtoupper($this->transaction->currency);

        return (new MailMessage)
            ->subject("Payment Receipt - Invoice {$invoiceNumber}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Thank you for your payment!')
            ->line("We have received your payment for Invoice **{$invoiceNumber}**.")
            ->line('')
            ->line('**Payment Details:**')
            ->line("Amount: **{$currency} {$amount}**")
            ->line('Payment Method: '.ucfirst($this->transaction->payment_method ?? 'N/A'))
            ->line("Transaction ID: {$this->transaction->transaction_id}")
            ->line('Date: '.$this->transaction->created_at->format('F j, Y, g:i a'))
            ->line('')
            ->action('View Invoice', url("/invoices/{$this->invoice->id}"))
            ->line('If you have any questions about this payment, please contact our support team.')
            ->salutation('Best regards, '.config('app.name'));
    }
}
