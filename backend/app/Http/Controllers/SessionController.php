<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterBusinessRequest;
use App\Models\Business;
use App\Models\User;
use App\Services\Invitations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function register(RegisterBusinessRequest $request, Invitations $invitations): JsonResponse
    {
        $input = $request->validated();
        $settings = $request->attributes->get('registration_settings');
        abort_if(empty($input['token']) && ! $settings['registration_enabled'], 403, 'Public registration is currently closed.');

        try {
            // The new user and their first membership must commit together.
            $user = DB::transaction(function () use ($input, $invitations, $settings): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);
                $user->forceFill(['legal_accepted_at' => now(), 'legal_version' => $input['legal_version']])->save();
                if (! empty($input['token'])) {
                    $invitations->accept($user, $input['token']);
                } else {
                    $business = Business::create([
                        'name' => $input['business_name'],
                        'currency' => $input['currency'] ?? $settings['default_currency'],
                    ]);
                    $user->businesses()->attach($business, ['role' => 'owner']);
                }

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie(Auth::guard('web')->user()->getAuthPassword()));

        return response()->json($this->account($user), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt([...$request->validated(), 'suspended_at' => null])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie(Auth::guard('web')->user()->getAuthPassword()));

        return response()->json($this->account($request->user()));
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->account($request->user()));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function account(User $user): array
    {
        return [
            'user' => $user->only(['id', 'name', 'email', 'platform_admin']),
            'businesses' => $user->businesses()->orderBy('businesses.id')->get()->map(
                fn (Business $business): array => [
                    ...$business->only(['id', 'name', 'currency', 'suspended_at']),
                    'role' => $business->pivot->role,
                ],
            ),
        ];
    }
}
