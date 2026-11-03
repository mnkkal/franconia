<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            // Legacy unpaid orders hold no reservations. Never restore stock for them.
            $table->string('inventory_state')->default('none');
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->string('refund_id')->nullable();
            $table->string('refund_status')->nullable();
            $table->unsignedInteger('refund_attempt')->default(0);
            $table->boolean('refund_restock')->default(false);
            $table->text('refund_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
        });
        Schema::create('store_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->boolean('handled')->default(false);
            $table->timestamps();
        });
        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('store_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['shop_order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_notifications');
        Schema::dropIfExists('newsletter_subscriptions');
        Schema::dropIfExists('store_messages');
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn(['inventory_state', 'reservation_expires_at', 'refund_id', 'refund_status', 'refund_attempt', 'refund_restock', 'refund_reason', 'refunded_at']);
        });
    }
};
