<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->string('sku');
            $table->string('variant_id'); // Lunar variant SKU key from storefront
            $table->string('product_name');
            $table->string('variant_label')->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price'); // paise
            $table->unsignedBigInteger('line_total'); // paise
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_items');
    }
};
