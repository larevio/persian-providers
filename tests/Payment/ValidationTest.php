<?php

use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;

beforeEach(fn () => Http::preventStrayRequests());

it('validates payment amounts, callback URLs and transaction IDs before requests', function ($name) {
    Http::fake();
    $gateway = PaymentProvider::make($name);
    foreach ([0, -10, PHP_INT_MAX] as $amount) {
        expect(fn () => $gateway->create($amount, 'Order', 'order', callback: 'https://example.com/callback'))->toThrow(InvalidArgumentException::class);
    }
    foreach ([null, 'javascript:alert(1)', 'not a URL'] as $callback) {
        expect(fn () => $gateway->create(10000, 'Order', 'order', callback: $callback))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $gateway->verify(null, 10000))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['zarinpal', 'zibal', 'idpay', 'sep', 'sepehr', 'nextpay', 'aqayepardakht', 'panapal', 'payping', 'paystar', 'vandar', 'pasargad', 'sadad', 'damavand']);

it('requires merchant order IDs on providers that need them', function ($name) {
    Http::fake();
    expect(fn () => PaymentProvider::make($name)->create(10000, 'Order', callback: 'https://example.com/callback'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['idpay', 'sep', 'sepehr', 'nextpay', 'paystar', 'pasargad', 'sadad', 'damavand']);

it('requires the order ID when Sepehr verifies a payment', function () {
    Http::fake();
    expect(fn () => PaymentProvider::make('sepehr')->verify('token', 10000, extra: ['digitalreceipt' => 'receipt']))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('rejects malformed success codes instead of casting them to valid codes', function ($name, $reply, $extra) {
    Http::fake(['*' => Http::response($reply)]);
    expect(fn () => PaymentProvider::make($name)->verify('payment', 10000, 'order', $extra))->toThrow(RuntimeException::class);
})->with([
    ['nextpay', ['code' => 'invalid', 'amount' => 1000, 'Shaparak_Ref_Id' => 'ref'], []],
    ['sep', ['ResultCode' => 'invalid', 'TransactionDetail' => ['RRN' => 'ref', 'OrginalAmount' => 10000]], ['RefNum' => 'ref']],
    ['sep', ['ResultCode' => 0, 'Success' => false, 'TransactionDetail' => ['RRN' => 'ref', 'OrginalAmount' => 10000]], ['RefNum' => 'ref']],
    ['sadad', ['ResCode' => 'invalid', 'RetrivalRefNo' => 'ref'], []],
    ['idpay', ['status' => '100invalid', 'track_id' => 'ref'], []],
    ['zarinpal', ['data' => ['code' => '100invalid', 'ref_id' => 'ref']], []],
]);

it('checks the SEP token status', function () {
    Http::fake(['*' => Http::response(['status' => -1, 'errorCode' => '5', 'errorDesc' => 'Invalid parameters'])]);
    expect(fn () => PaymentProvider::make('sep')->create(10000, 'Order', 'order', callback: 'https://example.com/callback'))->toThrow(ProviderException::class, 'Invalid parameters');
});

it('rejects SEP verification details from a different transaction or terminal', function ($field, $value) {
    $detail = ['RefNum' => 'ref', 'TerminalNumber' => 'terminal', 'RRN' => '123', 'OrginalAmount' => 10000];
    $detail[$field] = $value;
    Http::fake(['*' => Http::response(['ResultCode' => 0, 'Success' => true, 'TransactionDetail' => $detail])]);
    expect(fn () => PaymentProvider::make('sep')->verify('payment', 10000, 'order', ['RefNum' => 'ref']))->toThrow(RuntimeException::class);
})->with([['RefNum', 'other'], ['TerminalNumber', 'other'], ['RRN', ''], ['OrginalAmount', 1000]]);

it('rejects Zibal verification for another order', function () {
    Http::fake(['*' => Http::response(['result' => 100, 'amount' => 10000, 'refNumber' => '123', 'orderId' => 'other'])]);
    expect(fn () => PaymentProvider::make('zibal')->verify('payment', 10000, 'order'))->toThrow(PaymentVerificationException::class);
});

it('accepts PayPing duplicate verification only with matching full transaction details', function () {
    Http::fake(['*' => Http::response(['metaData' => ['code' => 110, 'message' => [
        'Code' => 'payment', 'PaymentRefId' => 123, 'Amount' => 1000, 'ClientRefId' => 'order', 'CardNumber' => 'masked',
    ]]], 409)]);
    $verified = PaymentProvider::make('payping')->verify('payment', 10000, 'order', ['paymentRefId' => 123]);
    expect($verified->alreadyVerified)->toBeTrue()->and($verified->referenceId)->toBe('123')->and($verified->amount)->toBe(10000);
});

it('rejects PayPing duplicates with mismatched transaction details', function ($field, $value) {
    $details = ['Code' => 'payment', 'PaymentRefId' => 123, 'Amount' => 1000, 'ClientRefId' => 'order'];
    $details[$field] = $value;
    Http::fake(['*' => Http::response(['metaData' => ['code' => 110, 'message' => $details]], 409)]);
    expect(fn () => PaymentProvider::make('payping')->verify('payment', 10000, 'order', ['paymentRefId' => 123]))->toThrow(PaymentVerificationException::class);
})->with([['Code', 'other'], ['PaymentRefId', 999], ['Amount', 999], ['ClientRefId', 'other']]);

it('does not treat duplicate verification details as a reversal', function () {
    Http::fake(['*' => Http::response(['metaData' => ['code' => 110, 'message' => ['Code' => 'payment', 'PaymentRefId' => 123, 'Amount' => 1000]]], 409)]);
    expect(fn () => PaymentProvider::make('payping')->reverse('payment', 10000, extra: ['paymentRefId' => 123]))->toThrow(ProviderException::class);
});

it('sends IDPay original order and authenticates with the configured token', function () {
    Http::fake(['*' => Http::response(['status' => 100, 'track_id' => 123, 'amount' => 10000, 'order_id' => 'order'])]);
    expect(PaymentProvider::make('idpay', ['token' => 'other-key'])->verify('payment', 10000, 'order')->referenceId)->toBe('123');
    Http::assertSent(fn ($request) => $request['order_id'] === 'order' && $request->hasHeader('X-API-KEY', 'other-key'));
});

it('rejects IDPay verification for another amount', function () {
    Http::fake(['*' => Http::response(['status' => 100, 'track_id' => 123, 'amount' => 1000, 'order_id' => 'order'])]);
    expect(fn () => PaymentProvider::make('idpay')->verify('payment', 10000, 'order'))->toThrow(PaymentVerificationException::class);
});

it('does not truncate fractional or malformed verified amounts into a match', function ($amount) {
    Http::fake(['*' => Http::response(['code' => 0, 'amount' => $amount, 'Shaparak_Ref_Id' => 'ref'])]);
    expect(fn () => PaymentProvider::make('nextpay')->verify('payment', 10000))->toThrow(PaymentVerificationException::class);
})->with(['1000.5', '1000invalid', '1e3', true]);

it('accepts whole decimal provider amounts without losing integer precision', function () {
    Http::fake(['*' => Http::response(['code' => 0, 'amount' => '1000.00', 'Shaparak_Ref_Id' => 'ref'])]);
    expect(PaymentProvider::make('nextpay')->verify('payment', 10000)->amount)->toBe(10000);
});

it('rejects a successful verification that omits its reference', function () {
    Http::fake(['*' => Http::response(['data' => ['code' => 100]])]);
    expect(fn () => PaymentProvider::make('zarinpal')->verify('A1', 10000))->toThrow(InvalidResponseException::class, "invalid 'ref_id'");
});

it('rejects a confirmed amount that differs from the stored order amount', function ($name, $url, $reply, $extra) {
    Http::fake([
        $url => Http::response($reply),
        'https://pep.shaparak.ir/dorsa1/token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
    ]);
    expect(fn () => PaymentProvider::make($name)->verify('payment', 10000, 'order', $extra))->toThrow(PaymentVerificationException::class);
})->with([
    'pasargad' => ['pasargad', 'https://pep.shaparak.ir/dorsa1/api/payment/verify-transactions', ['resultCode' => 0, 'data' => ['invoice' => 'order', 'referenceNumber' => '1', 'amount' => 1000]], []],
    'sadad' => ['sadad', 'https://sadad.shaparak.ir/vpg/api/v0/Advice/Verify', ['ResCode' => 0, 'OrderId' => 'order', 'RetrivalRefNo' => '1', 'Amount' => 1000], []],
    'damavand callback' => ['damavand', 'https://ecd.shaparak.ir/ipg_ecd/PayConfirmation', ['State' => 1, 'Res' => 'order'], ['Amount' => '1000']],
    'panapal' => ['panapal', 'https://paanapardakht.com/webservice/rest/PaymentVerification', ['Status' => 100, 'Amount' => 10000, 'RefID' => '1'], []],
    'paystar' => ['paystar', 'https://core.paystar.ir/api/pardakht/verify', ['status' => 1, 'data' => ['price' => 1000]], ['card_number' => 'c', 'tracking_code' => 't']],
    'sepehr' => ['sepehr', 'https://sepehr.shaparak.ir/Rest/V1/PeymentApi/AdviceWithInvoicId', ['Status' => 'OK', 'ReturnId' => 1000], ['digitalreceipt' => 'r']],
]);

it('rejects a Damavand callback for another payment before confirming it', function () {
    Http::fake();
    expect(fn () => PaymentProvider::make('damavand')->verify('payment', 10000, 'order', ['Token' => 'other']))->toThrow(PaymentVerificationException::class);
    Http::assertNothingSent();
});
