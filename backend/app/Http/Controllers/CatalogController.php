<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Services\ActivityLog;
use Illuminate\Database\ForeignKeyConstraintViolationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function index(Request $request, int $business, string $resource): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'stock_status' => ['nullable', 'in:low,out,in_stock,affected'],
            'sort' => ['nullable', 'in:name,recent'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $query = $this->records($request, $resource);
        if ($resource !== 'categories') {
            ($filters['archived'] ?? false) ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(function (Builder $search) use ($resource, $term): void {
                $search->whereRaw("name like ? escape '!'", [$term]);
                if ($resource === 'products') {
                    $search->orWhereRaw("sku like ? escape '!'", [$term])
                        ->orWhereRaw("barcode like ? escape '!'", [$term]);
                }
            });
        }
        if ($resource === 'products') {
            $this->applyProductFilters($query, $filters);
        }
        if (($filters['sort'] ?? 'name') === 'name') {
            $query->orderBy('name');
        }

        $page = $query->orderByDesc('id')->paginate(25);
        $page->through(fn (object $row): object => $this->visible($request, $row));

        return response()->json($page);
    }

    public function show(Request $request, int $business, string $resource, int $record): JsonResponse
    {
        $result = $this->records($request, $resource)->find($record);
        abort_unless($result, 404);

        return response()->json($this->visible($request, $result));
    }

    public function store(CatalogRequest $request, int $business, string $resource): JsonResponse
    {
        $query = $this->records($request, $resource);
        try {
            $id = DB::transaction(function () use ($request, $query, $resource): int {
                $business = $request->attributes->get('business')->id;
                $id = $query->insertGetId([
                    ...$request->validated(),
                    'business_id' => $business,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                ActivityLog::record($business, $request->user()->id, "$resource.created", ['id' => $id]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            $this->duplicate($resource);
        }

        return response()->json($query->find($id), 201);
    }

    public function update(CatalogRequest $request, int $business, string $resource, int $record): JsonResponse
    {
        $query = $this->records($request, $resource)->where('id', $record);
        abort_unless($query->exists(), 404);
        try {
            DB::transaction(function () use ($query, $request, $resource, $record): void {
                $query->update([...$request->validated(), 'updated_at' => now()]);
                ActivityLog::record($request->attributes->get('business')->id, $request->user()->id, "$resource.updated", ['id' => $record]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->duplicate($resource);
        }

        return response()->json($query->first());
    }

    public function destroy(Request $request, int $business, string $resource, int $record): Response
    {
        $query = $this->records($request, $resource)->where('id', $record);
        abort_unless($query->exists(), 404);
        if ($resource === 'categories') {
            abort_if($this->records($request, 'products')->where('category_id', $record)->exists(),
                409, 'Remove this category from its products before deleting it.');
            try {
                DB::transaction(function () use ($query, $request, $resource, $record): void {
                    if ($query->delete() > 0) {
                        ActivityLog::record($request->attributes->get('business')->id, $request->user()->id, "$resource.deleted", ['id' => $record]);
                    }
                });
            } catch (ForeignKeyConstraintViolationException) {
                abort(409, 'Remove this category from its products before deleting it.');
            }
        } else {
            DB::transaction(function () use ($request, $resource, $query, $record): void {
                $row = (clone $query)->lockForUpdate()->first();
                abort_if($resource === 'products' && bccomp((string) $row->stock, '0', 3) !== 0,
                    409, 'Bring the product stock to zero before archiving it.');
                if ($query->whereNull('archived_at')->update(['archived_at' => now(), 'updated_at' => now()]) > 0) {
                    ActivityLog::record($request->attributes->get('business')->id, $request->user()->id, "$resource.archived", ['id' => $record]);
                }
            });
        }

        return response()->noContent();
    }

    public function supplierProducts(Request $request, int $business, int $record): JsonResponse
    {
        abort_unless($this->records($request, 'suppliers')->where('id', $record)->exists(), 404);
        $products = DB::table('products')
            ->where('business_id', $business)
            ->whereIn('id', DB::table('order_items as line')
                ->join('orders as purchase', 'purchase.id', '=', 'line.order_id')
                ->where('purchase.business_id', $business)
                ->where('purchase.supplier_id', $record)
                ->where('purchase.type', 'purchases')
                ->select('line.product_id'))
            ->orderBy('name')->orderBy('id');

        return response()->json($products->paginate(25, ['id', 'name', 'sku']));
    }

    private function records(Request $request, string $resource): Builder
    {
        $business = $request->attributes->get('business');
        abort_if($resource === 'suppliers' && $business->pivot->role === 'staff', 403);

        return DB::table($resource)->where('business_id', $business->id);
    }

    private function applyProductFilters(Builder $query, array $filters): void
    {
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        match ($filters['stock_status'] ?? null) {
            'low' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'minimum_stock'),
            'affected' => $query->where(fn (Builder $affected) => $affected->where('stock', '<=', 0)->orWhereColumn('stock', '<=', 'minimum_stock')),
            'out' => $query->where('stock', '<=', 0),
            'in_stock' => $query->where('stock', '>', 0)->whereColumn('stock', '>', 'minimum_stock'),
            default => null,
        };
    }

    private function visible(Request $request, object $row): object
    {
        if ($request->attributes->get('business')->pivot->role === 'staff') {
            unset($row->purchase_cost, $row->average_cost);
        }

        return $row;
    }

    private function duplicate(string $resource): never
    {
        throw ValidationException::withMessages($resource === 'products'
            ? ['sku' => 'This SKU is already in use.']
            : ['name' => 'This category name is already in use.']);
    }
}
