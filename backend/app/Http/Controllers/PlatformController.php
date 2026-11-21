<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Services\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformController extends Controller
{
    public function show(Platform $platform): JsonResponse
    {
        return response()->json($platform->publicSettings())->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Platform $platform): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'headline' => ['required', 'string', 'max:130'],
            'description' => ['required', 'string', 'max:450'],
            'support_email' => ['nullable', 'email', 'max:254'],
            'registration_enabled' => ['required', 'boolean'],
            'default_currency' => ['required', 'in_array:currencies.*'],
            'currencies' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'currencies.*' => ['required', 'distinct', 'regex:/^[A-Z]{3}$/D'],
            'seo_title' => ['required', 'string', 'max:100'],
            'seo_description' => ['required', 'string', 'max:300'],
            'search_indexing' => ['required', 'boolean'],
            'password_reset_enabled' => ['required', 'boolean'],
            'password_minimum' => ['required', 'integer', 'between:12,40'],
            'invitation_days' => ['required', 'integer', 'between:1,30'],
            'mail_mode' => ['required', 'in:environment,php_mail,smtp'],
            'mail_from_address' => ['required_if:mail_mode,php_mail,smtp', 'nullable', 'email', 'max:254'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
            'smtp_host' => ['required_if:mail_mode,smtp', 'nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_scheme' => ['required', 'in:smtp,smtps'],
            'smtp_username' => ['nullable', 'string', 'max:254'],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'clear_smtp_password' => ['sometimes', 'boolean'],
            'terms' => ['required', 'string', 'max:15000'],
            'privacy' => ['required', 'string', 'max:15000'],
        ]);
        if (! empty($data['clear_smtp_password'])) {
            $data['smtp_password'] = null;
        } elseif (filled($data['smtp_password'] ?? null)) {
            $data['smtp_password'] = Crypt::encryptString($data['smtp_password']);
        } else {
            unset($data['smtp_password']);
        }
        unset($data['clear_smtp_password']);
        $platform->save($data);

        return $this->adminSettings($platform);
    }

    public function adminSettings(Platform $platform): JsonResponse
    {
        $settings = $platform->settings();

        return response()->json([
            ...$platform->publicSettings(),
            ...Arr::only($settings, ['invitation_days', 'mail_mode', 'mail_from_address', 'mail_from_name', 'smtp_host',
                'smtp_port', 'smtp_scheme', 'smtp_username']),
            'smtp_password_set' => filled($settings['smtp_password']),
        ])->header('Cache-Control', 'no-store');
    }

    public function asset(Platform $platform, string $kind): StreamedResponse
    {
        $path = $platform->settings()[$kind.'_path'];
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function upload(Request $request, Platform $platform, string $kind): JsonResponse
    {
        $limit = $kind === 'favicon' ? 512 : 1600;
        $input = $request->validate(['image' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('1mb'),
            "dimensions:max_width=$limit,max_height=$limit"]]);
        $path = $input['image']->store('platform-branding', 'local');
        abort_unless($path, 503, 'The image could not be stored.');
        try {
            $previous = $platform->save([$kind.'_path' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);

            throw $error;
        }

        $this->deletePreviousImage($previous[$kind.'_path'] ?? null);

        return $this->adminSettings($platform);
    }

    public function removeAsset(Platform $platform, string $kind): JsonResponse
    {
        $previous = $platform->save([$kind.'_path' => null]);
        $this->deletePreviousImage($previous[$kind.'_path'] ?? null);

        return $this->adminSettings($platform);
    }

    private function deletePreviousImage(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'business_count' => Business::count(),
            'user_count' => User::count(),
            'businesses' => Business::query()->latest('id')->paginate(20, ['id', 'name', 'currency', 'created_at']),
        ]);
    }
}
