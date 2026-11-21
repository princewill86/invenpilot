<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ActivityLog
{
    public static function record(int $business, int $user, string $action, array $details): void
    {
        DB::table('activity_logs')->insert([
            'business_id' => $business,
            'user_id' => $user,
            'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
