<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_records_and_category_links_are_scoped_to_the_business(): void
    {
        $owner = User::factory()->create();
        $first = Business::factory()->create();
        $second = Business::factory()->create();
        $owner->businesses()->attach([$first->id => ['role' => 'owner'], $second->id => ['role' => 'owner']]);
        $this->actingAs($owner);

        $firstUrl = "/api/v1/businesses/{$first->id}";
        $secondUrl = "/api/v1/businesses/{$second->id}";
        $category = $this->postJson("$firstUrl/categories", ['name' => 'Fasteners'])->assertCreated()->json('id');

        $this->postJson("$secondUrl/products", $this->product() + ['category_id' => $category])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $product = $this->postJson("$firstUrl/products", $this->product() + ['category_id' => $category])
            ->assertCreated()->json('id');
        $this->postJson("$secondUrl/products", $this->product())->assertCreated();
        $this->getJson("$secondUrl/products/$product")->assertNotFound();
        $this->deleteJson("$firstUrl/categories/$category")->assertConflict();

        $this->actingAs(User::factory()->create());
        $this->getJson("$firstUrl/products")->assertNotFound();
    }

    public function test_staff_cannot_change_catalog_or_inventory(): void
    {
        $staff = User::factory()->create();
        $business = Business::factory()->create();
        $staff->businesses()->attach($business, ['role' => 'staff']);
        $this->actingAs($staff);
        $url = "/api/v1/businesses/{$business->id}";

        $this->getJson("$url/products")->assertOk();
        $this->postJson("$url/products", $this->product())->assertForbidden();
        $this->postJson("$url/movements", [])->assertForbidden();
    }

    public function test_ledger_is_auditable_idempotent_and_blocks_insufficient_stock(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";

        $product = $this->postJson("$url/products", $this->product())->assertCreated()->json('id');
        $receipt = [
            'product_id' => $product,
            'type' => 'received',
            'quantity' => '5.125',
            'reference' => (string) Str::uuid(),
            'reason' => 'Opening stock',
        ];
        $first = $this->postJson("$url/movements", $receipt)->assertCreated()->json('id');
        $this->postJson("$url/movements", $receipt)->assertCreated()->assertJsonPath('id', $first);
        $this->postJson("$url/movements", [...$receipt, 'quantity' => '6'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson("$url/movements", [
            ...$receipt, 'reference' => (string) Str::uuid(), 'type' => 'damaged', 'quantity' => '-6',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => '5.125']);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product, 'user_id' => $owner->id, 'previous_stock' => 0, 'resulting_stock' => '5.125']);
    }

    public function test_weighted_average_cost_and_owner_negative_stock_setting(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";
        $product = $this->postJson("$url/products", [...$this->product(), 'purchase_cost' => '4'])->assertCreated()->json('id');
        $receive = [
            'product_id' => $product, 'type' => 'received',
            'quantity' => '5', 'reason' => 'Supplier receipt',
        ];
        $this->postJson("$url/movements", $receive + ['reference' => (string) Str::uuid()])->assertCreated();
        $this->putJson("$url/products/$product", [...$this->product(), 'purchase_cost' => '8'])->assertOk();
        $this->postJson("$url/movements", $receive + ['reference' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('products', ['id' => $product, 'average_cost' => 6]);

        $this->putJson("$url/settings", ['name' => $business->name, 'allow_negative_stock' => true])->assertOk();
        $this->postJson("$url/movements", [
            ...$receive, 'type' => 'adjustment', 'quantity' => '-11', 'reference' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => -1]);
    }

    private function product(): array
    {
        return [
            'name' => 'Steel Bolt',
            'sku' => 'BOLT-1',
            'unit' => 'pieces',
            'purchase_cost' => '4.25',
            'selling_price' => '6.10',
            'minimum_stock' => '3',
        ];
    }
}
