<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Services\Commerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CommerceController extends Controller
{
    public function index(Request $request, Commerce $commerce, int $business, string $type): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'status' => ['nullable', 'string', 'max:24'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'integer', 'min:1'],
        ]);
        $orders = $commerce->orders($request->attributes->get('business'), $type);
        foreach (['status' => 'status', 'supplier_id' => 'supplier_id', 'search' => 'id'] as $field => $column) {
            if (isset($filters[$field])) {
                $orders->where($column, $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $orders->where('order_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $orders->where('order_date', '<=', $filters['to']);
        }
        $page = $orders->latest('order_date')->latest('id')->paginate(25);
        $page->through(fn ($order): array => $this->summary($order));

        return response()->json($page);
    }

    public function show(Request $request, Commerce $commerce, int $business, string $type, int $record): JsonResponse
    {
        return response()->json($this->details($request, $commerce, $type, $record));
    }

    public function store(CommerceRequest $request, Commerce $commerce, int $business, string $type): JsonResponse
    {
        $id = $commerce->create($request->attributes->get('business'), $request->user()->id, $type, $request->validated());

        return response()->json($this->details($request, $commerce, $type, $id), 201);
    }

    public function apply(CommerceRequest $request, Commerce $commerce, int $business, string $type, int $record, string $action): JsonResponse
    {
        $commerce->apply($request->attributes->get('business'), $request->user()->id, $type, $record, $action, $request->validated());

        return response()->json($this->details($request, $commerce, $type, $record));
    }

    private function details(Request $request, Commerce $commerce, string $type, int $id): array
    {
        $order = $commerce->orders($request->attributes->get('business'), $type)->find($id);
        abort_unless($order, 404);
        $operations = DB::table('order_operations')->where('order_id', $id)->oldest('id')
            ->get(['id', 'type', 'reason', 'user_id', 'created_at']);
        $operationItems = DB::table('order_operation_items')->whereIn('order_operation_id', $operations->pluck('id'))
            ->get()->groupBy('order_operation_id');

        return [
            ...$this->summary($order),
            'items' => DB::table('order_items')->where('order_id', $id)->orderBy('id')->get()->map(
                fn ($line): array => $request->attributes->get('business')->pivot->role === 'staff'
                    ? Arr::except((array) $line, ['unit_cost'])
                    : (array) $line,
            ),
            'operations' => $operations->map(fn ($operation): array => [
                ...(array) $operation,
                'items' => $operationItems->get($operation->id, []),
            ]),
        ];
    }

    private function summary(object $order): array
    {
        return [
            ...Arr::except((array) $order, ['request_id', 'request_hash']),
            'reference' => ($order->type === 'sales' ? 'SO-' : 'PO-').$order->id,
        ];
    }
}
