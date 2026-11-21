<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class PlatformMail
{
    public function configure(array $settings): void
    {
        if ($settings['mail_mode'] === 'environment') {
            return;
        }
        $config = $settings['mail_mode'] === 'php_mail'
            ? ['transport' => 'sendmail', 'path' => ini_get('sendmail_path') ?: '/usr/sbin/sendmail -t -i']
            : [
                'transport' => 'smtp',
                'scheme' => $settings['smtp_scheme'],
                'host' => $settings['smtp_host'],
                'port' => $settings['smtp_port'],
                'username' => $settings['smtp_username'],
                'password' => $settings['smtp_password'] ? Crypt::decryptString($settings['smtp_password']) : null,
                'timeout' => 10,
            ];
        config([
            'mail.default' => 'platform_delivery',
            'mail.mailers.platform_delivery' => $config,
            'mail.from.address' => $settings['mail_from_address'],
            'mail.from.name' => $settings['mail_from_name'] ?? $settings['name'],
        ]);
        Mail::purge('platform_delivery');
    }
}
