<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Services\Commerce;
use App\Services\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoAccounts extends Command
{
    protected $signature = 'invenpilot:demo';

    protected $description = 'Create test accounts and sample transactions for a loopback installation';

    public function handle(StockLedger $ledger, Commerce $commerce): int
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        if (! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            $this->error('Demo accounts can only be created for a loopback APP_URL.');

            return self::FAILURE;
        }
        $people = [
            ['Platform Admin', 'admin@invenpilot.test', 'platform'],
            ['Morgan Lee', 'owner@invenpilot.test', 'owner'],
            ['Taylor Reed', 'manager@invenpilot.test', 'administrator'],
            ['Alex Rivera', 'staff@invenpilot.test', 'staff'],
        ];
        if (User::whereIn('email', array_column($people, 1))->exists()) {
            $this->error('A demo email address is already registered.');

            return self::FAILURE;
        }
        $accounts = DB::transaction(function () use ($people, $ledger, $commerce): array {
            $business = Business::create(['name' => 'Northline Supply Demo', 'currency' => 'USD']);
            $credentials = [];
            foreach ($people as [$name, $email, $role]) {
                $password = Str::password(24, symbols: false);
                $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
                if ($role === 'platform') {
                    $user->forceFill(['platform_admin' => true])->save();
                } else {
                    $user->businesses()->attach($business, ['role' => $role]);
                }
                if ($role === 'owner') {
                    $owner = $user;
                }
                $credentials[] = [$role, $email, $password];
            }
            $supplier = DB::table('suppliers')->insertGetId(['business_id' => $business->id, 'name' => 'Westway Components', 'created_at' => now(), 'updated_at' => now()]);
            $category = DB::table('categories')->insertGetId(['business_id' => $business->id, 'name' => 'Studio equipment', 'created_at' => now(), 'updated_at' => now()]);
            $products = [];
            foreach ([['Studio headphones', 'AU-104', '42', '88', '36', 10], ['USB-C travel hub', 'AC-203', '15', '34', '7', 12], ['Mechanical keyboard', 'AC-125', '31', '69', '22', 8], ['Desktop audio stand', 'AU-122', '12', '28', '0', 8]] as [$name, $sku, $cost, $price, $stock, $threshold]) {
                $product = DB::table('products')->insertGetId([
                    'business_id' => $business->id, 'category_id' => $category, 'name' => $name, 'sku' => $sku,
                    'unit' => 'pieces', 'purchase_cost' => $cost, 'selling_price' => $price,
                    'minimum_stock' => $threshold, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($stock !== '0') {
                    $ledger->record($business, $owner->id, $product, 'received', $stock, (string) Str::uuid(), 'Demo opening inventory');
                }
                $products[] = $product;
            }
            $purchase = $commerce->create($business, $owner->id, 'purchases', [
                'request_id' => (string) Str::uuid(), 'order_date' => today()->subDays(3)->toDateString(),
                'supplier_id' => $supplier, 'status' => 'pending',
                'items' => [['product_id' => $products[0], 'quantity' => '12', 'unit_price' => '42']],
            ]);
            $commerce->apply($business, $owner->id, 'purchases', $purchase, 'receive', [
                'request_id' => (string) Str::uuid(), 'reason' => 'Demo partial delivery',
                'items' => [['product_id' => $products[0], 'quantity' => '6']],
            ]);
            foreach ([3, 6, 9] as $days) {
                $commerce->create($business, $owner->id, 'sales', [
                    'request_id' => (string) Str::uuid(), 'order_date' => today()->subDays($days)->toDateString(),
                    'payment_method' => 'card',
                    'items' => [['product_id' => $products[2], 'quantity' => (string) ($days / 3), 'unit_price' => '69']],
                ]);
            }

            return $credentials;
        });
        $this->table(['Role', 'Email', 'Password'], $accounts);

        return self::SUCCESS;
    }
}
