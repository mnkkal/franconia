<?php

namespace App\Mail;

use App\Models\ShopOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

class OrderUpdate extends Mailable
{
    public function __construct(public ShopOrder $order, public string $event) {}

    public function build(): static
    {
        $receiptUrl = URL::temporarySignedRoute('orders.receipt', now()->addDays(30), ['reference' => $this->order->reference]);

        return $this->subject('Franconia order '.$this->order->reference.' — '.str_replace('_', ' ', $this->event))
            ->view('emails.order-update', ['receiptUrl' => $receiptUrl]);
    }
}
