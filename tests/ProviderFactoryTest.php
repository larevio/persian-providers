<?php

use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;
use Larevio\PersianProviders\Exchange\Providers\Bitpin;
use Larevio\PersianProviders\Inquiry\Abstracts\InquiryProvider;
use Larevio\PersianProviders\Inquiry\Providers\Jibit;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Providers\Zarinpal;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Providers\SmsIr;

it('resolves providers with the same factory signature', function ($factory, $name, $class, $key) {
    $provider = $factory::make($name, [$key => 'test-credential']);

    expect($provider)->toBeInstanceOf($class)
        ->and($provider->getConfig($key))->toBe('test-credential');
})->with([
    [PaymentProvider::class, 'zarinpal', Zarinpal::class, 'token'],
    [SmsProvider::class, 'smsir', SmsIr::class, 'token'],
    [InquiryProvider::class, 'jibit', Jibit::class, 'api_key'],
    [ExchangeProvider::class, 'bitpin', Bitpin::class, 'token'],
]);

it('passes configuration through the inquiry and exchange helpers', function () {
    expect(inquiry('jibit', ['api_key' => 'inquiry-test'])->getConfig('api_key'))->toBe('inquiry-test')
        ->and(exchange('bitpin', ['token' => 'exchange-test'])->getConfig('token'))->toBe('exchange-test');
});

it('selects providers the same way in every helper, without needing a default', function () {
    config(['persianproviders.payment.default' => null, 'persianproviders.sms.default' => null, 'persianproviders.inquiry.default' => null, 'persianproviders.exchange.default' => null]);

    expect(payment('zibal')->supportsReverse())->toBeFalse()
        ->and(sms('kavenegar')->supportsBalance())->toBeTrue()
        ->and(inquiry('zohal'))->toBeInstanceOf(InquiryProvider::class)
        ->and(exchange('nobitex'))->toBeInstanceOf(ExchangeProvider::class);

    foreach (['payment' => fn () => payment(), 'sms' => fn () => sms(), 'inquiry' => fn () => inquiry(), 'exchange' => fn () => exchange()] as $namespace => $helper) {
        expect($helper)->toThrow(ConfigurationException::class, "No default $namespace provider is configured.");
    }
});

it('registers configuration for every selectable provider', function ($factory, $namespace, $name) {
    expect($factory::make($name))->toBeInstanceOf($factory)
        ->and(config("persianproviders.$namespace.$name"))->toBeArray();
})->with([
    [PaymentProvider::class, 'payment', 'zarinpal'],
    [PaymentProvider::class, 'payment', 'zibal'],
    [PaymentProvider::class, 'payment', 'idpay'],
    [PaymentProvider::class, 'payment', 'nextpay'],
    [PaymentProvider::class, 'payment', 'aqayepardakht'],
    [PaymentProvider::class, 'payment', 'panapal'],
    [PaymentProvider::class, 'payment', 'payping'],
    [PaymentProvider::class, 'payment', 'paystar'],
    [PaymentProvider::class, 'payment', 'sadad'],
    [PaymentProvider::class, 'payment', 'vandar'],
    [PaymentProvider::class, 'payment', 'pasargad'],
    [PaymentProvider::class, 'payment', 'damavand'],
    [PaymentProvider::class, 'payment', 'sep'],
    [PaymentProvider::class, 'payment', 'sepehr'],
    [SmsProvider::class, 'sms', 'kavenegar'],
    [SmsProvider::class, 'sms', 'smsir'],
    [SmsProvider::class, 'sms', 'ghasedak'],
    [SmsProvider::class, 'sms', 'sabanovin'],
    [SmsProvider::class, 'sms', 'melipayamak'],
    [SmsProvider::class, 'sms', 'farapayamak'],
    [InquiryProvider::class, 'inquiry', 'zohal'],
    [InquiryProvider::class, 'inquiry', 'jibit'],
    [ExchangeProvider::class, 'exchange', 'nobitex'],
    [ExchangeProvider::class, 'exchange', 'bitpin'],
    [ExchangeProvider::class, 'exchange', 'wallex'],
]);
