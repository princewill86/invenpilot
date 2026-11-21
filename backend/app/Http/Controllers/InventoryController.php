<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovementRequest;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['product_id' => ['sometimes', 'integer', 'min:1']]);
        $query = DB::table('inventory_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->select('movement.*', 'product.name as product_name', 'product.sku')
            ->where('movement.business_id', $request->attributes->get('business')->id);
        if (isset($filter['product_id'])) {
            $query->where('movement.product_id', $filter['product_id']);
        }

        $page = $query->latest('movement.id')->paginate(25);
        if ($request->attributes->get('business')->pivot->role === 'staff') {
            $page->through(function (object $row): object {
                unset($row->unit_cost);

                return $row;
            });
        }

        return response()->json($page);
    }

    public function store(MovementRequest $request, StockLedger $ledger): JsonResponse
    {
        $input = $request->validated();
        $business = $request->attributes->get('business');
        $quantity = (string) $input['quantity'];
        $direction = bccomp($quantity, '0', 3);
        $decrease = in_array($input['type'], ['damaged', 'supplier_return'], true);
        if ($direction === 0 || ($input['type'] !== 'adjustment' && $decrease !== ($direction < 0))) {
            throw ValidationException::withMessages(['quantity' => 'Enter a nonzero quantity with the correct sign for this movement.']);
        }

        $id = $ledger->record($business, $request->user()->id, $input['product_id'], $input['type'], $quantity, $input['reference'], $input['reason']);

        return response()->json(DB::table('inventory_movements')->find($id), 201);
    }
}
