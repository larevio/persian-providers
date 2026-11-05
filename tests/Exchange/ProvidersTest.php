<?php

use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Exceptions\TransportException;
use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;

beforeEach(fn () => Http::preventStrayRequests());

it('uses order book quotes rather than daily highs and converts Bitpin tomans to rials', function () {
    Http::fake([
        'https://api.bitpin.ir/api/v1/mkt/tickers/' => Http::response([['symbol' => 'BTC_IRT', 'price' => '100', 'high' => '500', 'low' => '10', 'timestamp' => 123.5]]),
        'https://api.bitpin.ir/api/v1/mth/orderbook/BTC_IRT/' => Http::response(['asks' => [['101', '2']], 'bids' => [['99.5', '3']]]),
    ]);
    $rate = exchange('bitpin')->getRate(Currency::BTC, Currency::IRR);
    expect($rate->last)->toBe('1000')->and($rate->bestSell)->toBe('1010')->and($rate->bestBuy)->toBe('995')->and($rate->timestamp)->toBe(123);
    Http::assertSentCount(2);
});

it('normalizes Nobitex rial symbols and quote units without floating point', function () {
    Http::fake(['https://apiv2.nobitex.ir/market/stats?srcCurrency=btc&dstCurrency=rls' => Http::response([
        'status' => 'ok', 'stats' => ['btc-rls' => ['latest' => '215130790175', 'bestSell' => '1010', 'bestBuy' => '9']],
    ])]);
    $rate = exchange('nobitex')->getRate(Currency::BTC, Currency::IRT);
    expect($rate->last)->toBe('21513079017.5')->and($rate->bestBuy)->toBe('0.9')->and($rate->bestSell)->toBe('101');
    Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0], 'TraderBot/'));
});

it('inverts the fiat unit when fiat is the base currency', function () {
    Http::fake(['*' => Http::response(['status' => 'ok', 'stats' => ['rls-usdt' => ['latest' => '0.0000037', 'bestSell' => '1', 'bestBuy' => '1']]])]);
    expect(exchange('nobitex')->getRate(Currency::IRT, Currency::USDT)->last)->toBe('0.000037');
});

it('uses the current Wallex market list and allows an empty order book', function () {
    Http::fake([
        'https://api.wallex.ir/hector/web/v1/markets' => Http::response(['success' => true, 'result' => ['markets' => [['symbol' => 'BTCTMN', 'price' => '100']]]]),
        'https://api.wallex.ir/v1/depth?symbol=BTCTMN' => Http::response(['success' => true, 'result' => ['ask' => [], 'bid' => [['price' => 99]]]]),
    ]);
    $rate = exchange('wallex')->getRate(Currency::BTC, Currency::IRT);
    expect($rate->last)->toBe('100')->and($rate->bestSell)->toBeNull()->and($rate->bestBuy)->toBe('99');
});

it('rejects invalid prices instead of reporting a zero rate', function ($price) {
    Http::fake(['*' => Http::response(['status' => 'ok', 'stats' => ['btc-rls' => ['latest' => $price, 'bestSell' => '1', 'bestBuy' => '1']]])]);
    expect(fn () => exchange('nobitex')->getRate(Currency::BTC, Currency::IRR))->toThrow(InvalidResponseException::class);
})->with(['0', '-5', 'NaN', '1e5', null]);

it('rejects business errors even when the exchange returns HTTP 200', function ($provider, $reply) {
    Http::fake(['*' => Http::response($reply)]);
    expect(fn () => exchange($provider)->order('buy', 'btc', 'usdt', '0.01', '100'))->toThrow(ProviderException::class);
    Http::assertSentCount(1);
})->with([['nobitex', ['status' => 'failed', 'code' => 'InsufficientBalance']], ['wallex', ['success' => false, 'result' => []]]]);

it('validates order side and amounts before making exchange requests', function ($provider) {
    Http::fake();
    $gateway = exchange($provider);
    expect(fn () => $gateway->order('sell', 'btc', 'usdt', 0, 100))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->order('other', 'btc', 'usdt', 1, 100))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->order('buy', 'btc', 'usdt', 'NaN', 100))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->order('buy', 'btc', 'usdt', '1e3', 100))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['nobitex', 'wallex']);

it('sends Wallex LIMIT orders with the documented side without losing decimal input', function () {
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
    expect(exchange('wallex')->order('buy', 'btc', 'usdt', '0.000000000000123', '100', ['side' => 'SELL', 'quantity' => 5]))->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.wallex.ir/v1/account/orders'
        && $request['side'] === 'BUY' && $request['quantity'] === '0.000000000000123'
        && $request['type'] === 'LIMIT' && $request['symbol'] === 'BTCUSDT' && $request->hasHeader('X-API-Key', 'key'));
});

