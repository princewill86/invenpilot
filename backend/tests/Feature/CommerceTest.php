<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_receipts_and_sale_reversals_remain_consistent_across_retries(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";

        $supplier = $this->postJson("$url/suppliers", ['name' => 'Primary Supplier'])->assertCreated()->json('id');
        $product = $this->postJson("$url/products", [
            'name' => 'Stock Item', 'sku' => 'IT-01', 'unit' => 'pieces', 'minimum_stock' => '5',
            'purchase_cost' => '4.15', 'selling_price' => '7.25',
        ])->assertCreated()->json('id');
        $input = [
            'request_id' => (string) Str::uuid(), 'order_date' => '2026-11-20',
            'items' => [['product_id' => $product, 'quantity' => '10', 'unit_price' => '4.15']],
        ];
        $purchase = $this->postJson("$url/purchases", $input + ['supplier_id' => $supplier, 'status' => 'pending'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->assertDatabaseCount('inventory_movements', 0);

        $receipt = $this->operation($product, '4');
        $this->postJson("$url/purchases/$purchase/receive", $receipt)->assertOk()->assertJsonPath('status', 'partially_received');
        $this->postJson("$url/purchases/$purchase/receive", $receipt)->assertOk();
        $this->postJson("$url/purchases/$purchase/receive", $this->operation($product, '7'))->assertUnprocessable();
        $this->postJson("$url/purchases/$purchase/receive", $this->operation($product, '6'))->assertOk()->assertJsonPath('status', 'received');
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 10, 'average_cost' => '4.15']);

        $sale = [...$input, 'request_id' => (string) Str::uuid(), 'payment_method' => 'cash',
            'items' => [['product_id' => $product, 'quantity' => '7', 'unit_price' => '7.25']]];
        $saleId = $this->postJson("$url/sales", $sale)->assertCreated()->json('id');
        $this->postJson("$url/sales", $sale)->assertCreated()->assertJsonPath('id', $saleId);
        $this->postJson("$url/sales", [...$sale, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 3]);

        $this->postJson("$url/sales/$saleId/return", $this->operation($product, '2'))->assertOk()->assertJsonPath('status', 'partially_returned');
        $cancel = ['request_id' => (string) Str::uuid(), 'reason' => 'Customer cancelled'];
        $this->postJson("$url/sales/$saleId/cancel", $cancel)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("$url/sales/$saleId/cancel", $cancel)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 10]);
        $this->assertDatabaseCount('inventory_movements', 5);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_operations', 4);
    }

    public function test_supplier_returns_and_partial_cancellation_preserve_received_history(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";

        $supplier = $this->postJson("$url/suppliers", ['name' => 'Original supplier'])->assertCreated()->json('id');
        $productInput = [
            'name' => 'Original label', 'description' => 'First description', 'sku' => 'PRODUCT-1',
            'unit' => 'pieces', 'minimum_stock' => '1', 'purchase_cost' => '8', 'selling_price' => '15',
        ];
        $product = $this->postJson("$url/products", $productInput)->assertCreated()->json('id');
        $purchase = $this->postJson("$url/purchases", [
            'request_id' => (string) Str::uuid(), 'order_date' => today()->toDateString(),
            'supplier_id' => $supplier, 'status' => 'pending',
            'items' => [['product_id' => $product, 'quantity' => '10', 'unit_price' => '8']],
        ])->assertCreated()->json('id');

        $this->postJson("$url/purchases/$purchase/receive", $this->operation($product, '6'))->assertOk();
        $this->putJson("$url/products/$product", [...$productInput, 'name' => 'Current label', 'description' => 'New description'])->assertOk();
        $this->putJson("$url/suppliers/$supplier", ['name' => 'Current supplier'])->assertOk();

        $return = $this->operation($product, '2');
        $this->postJson("$url/purchases/$purchase/supplier_return", $return)->assertOk();
        $this->postJson("$url/purchases/$purchase/supplier_return", $return)->assertOk();
        $this->postJson("$url/purchases/$purchase/supplier_return", $this->operation($product, '5'))->assertUnprocessable();

        $this->postJson("$url/purchases/$purchase/cancel", [
            'request_id' => (string) Str::uuid(), 'reason' => 'Cancel the outstanding units',
        ])->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('supplier_name', 'Original supplier')
            ->assertJsonPath('items.0.product_name', 'Original label')
            ->assertJsonPath('items.0.product_description', 'First description');

        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 4]);
        $this->assertDatabaseHas('order_items', ['order_id' => $purchase, 'processed_quantity' => 6, 'supplier_returned_quantity' => 2]);
        $totals = $this->getJson("$url/reports/purchase_totals")->assertOk()->json();
        $this->assertSame(32.0, (float) $totals['data'][0]['expenditure']);
    }

    private function operation(int $product, string $quantity): array
    {
        return [
            'request_id' => (string) Str::uuid(), 'reason' => 'Warehouse check',
            'items' => [['product_id' => $product, 'quantity' => $quantity]],
        ];
    }
}
