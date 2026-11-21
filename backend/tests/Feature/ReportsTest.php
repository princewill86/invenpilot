<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_reports_separate_sales_profit_and_received_purchase_expenditure(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";
        $today = today()->toDateString();

        $product = $this->postJson("$url/products", [
            'name' => '=Custom Product', 'sku' => 'SKU-1', 'unit' => 'pieces',
            'purchase_cost' => '4', 'selling_price' => '10', 'minimum_stock' => '2',
        ])->assertCreated()->json('id');
        $supplier = $this->postJson("$url/suppliers", ['name' => 'Supplier'])->assertCreated()->json('id');
        $purchase = $this->postJson("$url/purchases", [
            'request_id' => (string) Str::uuid(), 'order_date' => $today, 'supplier_id' => $supplier,
            'status' => 'pending', 'additional_cost' => '2', 'discount' => '1',
            'items' => [['product_id' => $product, 'quantity' => '10', 'unit_price' => '4']],
        ])->assertCreated()->json('id');
        $this->operation("$url/purchases/$purchase/receive", $product, '5');

        $sale = $this->postJson("$url/sales", [
            'request_id' => (string) Str::uuid(), 'order_date' => $today, 'discount' => '3',
            'payment_method' => 'cash',
            'items' => [['product_id' => $product, 'quantity' => '3', 'unit_price' => '10']],
        ])->assertCreated()->json('id');
        $this->operation("$url/sales/$sale/return", $product, '1');

        $dashboard = $this->getJson("$url/dashboard")->assertOk()->json();
        $this->assertSame(18.0, (float) $dashboard['sales']['revenue']);
        $this->assertSame(8.0, (float) $dashboard['sales']['cost_of_goods']);
        $this->assertSame(10.0, (float) $dashboard['sales']['gross_profit']);
        $this->assertSame(20.5, (float) $dashboard['purchases']['expenditure']);
        $this->assertSame(12.0, (float) $dashboard['metrics']['inventory_value']);

        $this->getJson("$url/reports/sales_totals?to=$today")->assertOk()->assertJsonPath('total', 1);
        $csv = $this->get("$url/reports/inventory?format=csv")->assertDownload('inventory.csv');
        $this->assertStringContainsString("'=Custom Product", $csv->streamedContent());
    }

    private function operation(string $url, int $product, string $quantity): void
    {
        $this->postJson($url, [
            'request_id' => (string) Str::uuid(), 'reason' => 'Stock record',
            'items' => [['product_id' => $product, 'quantity' => $quantity]],
        ])->assertOk();
    }
}
