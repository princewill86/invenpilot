<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class Platform
{
    public function settings(): array
    {
        $stored = DB::table('platform_settings')->where('id', 1)->value('settings');
        $settings = array_replace(config('platform'), $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : []);
        $settings['legal_version'] = hash('sha256', json_encode([$settings['terms'], $settings['privacy']], JSON_THROW_ON_ERROR));

        return $settings;
    }

    public function publicSettings(): array
    {
        $settings = $this->settings();

        return [
            ...Arr::only($settings, ['name', 'headline', 'description', 'support_email', 'registration_enabled',
                'default_currency', 'currencies', 'terms', 'privacy', 'legal_version', 'seo_title', 'seo_description',
                'search_indexing', 'password_reset_enabled', 'password_minimum']),
            'logo_url' => $this->assetUrl($settings['logo_path'], 'logo'),
            'favicon_url' => $this->assetUrl($settings['favicon_path'], 'favicon'),
        ];
    }

    public function save(array $changes): array
    {
        return DB::transaction(function () use ($changes): array {
            DB::table('platform_settings')->insertOrIgnore([
                'id' => 1, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('platform_settings')->where('id', 1)->lockForUpdate()->first();
            $previous = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
            $settings = array_replace($previous, $changes);
            DB::table('platform_settings')->where('id', 1)->update([
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return array_intersect_key($previous, $changes);
        });
    }

    private function assetUrl(?string $path, string $kind): ?string
    {
        return $path ? '/api/v1/platform/assets/'.$kind.'?v='.substr(hash('sha256', $path), 0, 12) : null;
    }
}
