<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('contact_person', 160)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'name']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign(['business_id', 'category_id'])->references(['business_id', 'id'])->on('categories')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 40);
            $table->decimal('purchase_cost', 16, 4);
            $table->decimal('selling_price', 16, 4);
            $table->decimal('minimum_stock', 16, 3)->default(0);
            $table->decimal('stock', 16, 3)->default(0);
            $table->decimal('average_cost', 16, 4)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'name']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity', 16, 3);
            $table->decimal('previous_stock', 16, 3);
            $table->decimal('resulting_stock', 16, 3);
            $table->decimal('unit_cost', 16, 4);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['business_id', 'created_at']);
            $table->unique(['business_id', 'product_id', 'type', 'reference'], 'inventory_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('categories');
    }
};
