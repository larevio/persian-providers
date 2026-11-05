<?php

use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;

it('converts between rials and tomans, rounding tomans down', function () {
    expect(Currency::IRT->toRials(1250))->toBe(12500)
        ->and(Currency::IRR->toRials(1250))->toBe(1250)
        ->and(Currency::IRT->fromRials(12500))->toBe(1250)
        ->and(Currency::IRT->fromRials(12509))->toBe(1250)
        ->and(Currency::IRR->fromRials(12505))->toBe(12505)
        ->and(Currency::IRT->toRials(0))->toBe(0);
});

it('refuses negative, overflowing and non-fiat conversions', function (Currency $currency, string $method, int $amount) {
    expect(fn () => $currency->$method($amount))->toThrow(InvalidArgumentException::class);
})->with([
    'negative amount' => [Currency::IRT, 'toRials', -1],
    'negative rials' => [Currency::IRR, 'fromRials', -10],
    'overflow' => [Currency::IRT, 'toRials', PHP_INT_MAX],
    'crypto' => [Currency::BTC, 'toRials', 1],
]);

it('requires the caller to name the unit of a payment amount', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => ['code' => 100, 'authority' => 'A1']])]);

    payment()->amount(1000, Currency::IRT)->callback('https://example.com/callback')->create();
    payment()->amount(1000, Currency::IRR)->callback('https://example.com/callback')->create();

    Http::assertSent(fn ($request) => $request['amount'] === 10000 && $request['currency'] === 'IRR');
    Http::assertSent(fn ($request) => $request['amount'] === 1000 && $request['currency'] === 'IRR');
});

it('rejects amounts below one toman on toman gateways before sending', function ($name) {
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => PaymentProvider::make($name)->create(9, 'Order', 'order-1', callback: 'https://example.com/callback'))
        ->toThrow(InvalidArgumentException::class, 'at least one IRT');
    Http::assertNothingSent();
})->with(['nextpay', 'aqayepardakht', 'panapal', 'payping']);

it('rounds rial amounts down to whole tomans for toman gateways', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://nextpay.org/nx/gateway/token' => Http::response(['code' => -1, 'trans_id' => 'nx-1']),
        'https://nextpay.org/nx/gateway/verify' => Http::response(['code' => 0, 'amount' => 1000, 'order_id' => 'order-1', 'Shaparak_Ref_Id' => 'nx-ref']),
    ]);

    $gateway = PaymentProvider::make('nextpay');
    $gateway->create(10009, 'Order', 'order-1', callback: 'https://example.com/callback');
    $verified = $gateway->verify('nx-1', 10009, 'order-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://nextpay.org/nx/gateway/token' && $request['amount'] === 1000);
    Http::assertSent(fn ($request) => $request->url() === 'https://nextpay.org/nx/gateway/verify' && $request['amount'] === 1000);
    expect($verified->amount)->toBe(10000);
});
