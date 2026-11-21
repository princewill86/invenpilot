<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductImageController extends Controller
{
    public function show(Request $request, int $business, int $record): StreamedResponse
    {
        $path = $this->product($request, $record)->value('image_path');
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function store(Request $request, int $business, int $record): JsonResponse
    {
        $data = $request->validate(['image' => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])
            ->max('4mb'), 'dimensions:max_width=4096,max_height=4096']]);
        $product = $this->product($request, $record);
        abort_unless((clone $product)->exists(), 404);
        // Store uploads outside the public web root and serve them through membership checks.
        $path = $data['image']->store("product-images/$business", 'local');
        abort_unless(is_string($path), 503, 'The image could not be stored.');

        try {
            $previous = DB::transaction(function () use ($product, $path): ?string {
                $record = (clone $product)->lockForUpdate()->first();
                abort_unless($record, 404);
                (clone $product)->update(['image_path' => $path, 'updated_at' => now()]);

                return $record->image_path;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);

            throw $error;
        }

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json($product->first());
    }

    private function product(Request $request, int $record): Builder
    {
        return DB::table('products')->where('business_id', $request->attributes->get('business')->id)->where('id', $record);
    }
}
