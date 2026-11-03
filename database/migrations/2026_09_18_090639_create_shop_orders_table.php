<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            // Public reference, e.g. FR-1001
            $table->string('reference')->unique();
            $table->string('status')->default('pending'); // pending → paid → processing → shipped → delivered / cancelled / refunded
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('address');
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode');
            $table->string('coupon')->nullable();
            $table->unsignedBigInteger('subtotal'); // paise
            $table->unsignedBigInteger('shipping'); // paise
            $table->unsignedBigInteger('tax'); // paise (GST 18% note)
            $table->unsignedBigInteger('total'); // paise
            $table->string('currency', 3)->default('INR');
            $table->string('razorpay_order_id')->nullable()->index();
            $table->string('razorpay_payment_id')->nullable();
            $table->string('razorpay_signature')->nullable();
            $table->timestamp('paid_at')->nullable();
            // Fulfilment (admin fills on shipment)
            $table->string('tracking_number')->nullable();
            $table->string('tracking_link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
