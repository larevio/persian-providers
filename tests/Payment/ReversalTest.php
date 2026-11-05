<?php

use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Providers\Pasargad;

beforeEach(function () {
    Http::preventStrayRequests();
});

dataset('reversible gateways', [
    'zarinpal' => ['zarinpal', 'https://payment.zarinpal.com/pg/v4/payment/reverse.json', ['data' => ['code' => 100]], [], ['merchant_id' => 'key', 'authority' => 'payment-1']],
    'nextpay' => ['nextpay', 'https://nextpay.org/nx/gateway/verify', ['code' => -90], [], ['api_key' => 'key', 'trans_id' => 'payment-1', 'amount' => 1000, 'refund_request' => 'yes_money_back']],
    'sep' => ['sep', 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction', ['ResultCode' => 0], ['RefNum' => 'reference'], ['RefNum' => 'reference', 'TerminalNumber' => 'terminal']],
    'payping' => ['payping', 'https://api.payping.ir/v3/pay/reverse', ['paymentRefId' => 123, 'amount' => 1000, 'clientRefId' => 'order-1'], ['paymentRefId' => 123], ['paymentCode' => 'payment-1', 'paymentRefId' => 123]],
    'pasargad' => ['pasargad', 'https://pep.shaparak.ir/dorsa1/api/payment/reverse-transactions', ['resultCode' => 0], [], ['invoice' => 'order-1', 'urlId' => 'payment-1']],
    'damavand' => ['damavand', 'https://ecd.shaparak.ir/ipg_ecd/PayReverse', ['State' => 1, 'Res' => 'order-1'], [], ['Token' => 'payment-1']],
    'sepehr' => ['sepehr', 'https://sepehr.shaparak.ir/Rest/V1/PeymentApi/Rollback', ['Status' => 'Ok', 'ReturnId' => '10000', 'Message' => 'OK'], ['digitalreceipt' => 'receipt'], ['digitalreceipt' => 'receipt', 'Tid' => 'terminal']],
]);

it('reverses using the documented request and response', function ($name, $url, $reply, $extra, $payload) {
    Http::fake([
        $url => Http::response($reply),
        'https://pep.shaparak.ir/dorsa1/token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
    ]);

    $payment = payment($name)->id('payment-1')->amount(1000, Currency::IRT)->order('order-1')->extra([
        ...$extra, 'Token' => 'wrong', 'authority' => 'wrong', 'amount' => 1, 'merchant_id' => 'wrong',
    ]);
    expect($payment->supportsReverse())->toBeTrue();
    $result = $payment->reverse();

    expect($result)->toBeInstanceOf(ReversedPayment::class)
        ->and($result->paymentId)->toBe('payment-1')
        ->and($result->alreadyReversed)->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === $url && $request->data() === $payload);
    if ($name === 'payping' || $name === 'pasargad') {
        Http::assertSent(fn ($request) => $request->url() === $url && $request->hasHeader('Authorization', 'Bearer '.($name === 'payping' ? 'key' : 'jwt')));
    }
    Http::assertSentCount($name === 'pasargad' ? 2 : 1);
})->with('reversible gateways');

it('rejects malformed, unsuccessful, and HTTP error reversal responses without retrying', function ($name, $url, $reply, $extra) {
    foreach ([Http::response([]), Http::response(['code' => 'invalid', 'ResultCode' => 'invalid', 'State' => 'invalid', 'resultCode' => 'invalid']), Http::response($reply, 500)] as $response) {
        Http::fake([
            $url => $response,
            'https://pep.shaparak.ir/dorsa1/token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
        ]);

        expect(fn () => PaymentProvider::make($name)->reverse('payment-1', 10000, 'order-1', $extra))
            ->toThrow(RuntimeException::class);
        Http::assertSentCount($name === 'pasargad' ? 2 : 1);
    }
})->with('reversible gateways');

it('rejects missing reversal inputs before sending a request', function ($name, $id, $amount, $order, $extra) {
    Http::fake();
    expect(fn () => PaymentProvider::make($name)->reverse($id, $amount, $order, $extra))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['zarinpal', null, null, null, []],
    ['nextpay', 'id', null, null, []],
    ['nextpay', 'id', 5, null, []],
    ['sep', 'id', null, null, []],
    ['payping', 'id', null, null, []],
    ['payping', 'id', null, null, ['paymentRefId' => 'invalid']],
    ['pasargad', 'id', null, null, []],
    ['damavand', 'id', null, null, []],
    ['sepehr', 'id', null, null, []],
]);

it('only advertises reversal for implemented gateways', function ($name) {
    Http::fake();
    expect(PaymentProvider::make($name))->not->toBeInstanceOf(Reversible::class);
    $payment = payment($name)->id('payment-1');
    expect($payment->supportsReverse())->toBeFalse();
    expect(fn () => $payment->reverse())->toThrow(BadMethodCallException::class, 'does not support reversal');
    Http::assertNothingSent();
})->with(['zibal', 'idpay', 'aqayepardakht', 'panapal', 'paystar', 'vandar', 'sadad']);

it('recognizes duplicate Sepehr reversals and reports the reversed amount', function () {
    Http::fake(['https://sepehr.shaparak.ir/Rest/V1/PeymentApi/Rollback' => Http::response(['Status' => 'Duplicate', 'ReturnId' => '10000', 'Message' => 'Duplicate'])]);
    $result = PaymentProvider::make('sepehr')->reverse('token', extra: ['digitalreceipt' => 'receipt']);
    expect($result->alreadyReversed)->toBeTrue()->and($result->amount)->toBe(10000)->and($result->referenceId)->toBe('receipt');
});

