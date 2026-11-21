<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Reporting
{
    public const TYPES = [
        'inventory', 'valuation', 'low_stock', 'out_of_stock', 'movements', 'adjustments',
        'sales_by_date', 'sales_by_product', 'sales_by_category', 'sales_totals',
        'purchases_by_supplier', 'purchases_by_date', 'purchase_totals', 'purchasing_history',
    ];

    public function report(int $business, string $type, array $filters): Builder
    {
        if (in_array($type, ['sales_by_date', 'sales_by_product', 'sales_by_category', 'sales_totals'], true)) {
            return $this->sales($business, $type, $filters);
        }
        if (in_array($type, ['purchases_by_supplier', 'purchases_by_date', 'purchase_totals', 'purchasing_history'], true)) {
            return $this->purchases($business, $type, $filters);
        }
        if (in_array($type, ['movements', 'adjustments'], true)) {
            $query = DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
                ->where('m.business_id', $business)
                ->select('m.id', 'p.name', 'p.sku', 'm.type', 'm.quantity', 'm.previous_stock', 'm.resulting_stock', 'm.unit_cost', 'm.reference', 'm.reason', 'm.user_id', 'm.created_at');
            if ($type === 'adjustments') {
                $query->where('m.type', 'adjustment');
            }
            $this->dates($query, 'm.created_at', $filters, true);
            $this->search($query, 'p.name', $filters);

            return $query->latest('m.id');
        }

        $query = DB::table('products as p')->where('p.business_id', $business)->whereNull('p.archived_at')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->select('p.id', 'p.name', 'p.sku', 'c.name as category', 'p.unit', 'p.stock', 'p.minimum_stock', 'p.average_cost')
            ->selectRaw('ROUND(p.stock * p.average_cost, 4) as stock_value');
        if ($type === 'low_stock') {
            $query->where('p.stock', '>', 0)->whereColumn('p.stock', '<=', 'p.minimum_stock');
        }
        if ($type === 'out_of_stock') {
            $query->where('p.stock', '<=', 0);
        }
        $this->search($query, 'p.name', $filters);

        return $query->orderBy('p.name')->orderBy('p.id');
    }

    public function dashboard(int $business, array $filters): array
    {
        $inventory = DB::table('products')->where('products.business_id', $business)->whereNull('archived_at');
        $purchases = $this->purchases($business, 'purchase_totals', $filters)->first();
        $sales = $this->sales($business, 'sales_totals', $filters)->first();
        $metrics = (clone $inventory)->selectRaw(
            'COUNT(*) as product_count, COALESCE(SUM(stock), 0) as stock_units, COALESCE(ROUND(SUM(stock * average_cost), 4), 0) as inventory_value,
            COALESCE(SUM(CASE WHEN stock > 0 AND stock <= minimum_stock THEN 1 ELSE 0 END), 0) as low_stock,
            COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock',
        )->first();
        $recent = DB::table('orders')->where('business_id', $business);
        $this->dates($recent, 'order_date', $filters);
        $movements = DB::table('inventory_movements')->where('business_id', $business);
        $this->dates($movements, 'created_at', $filters, true);

        return [
            'metrics' => $metrics,
            'sales' => $sales,
            'purchases' => $purchases,
            'sales_trend' => $this->sales($business, 'sales_by_date', $filters)->limit(366)->get(),
            'purchase_trend' => $this->purchases($business, 'purchases_by_date', $filters)->limit(366)->get(),
            'stock_trend' => (clone $movements)->selectRaw(
                'DATE(created_at) as day, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as stock_out',
            )->groupByRaw('DATE(created_at)')->orderBy('day')->limit(366)->get(),
            'top_products' => $this->sales($business, 'sales_by_product', $filters)->limit(8)->get(),
            'stock_by_category' => (clone $inventory)->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category, ROUND(SUM(stock * average_cost), 4) as stock_value")
                ->groupByRaw("COALESCE(categories.name, 'Uncategorized')")->orderByDesc('stock_value')->limit(12)->get(),
            'recent_movements' => $this->report($business, 'movements', $filters)->limit(6)->get(),
            'recent_sales' => (clone $recent)->where('type', 'sales')->latest('id')->limit(6)->get(['id', 'order_date', 'total', 'status']),
            'recent_purchases' => (clone $recent)->where('type', 'purchases')->latest('id')->limit(6)->get(['id', 'order_date', 'total', 'status']),
        ];
    }

    private function sales(int $business, string $type, array $filters): Builder
    {
        // Recognize the original sale on its order date and compensating amounts on their return dates.
        $sales = $this->eventBase($business, 'sales')->selectRaw(
            'o.order_date as day, l.product_id, l.product_name, l.sku, l.category_name, l.quantity as quantity,
             ROUND(l.quantity * l.unit_price, 4) as gross, o.discount, o.subtotal, l.quantity * l.unit_cost as cost',
        );
        $returns = $this->eventBase($business, 'sales')
            ->join('order_operation_items as ol', 'ol.order_item_id', '=', 'l.id')
            ->join('order_operations as op', 'op.id', '=', 'ol.order_operation_id')
            ->selectRaw(
                'DATE(op.created_at) as day, l.product_id, l.product_name, l.sku, l.category_name, -ol.quantity as quantity,
                 -ROUND(l.quantity * l.unit_price, 4) * ol.quantity * 1.00000000 / l.quantity as gross,
                 o.discount, o.subtotal, -ol.quantity * l.unit_cost as cost',
            );
        $query = DB::query()->fromSub($sales->unionAll($returns), 'events');
        $this->dates($query, 'day', $filters);
        if ($type === 'sales_by_product') {
            $query->join('products as product', 'events.product_id', '=', 'product.id');
        }
        $this->search($query, $type === 'sales_by_product' ? 'product.name' : 'product_name', $filters);

        // Allocate each order discount by its lines' original extended prices.
        $net = 'CASE WHEN subtotal > 0 THEN gross - (gross * discount * 1.00000000 / subtotal) ELSE 0 END';
        $query->selectRaw("
            COALESCE(ROUND(SUM(quantity), 3), 0) as units,
            COALESCE(ROUND(SUM($net), 4), 0) as revenue,
            COALESCE(ROUND(SUM(cost), 4), 0) as cost_of_goods,
            COALESCE(ROUND(SUM($net - cost), 4), 0) as gross_profit
        ");

        return match ($type) {
            'sales_by_date' => $query->addSelect('day')->groupBy('day')->orderBy('day'),
            'sales_by_product' => $query->addSelect('events.product_id', 'product.name as product_name', 'product.sku')
                ->groupBy('events.product_id', 'product.name', 'product.sku')->orderByDesc('units')->orderBy('product_id'),
            'sales_by_category' => $query->addSelect('category_name')->groupBy('category_name')->orderByDesc('revenue')->orderBy('category_name'),
            default => DB::query()->fromSub($query, 'totals'),
        };
    }

    private function purchases(int $business, string $type, array $filters): Builder
    {
        // Recognize purchase expenditure when goods are received. Allocate order-level adjustments across lines.
        $receipts = $this->eventBase($business, 'purchases')
            ->join('order_operation_items as ol', 'ol.order_item_id', '=', 'l.id')
            ->join('order_operations as op', 'op.id', '=', 'ol.order_operation_id')
            ->join('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->whereIn('op.type', ['receive', 'supplier_return'])
            ->selectRaw(
                "DATE(op.created_at) as day, o.id as purchase_id, s.id as supplier_id, COALESCE(o.supplier_name, s.name) as supplier, s.name as current_supplier, op.type as operation,
                 l.product_name, l.sku, l.unit_price, CASE WHEN op.type = 'supplier_return' THEN -ol.quantity ELSE ol.quantity END as quantity,
                 (CASE WHEN op.type = 'supplier_return' THEN -1 ELSE 1 END) *
                 (CASE WHEN o.subtotal > 0 THEN ROUND(l.quantity * l.unit_price, 4) * ol.quantity * 1.00000000 / l.quantity * o.total / o.subtotal
                      ELSE o.total * ol.quantity * 1.00000000 / l.quantity / (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) END) as expenditure",
            );

        $query = DB::query()->fromSub($receipts, 'receipts');
        $this->dates($query, 'day', $filters);
        $this->search($query, $type === 'purchases_by_supplier' ? 'current_supplier' : 'product_name', $filters);

        return match ($type) {
            'purchases_by_date' => $query->selectRaw('day, ROUND(SUM(expenditure), 4) as expenditure')->groupBy('day')->orderBy('day'),
            'purchases_by_supplier' => $query->selectRaw('supplier_id, current_supplier as supplier, ROUND(SUM(expenditure), 4) as expenditure')
                ->groupBy('supplier_id', 'current_supplier')->orderByDesc('expenditure')->orderBy('supplier_id'),
            'purchasing_history' => $query->selectRaw('day, purchase_id, supplier, operation, product_name, sku, unit_price, quantity, ROUND(expenditure, 4) as expenditure')
                ->orderByDesc('day')->orderByDesc('purchase_id')->orderBy('product_name'),
            default => DB::query()->fromSub($query->selectRaw('COALESCE(ROUND(SUM(expenditure), 4), 0) as expenditure'), 'totals'),
        };
    }

    private function eventBase(int $business, string $type): Builder
    {
        return DB::table('orders as o')->join('order_items as l', 'l.order_id', '=', 'o.id')
            ->where('o.business_id', $business)->where('o.type', $type);
    }

    private function dates(Builder $query, string $column, array $filters, bool $timestamp = false): void
    {
        if (isset($filters['from'])) {
            $query->where($column, '>=', $filters['from'].($timestamp ? ' 00:00:00' : ''));
        }
        if (isset($filters['to'])) {
            $query->where($column, '<=', $filters['to'].($timestamp ? ' 23:59:59' : ''));
        }
    }

    private function search(Builder $query, string $column, array $filters): void
    {
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->whereRaw("$column like ? escape '!'", [$term]);
        }
    }
}