it('returns a Nobitex withdrawal request and confirms only when explicitly called', function () {
    Http::fake([
        'https://apiv2.nobitex.ir/users/wallets/withdraw' => Http::response(['status' => 'ok', 'withdraw' => ['id' => 42, 'status' => 'New']]),
        'https://apiv2.nobitex.ir/users/wallets/withdraw-confirm' => Http::response(['status' => 'ok']),
    ]);
    $gateway = exchange('nobitex');
    $withdrawal = $gateway->withdraw(10, 'destination', '0.125', 'TRX', ['totp' => '123456', 'tag' => 'memo', 'amount' => 99]);
    expect($withdrawal['id'])->toBe(42);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['amount'] === '0.125' && $request['tag'] === 'memo'
        && ! isset($request['totp']) && $request->hasHeader('X-TOTP', '123456') && $request->hasHeader('Authorization', 'Token key'));
    expect($gateway->confirmWithdrawal($withdrawal['id'], '654321'))->toBeTrue();
    Http::assertSent(fn ($request) => $request->data() === ['withdraw' => 42, 'otp' => '654321']);
    Http::assertSentCount(2);
});

it('sends fiat withdrawals in each exchange unit from a rial amount', function ($provider, $url, $field, $expected) {
    Http::fake(['*' => Http::response(['status' => 'ok', 'success' => true])]);
    expect(exchange($provider)->withdrawFiat(13568, 25000000))->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === $url && $request[$field] === $expected);
})->with([
    ['nobitex', 'https://apiv2.nobitex.ir/cobank/withdraw', 'amount', '25000000'],
    ['wallex', 'https://api.wallex.ir/v1/account/money-withdrawal', 'value', 2500000],
]);

it('rounds fiat withdrawals down to whole tomans for toman exchanges', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    expect(exchange('wallex')->withdrawFiat(1, 25000009))->toBeTrue();
    Http::assertSent(fn ($request) => $request['value'] === 2500000);
});

it('refuses fiat withdrawals below one unit of the exchange currency', function () {
    Http::fake();
    expect(fn () => exchange('wallex')->withdrawFiat(1, 9))->toThrow(InvalidArgumentException::class, 'at least one IRT');
    expect(fn () => exchange('nobitex')->withdrawFiat(1, 0))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('does not retry an ambiguous exchange submission', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::failedConnection();
    });
    expect(fn () => exchange('nobitex')->withdraw(1, 'destination', 1))->toThrow(TransportException::class);
    expect($attempts)->toBe(1);
});

it('uses Bitpin v2 deposit endpoints and requires an explicit network', function () {
    Http::fake([
        'https://api.bitpin.ir/api/v2/wlt/address/' => Http::response(['currency' => 'USDT', 'network' => 2, 'address' => 'deposit', 'memo' => '']),
        'https://api.bitpin.ir/api/v2/wlt/networks/?currency_code=USDT' => Http::response([['network' => 'TRX']]),
    ]);
    $gateway = exchange('bitpin');
    expect(fn () => $gateway->getWalletAddress('USDT'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
    expect($gateway->getWalletAddress('USDT', 'TRX'))->toBe('deposit');
    expect($gateway->getNetworks('usdt'))->toBe([['network' => 'TRX']]);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['currency'] === 'USDT' && $request['network'] === 'TRX' && $request->hasHeader('Authorization', 'Bearer key'));
});

it('selects the requested Wallex deposit network', function () {
    Http::fake(['https://api.wallex.ir/v1/account/wallets/usdt?network=trc20' => Http::response([
        'success' => true, 'result' => ['wallets' => ['TRC20' => ['address' => 'selected'], 'ERC20' => ['address' => 'other']]],
    ])]);
    expect(exchange('wallex')->getWalletAddress('USDT', 'TRC20'))->toBe('selected');
});

it('does not fabricate unsupported exchange operations', function ($provider, $method, $arguments) {
    Http::fake();
    expect(fn () => ExchangeProvider::make($provider)->$method(...$arguments))->toThrow(BadMethodCallException::class);
    Http::assertNothingSent();
})->with([
    ['bitpin', 'withdraw', [1, 'destination', '1']],
    ['bitpin', 'order', ['buy', 'btc', 'usdt', '1', '1']],
    ['bitpin', 'withdrawFiat', [1, 10000]],
    ['bitpin', 'getProfile', []],
    ['wallex', 'withdraw', [1, 'destination', '1']],
]);
