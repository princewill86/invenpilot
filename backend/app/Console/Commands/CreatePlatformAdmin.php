<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'invenpilot:admin {email}';

    protected $description = 'Create a platform administrator with a prompted password';

    public function handle(Platform $platform): int
    {
        $email = Str::lower($this->argument('email'));
        $input = ['email' => $email, 'password' => $this->secret('Password')];
        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'min:'.$platform->settings()['password_minimum'], 'regex:/\A[^\x00]{1,72}\z/'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = new User(['name' => 'Platform Administrator', ...$input]);
        $user->forceFill(['platform_admin' => true])->save();
        $this->info('Platform administrator created.');

        return self::SUCCESS;
    }
}