it('allows reversal without an amount when the provider does not require one', function () {
    Http::fake(['https://payment.zarinpal.com/pg/v4/payment/reverse.json' => Http::response(['data' => ['code' => 100]])]);
    expect(payment('zarinpal')->id('authority')->reverse()->amount)->toBeNull();
});

it('recognizes duplicate SEP reversals', function () {
    Http::fake(['https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction' => Http::response(['ResultCode' => 2])]);
    $result = PaymentProvider::make('sep')->reverse('token', extra: ['RefNum' => 'reference']);
    expect($result->alreadyReversed)->toBeTrue()->and($result->referenceId)->toBe('reference');
});

it('protects the NextPay refund flag and reports the reversed amount in rials', function () {
    Http::fake(['https://nextpay.org/nx/gateway/verify' => Http::response(['code' => -90])]);
    $result = PaymentProvider::make('nextpay')->reverse('id', 10000, extra: ['amount' => 1, 'refund_request' => 'no']);
    expect($result->amount)->toBe(10000);
    Http::assertSent(fn ($request) => $request['amount'] === 1000 && $request['refund_request'] === 'yes_money_back');
});

it('never turns NextPay verification into a reversal through extra fields', function () {
    Http::fake(['https://nextpay.org/nx/gateway/verify' => Http::response(['code' => 0, 'amount' => 1000, 'Shaparak_Ref_Id' => 'ref'])]);
    PaymentProvider::make('nextpay')->verify('id', 10000, extra: ['refund_request' => 'yes_money_back']);
    Http::assertSent(fn ($request) => ! array_key_exists('refund_request', $request->data()));
});

it('creates reversible PayPing payments with the v3 contract', function () {
    Http::fake(['https://api.payping.ir/v3/pay' => Http::response(['paymentCode' => 'code', 'url' => 'https://api.payping.ir/v3/pay/start/code'])]);
    $pending = payment('payping')->amount(1000, Currency::IRT)->description('Purchase')->callback('https://example.com/callback')->extra(['isReversible' => true])->create();
    expect($pending->id)->toBe('code')->and($pending->url)->toBe('https://api.payping.ir/v3/pay/start/code');
    Http::assertSent(fn ($request) => $request['isReversible'] === true && $request['amount'] === 1000 && $request['description'] === 'Purchase');
});

it('reports the PayPing reversed amount in rials', function () {
    Http::fake(['*' => Http::response(['paymentRefId' => 123, 'amount' => 1000])]);
    expect(PaymentProvider::make('payping')->reverse('code', extra: ['paymentRefId' => 123])->amount)->toBe(10000);
});

it('does not treat pending PayPing operations as successful', function ($method, $status) {
    Http::fake(['https://api.payping.ir/v3/pay/'.$method => Http::response(['paymentRefId' => 123, 'amount' => 1000], $status)]);
    expect(fn () => PaymentProvider::make('payping')->$method('code', 10000, extra: ['paymentRefId' => 123]))->toThrow(ProviderException::class);
    Http::assertSentCount(1);
})->with(['verify', 'reverse'])->with([202, 502, 409]);

it('binds PayPing operations to the original reference, amount and order', function ($method, $reply) {
    Http::fake(['https://api.payping.ir/v3/pay/'.$method => Http::response($reply)]);
    expect(fn () => PaymentProvider::make('payping')->$method('code', 10000, 'order-1', ['paymentRefId' => 123]))->toThrow(PaymentVerificationException::class);
})->with(['verify', 'reverse'])->with([
    [['paymentRefId' => 456, 'amount' => 1000, 'clientRefId' => 'order-1']],
    [['paymentRefId' => 123, 'amount' => 999, 'clientRefId' => 'order-1']],
    [['paymentRefId' => 123, 'amount' => 1000, 'clientRefId' => 'another-order']],
]);

it('keeps Pasargad verification reversible until explicit confirmation', function () {
    $base = 'https://pep.shaparak.ir/dorsa1/';
    $transaction = ['resultCode' => 0, 'data' => ['invoice' => 'order-1', 'referenceNumber' => 'reference', 'amount' => 10000]];
    Http::fake([
        $base.'token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
        $base.'api/payment/verify-transactions' => Http::response($transaction),
        $base.'api/payment/confirm-transactions' => Http::response($transaction),
    ]);
    $gateway = new Pasargad;
    $gateway->verify('id', 10000, 'order-1');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), 'confirm-transactions'));
    expect($gateway->confirm('id', 10000, 'order-1')->referenceId)->toBe('reference');
    Http::assertSent(fn ($request) => $request->url() === $base.'api/payment/confirm-transactions' && $request['invoice'] === 'order-1' && $request['urlId'] === 'id');
    Http::assertSentCount(4);
});

it('rejects a mismatched Damavand reversed order', function () {
    Http::fake(['https://ecd.shaparak.ir/ipg_ecd/PayReverse' => Http::response(['State' => 1, 'Res' => 'another-order'])]);
    expect(fn () => PaymentProvider::make('damavand')->reverse('token', orderId: 'order-1'))
        ->toThrow(PaymentVerificationException::class, 'does not match the requested order');
});
