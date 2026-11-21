<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreign(['business_id', 'supplier_id'])->references(['business_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->string('type', 10);
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('status', 24);
            $table->date('order_date');
            $table->char('currency', 3);
            $table->decimal('subtotal', 24, 4);
            $table->decimal('discount', 24, 4);
            $table->decimal('additional_cost', 24, 4);
            $table->decimal('total', 24, 4);
            $table->string('payment_method', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'request_id']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'type', 'order_date']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->foreign(['business_id', 'order_id'])->references(['business_id', 'id'])->on('orders')->restrictOnDelete();
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
            $table->string('product_name', 160);
            $table->string('sku', 100);
            $table->string('category_name', 120)->nullable();
            $table->string('unit', 40);
            $table->decimal('quantity', 16, 3);
            $table->decimal('processed_quantity', 16, 3)->default(0);
            $table->decimal('unit_price', 16, 4);
            $table->decimal('unit_cost', 16, 4)->default(0);
            $table->unique(['order_id', 'product_id']);
        });

        Schema::create('order_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('type', 12);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['order_id', 'request_id']);
        });

        Schema::create('order_operation_items', function (Blueprint $table): void {
            $table->foreignId('order_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 16, 3);
            $table->primary(['order_operation_id', 'order_item_id'], 'order_operation_item_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_operation_items');
        Schema::dropIfExists('order_operations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
