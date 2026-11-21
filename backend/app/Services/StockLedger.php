<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    public function record(Business $business, int $userId, int $productId, string $type, string $quantity, string $reference, ?string $reason = null, ?string $incomingCost = null): int
    {
        return DB::transaction(function () use ($business, $userId, $productId, $type, $quantity, $reference, $reason, $incomingCost): int {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if($business->suspended_at !== null, 403, 'This business has been suspended.');
            $balance = DB::table('products')->where('business_id', $business->id)->where('id', $productId);

            // Lock before reading the balance so concurrent deductions cannot spend the same stock.
            $product = (clone $balance)->lockForUpdate()->first();
            abort_unless($product, 404);

            $record = DB::table('inventory_movements')
                ->where('business_id', $business->id)
                ->where('product_id', $productId)
                ->where('type', $type)
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();
            if ($record) {
                if (bccomp((string) $record->quantity, $quantity, 3) !== 0 || $record->reason !== $reason) {
                    throw ValidationException::withMessages(['reference' => 'This operation reference has already been used.']);
                }

                return $record->id;
            }

            $previous = (string) $product->stock;
            $result = bcadd($previous, $quantity, 3);
            $isIncoming = bccomp($quantity, '0', 3) > 0;
            if (! $isIncoming && ! $business->allow_negative_stock && bccomp($result, '0', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient stock for this operation.']);
            }
            $reopen = $product->archived_at !== null && in_array($type, ['purchase_receipt', 'sale_return'], true);
            if (($product->archived_at !== null && ! $reopen) || bccomp($result, '9999999999999.999', 3) > 0 || bccomp($result, '-9999999999999.999', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'This quantity cannot be recorded for the product.']);
            }

            $average = (string) $product->average_cost;
            $unitCost = $isIncoming ? ($incomingCost ?? (string) $product->purchase_cost) : $average;
            if ($isIncoming) {
                // Receipts into an empty or negative balance establish a fresh cost basis.
                $average = bccomp($previous, '0', 3) <= 0
                    ? $unitCost
                    : bcround(bcdiv(bcadd(bcmul($previous, $average, 7), bcmul($quantity, $unitCost, 7), 7), $result, 8), 4);
            }

            // A valid order receipt or return can restock a previously archived product.
            $balance->update(['stock' => $result, 'average_cost' => $average, 'archived_at' => null, 'updated_at' => now()]);

            return DB::table('inventory_movements')->insertGetId([
                'business_id' => $business->id,
                'product_id' => $productId,
                'user_id' => $userId,
                'type' => $type,
                'quantity' => $quantity,
                'previous_stock' => $previous,
                'resulting_stock' => $result,
                'unit_cost' => $unitCost,
                'reference' => $reference,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }, 3);
    }
}
