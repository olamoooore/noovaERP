<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOrderConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public $order;
    public $type; // 'Web' or 'POS'

    public function __construct($order, $type = 'Web')
    {
        $this->order = $order;
        $this->type = $type;
    }

    public function via($notifiable)
    {
        // Future: Add 'vonage', 'twilio', 'whatsapp' here
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $message = (new MailMessage)
                    ->subject("Order Confirmation: #{$this->order->order_number}")
                    ->greeting('Hello ' . ($notifiable->name ?? 'Customer') . ',')
                    ->line("Thank you for your purchase.")
                    ->line("Your {$this->type} order #{$this->order->order_number} has been received.")
                    ->line("Total: " . number_format($this->order->total, 2));
        
        if ($this->type === 'Web') {
            $message->line('We will notify you once your order has been shipped.');
        }

        return $message->line('Thank you for shopping with us!');
    }
    
    // Future placeholder for SMS
    public function toSms($notifiable)
    {
        return "Your NoovaERP order #{$this->order->order_number} is confirmed. Total: {$this->order->total}";
    }
}
