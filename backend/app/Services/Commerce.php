<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Commerce
{
    public function __construct(private StockLedger $ledger) {}

    public function orders(Business $business, string $type): Builder
    {
        return DB::table('orders')->where('business_id', $business->id)->where('type', $type);
    }

    public function create(Business $business, int $actor, string $type, array $input): int
    {
        return DB::transaction(function () use ($business, $actor, $type, $input): int {
            // Serialize requests using the same business key before checking for a replay.
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if($business->suspended_at !== null, 403, 'This business has been suspended.');
            $hash = hash('sha256', json_encode([$type, $input], JSON_THROW_ON_ERROR));
            $previous = DB::table('orders')->where('business_id', $business->id)->where('request_id', $input['request_id'])->first();
            if ($previous) {
                $this->checkReplay($previous->request_hash, $hash);

                return $previous->id;
            }

            $isSale = $type === 'sales';
            $supplier = null;
            if (! $isSale) {
                $supplier = DB::table('suppliers')->where('business_id', $business->id)->whereNull('archived_at')
                    ->where('id', $input['supplier_id'])->lockForUpdate()->first();
                $this->ensure($supplier !== null, 'supplier_id', 'Choose an active supplier.');
            }

            $items = collect($input['items'])->sortBy('product_id', SORT_NUMERIC);
            $subtotal = '0.0000';
            $lines = [];
            // Lock products in a consistent order across orders to limit deadlocks.
            foreach ($items as $item) {
                $product = DB::table('products')->where('business_id', $business->id)->whereNull('archived_at')
                    ->where('id', $item['product_id'])->lockForUpdate()->first();
                $this->ensure($product !== null, 'items', 'Every line must reference an active product in this business.');
                $category = DB::table('categories')->where('business_id', $business->id)->find($product->category_id);
                $subtotal = bcadd($subtotal, bcround(bcmul((string) $item['quantity'], (string) $item['unit_price'], 7), 4), 4);
                $lines[] = [
                    'business_id' => $business->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_description' => $product->description,
                    'sku' => $product->sku,
                    'category_name' => $category?->name,
                    'unit' => $product->unit,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $isSale ? $product->average_cost : $item['unit_price'],
                ];
            }

            $discount = (string) ($input['discount'] ?? '0');
            $additional = (string) ($input['additional_cost'] ?? '0');
            $this->ensure(bccomp($discount, $subtotal, 4) <= 0, 'discount', 'The discount cannot exceed the subtotal.');
            $id = DB::table('orders')->insertGetId([
                'business_id' => $business->id,
                'user_id' => $actor,
                'supplier_id' => $input['supplier_id'] ?? null,
                'supplier_name' => $supplier?->name,
                'type' => $type,
                'request_id' => $input['request_id'],
                'request_hash' => $hash,
                'status' => $isSale ? 'completed' : $input['status'],
                'order_date' => $input['order_date'],
                'currency' => $business->currency,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'additional_cost' => $additional,
                'total' => bcadd(bcsub($subtotal, $discount, 4), $additional, 4),
                'payment_method' => $input['payment_method'] ?? null,
                'notes' => $input['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                DB::table('order_items')->insert([...$line, 'order_id' => $id]);
                if ($isSale) {
                    $this->ledger->record($business, $actor, $line['product_id'], 'sold', '-'.$line['quantity'], 'sale-'.$id);
                }
            }

            return $id;
        }, 3);
    }

    public function apply(Business $business, int $actor, string $type, int $id, string $action, array $input): void
    {
        DB::transaction(function () use ($business, $actor, $type, $id, $action, $input): void {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if($business->suspended_at !== null, 403, 'This business has been suspended.');
            $order = $this->orders($business, $type)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            $hash = hash('sha256', json_encode([$action, $input], JSON_THROW_ON_ERROR));
            $previous = DB::table('order_operations')->where('order_id', $id)->where('request_id', $input['request_id'])->first();
            if ($previous) {
                $this->checkReplay($previous->request_hash, $hash);

                return;
            }

            $isSale = $type === 'sales';
            $valid = match ($action) {
                'submit' => ! $isSale && $order->status === 'draft',
                'receive' => ! $isSale && in_array($order->status, ['pending', 'partially_received'], true),
                'supplier_return' => ! $isSale && in_array($order->status, ['partially_received', 'received', 'cancelled'], true),
                'return' => $isSale && in_array($order->status, ['completed', 'partially_returned'], true),
                'cancel' => $isSale
                    ? in_array($order->status, ['completed', 'partially_returned'], true)
                    : in_array($order->status, ['draft', 'pending', 'partially_received'], true),
            };
            $this->ensure($valid, 'action', 'This operation is unavailable for the current order status.');
            $lines = DB::table('order_items')->where('order_id', $id)->orderBy('product_id')->get()->keyBy('product_id');
            $quantities = collect($input['items'] ?? [])->pluck('quantity', 'product_id');
            if ($action === 'cancel' && $isSale) {
                $quantities = $lines->map(fn ($line): string => bcsub((string) $line->quantity, (string) $line->processed_quantity, 3))
                    ->filter(fn (string $quantity): bool => bccomp($quantity, '0', 3) > 0);
            }

            $operation = DB::table('order_operations')->insertGetId([
                'order_id' => $id,
                'user_id' => $actor,
                'request_id' => $input['request_id'],
                'request_hash' => $hash,
                'type' => $action,
                'reason' => $input['reason'],
                'created_at' => now(),
            ]);

            foreach ($quantities->sortKeys(SORT_NUMERIC) as $product => $quantity) {
                $line = $lines->get($product);
                $this->ensure($line !== null, 'items', 'Every product must belong to this order.');
                $supplierReturn = $action === 'supplier_return';
                $field = $supplierReturn ? 'supplier_returned_quantity' : 'processed_quantity';
                $maximum = $supplierReturn ? $line->processed_quantity : $line->quantity;
                $processed = bcadd((string) $line->$field, (string) $quantity, 3);
                $this->ensure(bccomp($processed, (string) $maximum, 3) <= 0, 'items', 'The quantity exceeds the remaining order quantity.');

                $movement = $this->ledger->record(
                    $business, $actor, $product,
                    $supplierReturn ? 'purchase_return' : ($isSale ? 'sale_return' : 'purchase_receipt'),
                    $supplierReturn ? '-'.$quantity : (string) $quantity,
                    'operation-'.$operation, $input['reason'], $supplierReturn ? null : (string) $line->unit_cost,
                );
                DB::table('order_operation_items')->insert([
                    'order_operation_id' => $operation,
                    'order_item_id' => $line->id,
                    'inventory_movement_id' => $movement,
                    'quantity' => $quantity,
                ]);
                DB::table('order_items')->where('id', $line->id)->update([$field => $processed]);
                $line->$field = $processed;
            }

            $complete = $lines->every(fn ($line): bool => bccomp((string) $line->quantity, (string) $line->processed_quantity, 3) === 0);
            $status = match ($action) {
                'submit' => 'pending',
                'receive' => $complete ? 'received' : 'partially_received',
                'return' => $complete ? 'returned' : 'partially_returned',
                'cancel' => 'cancelled',
                'supplier_return' => $order->status,
            };
            $this->orders($business, $type)->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        }, 3);
    }

    private function checkReplay(string $previous, string $hash): void
    {
        $this->ensure(hash_equals($previous, $hash), 'request_id', 'This request identifier was already used with different details.');
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
