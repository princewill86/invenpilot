<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Platform;
use App\Services\PlatformMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordController extends Controller
{
    public function send(Request $request, Platform $platform, PlatformMail $mail): Response
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $settings = $platform->settings();
        if ($settings['password_reset_enabled']) {
            $mail->configure($settings);
            try {
                Password::sendResetLink(['email' => Str::lower($input['email']), 'suspended_at' => null]);
            } catch (TransportExceptionInterface) {
                // Keep the response identical for known and unknown email addresses.
                Log::warning('Password reset mail could not be delivered. Check the configured mail transport.');
            }
        }

        return response()->noContent();
    }

    public function reset(Request $request, Platform $platform): Response
    {
        $settings = $platform->settings();
        abort_unless($settings['password_reset_enabled'], 403);

        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.$settings['password_minimum'], 'confirmed', 'regex:/\A[^\x00]{1,72}\z/'],
        ]);
        $input['email'] = Str::lower($input['email']);
        $status = DB::transaction(function () use ($input): string {
            // Serialize resets for the same identity before verifying and consuming a broker token.
            $user = User::query()->where('email', $input['email'])->lockForUpdate()->first();
            if ($user?->suspended_at !== null) {
                return Password::InvalidToken;
            }

            return Password::reset($input, function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
                }
                event(new PasswordReset($user));
            });
        }, 3);
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or expired. Request another link.']);
        }

        return response()->noContent();
    }
}
