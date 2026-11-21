<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_images_are_validated_and_served_only_to_business_members(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";

        $product = $this->postJson("$url/products", [
            'name' => 'Wrench', 'sku' => 'WRENCH', 'unit' => 'pieces',
            'purchase_cost' => '7', 'selling_price' => '12', 'minimum_stock' => '1',
        ])->assertCreated()->json('id');
        $imageUrl = "$url/products/$product/image";
        $this->postJson($imageUrl, ['image' => UploadedFile::fake()->createWithContent('invalid.png', 'not an image')])
            ->assertUnprocessable();
        $image = UploadedFile::fake()->createWithContent('product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aLfoAAAAASUVORK5CYII='));
        $path = $this->postJson($imageUrl, ['image' => $image])->assertOk()->json('image_path');
        Storage::disk('local')->assertExists($path);
        $this->get($imageUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs(User::factory()->create());
        $this->get($imageUrl)->assertNotFound();
    }
}
