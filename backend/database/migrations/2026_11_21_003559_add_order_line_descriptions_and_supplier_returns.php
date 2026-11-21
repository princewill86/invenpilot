<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('supplier_name', 160)->nullable();
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->text('product_description')->nullable();
            $table->decimal('supplier_returned_quantity', 16, 3)->default(0);
        });
        Schema::table('order_operations', function (Blueprint $table): void {
            $table->string('type', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_operations', function (Blueprint $table): void {
            $table->string('type', 12)->change();
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['product_description', 'supplier_returned_quantity']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('supplier_name');
        });
    }
};
