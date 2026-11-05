<?php

namespace Larevio\PersianProviders\Tests;

use Larevio\PersianProviders\PersianProvidersServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            PersianProvidersServiceProvider::class,
        ];
    }

    /** Fixture credentials, so a test only passes configuration when it asserts on it. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('persianproviders.payment', [
            'default' => 'zarinpal',
            'zarinpal' => ['token' => 'key'],
            'zibal' => ['token' => 'key'],
            'idpay' => ['token' => 'key'],
            'nextpay' => ['token' => 'key'],
            'aqayepardakht' => ['pin' => 'pin'],
            'panapal' => ['merchant_id' => 'merchant'],
            'payping' => ['token' => 'key'],
            'paystar' => ['gateway_id' => 'gateway', 'sign_key' => 'sign'],
            'sadad' => ['merchant_id' => 'merchant', 'terminal_id' => 'terminal', 'key' => base64_encode('123456789012345678901234')],
            'vandar' => ['token' => 'key'],
            'pasargad' => ['username' => 'user', 'password' => 'pass', 'terminal_id' => '123'],
            'damavand' => ['terminal_id' => 'terminal', 'key' => 'secret'],
            'sep' => ['terminal_id' => 'terminal'],
            'sepehr' => ['terminal_id' => 'terminal'],
        ]);
        $app['config']->set('persianproviders.sms', [
            'default' => 'melipayamak',
            'sabanovin' => ['token' => 'key', 'line' => '3000'],
            'melipayamak' => ['username' => 'user', 'password' => 'secret', 'line' => '3000'],
            'farapayamak' => ['username' => 'user', 'password' => 'secret', 'line' => '3000'],
            'smsir' => ['token' => 'key', 'line' => '3000'],
            'kavenegar' => ['token' => 'key', 'line' => ''],
            'ghasedak' => ['token' => 'key', 'line' => ''],
        ]);
        $app['config']->set('persianproviders.inquiry', [
            'default' => 'zohal',
            'zohal' => ['token' => 'key'],
            'jibit' => ['api_key' => 'key', 'secret_key' => 'secret'],
        ]);
        $app['config']->set('persianproviders.exchange', [
            'default' => 'nobitex',
            'nobitex' => ['token' => 'key'],
            'bitpin' => ['token' => 'key'],
            'wallex' => ['token' => 'key'],
        ]);
    }
}
