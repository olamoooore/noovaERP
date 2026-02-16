<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminOrderAlert extends Notification implements ShouldQueue
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
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject("New {$this->type} Order: #{$this->order->order_number}")
                    ->greeting('Hello Admin,')
                    ->line("A new {$this->type} order has been placed.")
                    ->line("Order Number: {$this->order->order_number}")
                    ->line("Amount: " . number_format($this->order->total, 2))
                    ->line('Please check the dashboard for more details.');
    }

    public function toArray($notifiable)
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'amount' => $this->order->total,
            'type' => $this->type,
            'message' => "New {$this->type} Order #{$this->order->order_number}",
        ];
    }
}
