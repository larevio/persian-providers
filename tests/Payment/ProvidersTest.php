<?php

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Larevio\PersianProviders\Exceptions\AuthenticationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Providers\Idpay;
use Larevio\PersianProviders\Payment\Providers\Zarinpal;

beforeEach(fn () => Http::preventStrayRequests());

it('sends each gateway its documented amount unit and builds the documented redirect', function ($name, $url, $reply, $expectedId, $expectedUrl, $amountField, $expectedAmount, $extra) {
    Http::fake([
        $url => Http::response($reply),
        'https://pep.shaparak.ir/dorsa1/token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
    ]);

    $payment = PaymentProvider::make($name)->create(10000, 'Order payment', 'order-1', '09120000000', null, 'https://example.com/callback', $extra);

    expect($payment->id)->toBe($expectedId)
        ->and($payment->url)->toBe($expectedUrl);

    Http::assertSent(fn ($request) => $request->url() === $url && $request->data()[$amountField] === $expectedAmount);
    Http::assertSentCount($name === 'pasargad' ? 2 : 1);
})->with([
    'zarinpal (rials)' => ['zarinpal', 'https://payment.zarinpal.com/pg/v4/payment/request.json', ['data' => ['code' => 100, 'authority' => 'A1']], 'A1', 'https://payment.zarinpal.com/pg/StartPay/A1', 'amount', 10000, []],
    'zibal (rials)' => ['zibal', 'https://gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => 15966442233311], '15966442233311', 'https://gateway.zibal.ir/start/15966442233311', 'amount', 10000, []],
    'idpay (rials)' => ['idpay', 'https://api.idpay.ir/v1.1/payment', ['id' => 'id-1', 'link' => 'https://idpay.ir/p/ws/id-1'], 'id-1', 'https://idpay.ir/p/ws/id-1', 'amount', 10000, []],
    'nextpay (tomans)' => ['nextpay', 'https://nextpay.org/nx/gateway/token', ['code' => -1, 'trans_id' => 'nx-1'], 'nx-1', 'https://nextpay.org/nx/gateway/payment/nx-1', 'amount', 1000, []],
    'aqayepardakht (tomans)' => ['aqayepardakht', 'https://panel.aqayepardakht.ir/api/v2/create', ['status' => 'success', 'transid' => 'aq-1'], 'aq-1', 'https://panel.aqayepardakht.ir/startpay/aq-1', 'amount', 1000, []],
    'panapal (tomans)' => ['panapal', 'https://paanapardakht.com/webservice/rest/PaymentRequest', ['Status' => 100, 'Authority' => 'pa-1', 'PaymentUrl' => 'https://paanapardakht.com/startPay/pa-1'], 'pa-1', 'https://paanapardakht.com/startPay/pa-1', 'Amount', 1000, []],
    'payping (tomans)' => ['payping', 'https://api.payping.ir/v3/pay', ['paymentCode' => 'pp-1', 'url' => 'https://api.payping.ir/v3/pay/start/pp-1'], 'pp-1', 'https://api.payping.ir/v3/pay/start/pp-1', 'amount', 1000, []],
    'paystar (rials)' => ['paystar', 'https://core.paystar.ir/api/pardakht/create', ['status' => 1, 'data' => ['ref_num' => 'ps-1', 'token' => 'pay-token']], 'ps-1', 'https://core.paystar.ir/api/pardakht/payment?token=pay-token', 'amount', 10000, []],
    'vandar (rials)' => ['vandar', 'https://ipg.vandar.io/api/v3/send', ['status' => 1, 'token' => 'va-1'], 'va-1', 'https://ipg.vandar.io/v3/va-1', 'amount', 10000, []],
    'sadad (rials)' => ['sadad', 'https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest', ['ResCode' => 0, 'Token' => 'sd-1'], 'sd-1', 'https://sadad.shaparak.ir/VPG/Purchase?Token=sd-1', 'Amount', 10000, []],
    'damavand (rials)' => ['damavand', 'https://ecd.shaparak.ir/ipg_ecd/PayRequest', ['State' => 1, 'Res' => 'dm-1'], 'dm-1', 'https://ecd.shaparak.ir/ipg_ecd/PayStart', 'Amount', 10000, []],
    'sep (rials)' => ['sep', 'https://sep.shaparak.ir/onlinepg/onlinepg', ['status' => 1, 'token' => 'sep-1'], 'sep-1', 'https://sep.shaparak.ir/onlinepg/onlinepg', 'Amount', 10000, []],
    'pasargad (rials)' => ['pasargad', 'https://pep.shaparak.ir/dorsa1/api/payment/purchase', ['resultCode' => 0, 'data' => ['urlId' => 'pg-1', 'url' => 'https://pep.shaparak.ir/dorsa1/pg-1']], 'pg-1', 'https://pep.shaparak.ir/dorsa1/pg-1', 'amount', 10000, ['invoiceDate' => '1405-07-18']],
]);

it('authenticates and verifies Pasargad payments', function () {
    Http::fake([
        'https://pep.shaparak.ir/dorsa1/token/getToken' => Http::response(['resultCode' => 0, 'token' => 'jwt']),
        'https://pep.shaparak.ir/dorsa1/api/payment/purchase' => Http::response(['resultCode' => 0, 'data' => ['urlId' => 'pg-1', 'url' => 'https://pep.shaparak.ir/dorsa1/pg-1']]),
        'https://pep.shaparak.ir/dorsa1/api/payment/verify-transactions' => Http::response(['resultCode' => 0, 'data' => ['invoice' => 'order-1', 'referenceNumber' => '123', 'amount' => 10000]]),
    ]);

    $gateway = PaymentProvider::make('pasargad');
    $pending = $gateway->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback', extra: ['invoiceDate' => '1405-07-18']);
    $verified = $gateway->verify($pending->id, 10000, 'order-1');

    expect($pending->id)->toBe('pg-1')
        ->and($verified->referenceId)->toBe('123')
        ->and($verified->amount)->toBe(10000);

    Http::assertSent(fn ($request) => $request->url() === 'https://pep.shaparak.ir/dorsa1/api/payment/purchase'
        && $request->hasHeader('Authorization', 'Bearer jwt'));
    Http::assertSentCount(4);
});

it('verifies documented payment responses and reports rials', function ($name, $url, $reply, $id, $reference, $extra) {
    Http::fake([$url => Http::response($reply)]);

    $verified = PaymentProvider::make($name)->verify($id, 10000, 'order-1', $extra);

    expect($verified->referenceId)->toBe($reference)
        ->and($verified->amount)->toBe(10000)
        ->and($verified->paymentId)->toBe($id);

    Http::assertSentCount(1);
})->with([
    'zarinpal' => ['zarinpal', 'https://payment.zarinpal.com/pg/v4/payment/verify.json', ['data' => ['code' => 100, 'ref_id' => 201, 'fee' => 100]], 'A1', '201', ['Status' => 'OK']],
    'zibal' => ['zibal', 'https://gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 10000, 'refNumber' => 12312, 'orderId' => 'order-1'], '9900', '12312', ['success' => '1']],
    'idpay' => ['idpay', 'https://api.idpay.ir/v1.1/payment/verify', ['status' => '100', 'track_id' => '10012', 'order_id' => 'order-1', 'amount' => '10000'], 'id-1', '10012', ['status' => '10']],
    'nextpay' => ['nextpay', 'https://nextpay.org/nx/gateway/verify', ['code' => 0, 'amount' => 1000, 'order_id' => 'order-1', 'Shaparak_Ref_Id' => 'nx-ref'], 'nx-1', 'nx-ref', []],
    'aqayepardakht' => ['aqayepardakht', 'https://panel.aqayepardakht.ir/api/v2/verify', ['status' => 'success', 'code' => '1'], 'aq-1', 'aq-ref', ['status' => '1', 'tracking_number' => 'aq-ref']],
    'panapal' => ['panapal', 'https://paanapardakht.com/webservice/rest/PaymentVerification', ['Status' => 100, 'Amount' => 1000, 'RefID' => 'pa-ref'], 'pa-1', 'pa-ref', ['PaymentStatus' => 'OK']],
    'payping' => ['payping', 'https://api.payping.ir/v3/pay/verify', ['amount' => 1000, 'paymentRefId' => 123, 'clientRefId' => 'order-1'], 'pp-1', '123', ['paymentRefId' => 123]],
    'paystar' => ['paystar', 'https://core.paystar.ir/api/pardakht/verify', ['status' => 1, 'data' => ['price' => 10000, 'card_number' => '603799******7999']], 'ps-1', 'ps-ref', ['status' => '1', 'card_number' => '603799******7999', 'tracking_code' => 'ps-ref']],
    'vandar' => ['vandar', 'https://ipg.vandar.io/api/v3/verify', ['status' => 1, 'amount' => '10000.00', 'wage' => '0', 'factorNumber' => 'order-1', 'transId' => 123], 'va-1', '123', ['payment_status' => 'OK']],
    'sadad' => ['sadad', 'https://sadad.shaparak.ir/vpg/api/v0/Advice/Verify', ['ResCode' => 0, 'OrderId' => 'order-1', 'RetrivalRefNo' => 'sd-ref'], 'sd-1', 'sd-ref', ['ResCode' => '0']],
    'damavand' => ['damavand', 'https://ecd.shaparak.ir/ipg_ecd/PayConfirmation', ['State' => 1, 'Res' => 'order-1'], 'dm-1', 'dm-ref', ['State' => '1', 'Amount' => '10000', 'Token' => 'dm-1', 'ReferenceNumber' => 'dm-ref']],
    'sep' => ['sep', 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction', ['ResultCode' => 0, 'Success' => true, 'TransactionDetail' => ['OrginalAmount' => 10000, 'RRN' => 'rrn']], 'token', 'rrn', ['RefNum' => 'ref', 'State' => 'OK']],
]);

it('returns false for unpaid callbacks without calling the provider', function ($name, $extra) {
    Http::fake();
    expect(PaymentProvider::make($name)->verify('payment', 10000, 'order-1', $extra))->toBeFalse();
    Http::assertNothingSent();
})->with([
    ['zarinpal', ['Status' => 'NOK']],
    ['zibal', ['success' => '0']],
    ['idpay', ['status' => '7']],
    ['aqayepardakht', ['status' => '0']],
    ['panapal', ['PaymentStatus' => 'NOK']],
    ['paystar', ['status' => '-1']],
    ['vandar', ['payment_status' => 'FAILED']],
    ['sadad', ['ResCode' => '-1']],
    ['damavand', ['State' => '0']],
    ['sep', ['RefNum' => 'ref', 'State' => 'CanceledByUser']],
    ['sep', []],
    ['sepehr', []],
    ['sepehr', ['digitalreceipt' => 'receipt', 'respcode' => '-1']],
    ['pasargad', ['status' => 'cancel']],
]);

it('reports provider fees in rials', function () {
    Http::fake(['*' => Http::response(['data' => ['code' => 100, 'ref_id' => 201, 'fee' => 1500]])]);
    expect(PaymentProvider::make('zarinpal')->verify('A1', 10000)->fee)->toBe(1500);
});

it('signs PayStar requests using the original rial amount', function () {
    Http::fake(['https://core.paystar.ir/api/pardakht/create' => Http::response(['status' => 1, 'data' => ['ref_num' => 'ps-1', 'token' => 'token']])]);

    PaymentProvider::make('paystar', ['sign_key' => 'secret'])
        ->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback');

    Http::assertSent(fn ($request) => $request->data()['sign'] === hash_hmac('sha512', '10000#order-1#https://example.com/callback', 'secret')
        && $request->hasHeader('Authorization', 'Bearer gateway'));
});

it('uses AqayePardakht sandbox credentials and redirect together', function () {
    Http::fake(['https://panel.aqayepardakht.ir/api/v2/create' => Http::response(['status' => 'success', 'transid' => 'aq-1'])]);

    $payment = PaymentProvider::make('aqayepardakht', ['pin' => '', 'sandbox' => true])
        ->create(10000, 'Order payment', callback: 'https://example.com/callback');

    expect($payment->url)->toBe('https://panel.aqayepardakht.ir/startpay/sandbox/aq-1');
    Http::assertSent(fn ($request) => $request->data()['pin'] === 'sandbox');
});

it('uses the Zarinpal sandbox hosts when configured', function () {
    Http::fake(['https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'S1']])]);

    $payment = PaymentProvider::make('zarinpal', ['sandbox' => true])->create(10000, 'Order', callback: 'https://example.com/callback');

    expect($payment->url)->toBe('https://sandbox.zarinpal.com/pg/StartPay/S1');
});

it('signs Damavand requests with the documented checksum fields', function () {
    Http::fake(['https://ecd.shaparak.ir/ipg_ecd/PayRequest' => Http::response(['State' => 1, 'Res' => 'token'])]);

    PaymentProvider::make('damavand')->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $data['CheckSum'] === sha1($data['TerminalNumber'].$data['BuyID'].$data['Amount'].$data['Date'].$data['Time'].$data['RedirectURL'].'secret');
    });
});

it('rejects a mismatched verified amount', function () {
    Http::fake(['https://nextpay.org/nx/gateway/verify' => Http::response(['code' => 0, 'amount' => 900, 'Shaparak_Ref_Id' => 'nx-ref'])]);

    expect(fn () => PaymentProvider::make('nextpay')->verify('nx-1', 10000))
        ->toThrow(PaymentVerificationException::class, 'does not match the requested amount');
});

it('reports failed payment requests as provider rejections', function ($name, $extra) {
    Http::fake(['*' => Http::response(['Status' => -6, 'code' => -6, 'error_code' => -6, 'errorCode' => -6, 'ResCode' => -6, 'ErrorCode' => -6, 'resultCode' => -6, 'result' => -6], 422)]);

    expect(fn () => PaymentProvider::make($name)->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback', extra: $extra))
        ->toThrow(ProviderException::class);
})->with([
    ['zarinpal', []], ['zibal', []], ['nextpay', []], ['aqayepardakht', []], ['panapal', []], ['payping', []], ['paystar', []],
    ['vandar', []], ['pasargad', ['invoiceDate' => '1405-07-18']], ['sadad', []], ['damavand', []], ['sep', []], ['sepehr', []], ['idpay', []],
]);

it('distinguishes rejected credentials from other provider errors', function () {
    Http::fake(['*' => Http::response(['error_code' => 12, 'error_message' => 'API key not found'], 403)]);

    try {
        PaymentProvider::make('idpay')->create(10000, 'Order', 'order-1', callback: 'https://example.com/callback');
        $this->fail('Expected an authentication failure.');
    } catch (AuthenticationException $e) {
        expect($e->provider)->toBe('idpay')
            ->and($e->providerCode)->toBe(12)
            ->and($e->httpStatus)->toBe(403)
            ->and($e->getMessage())->toBe('API key not found');
    }
});

it('uses documented provider error messages when a response contains only a code', function ($name, $reply, $message, $extra = []) {
    Http::fake(['*' => Http::response($reply, 422)]);

    expect(fn () => PaymentProvider::make($name)->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback', extra: $extra))
        ->toThrow(ProviderException::class, $message);
})->with([
    ['nextpay', ['code' => -24], 'Invalid amount'],
    ['aqayepardakht', ['code' => -10], 'Amount does not match the transaction'],
    ['panapal', ['Status' => -16], 'Amount does not match the transaction'],
    ['vandar', [], 'Invalid request field'],
    ['zarinpal', ['data' => [], 'errors' => ['code' => -41]], 'Maximum amount is 100,000,000 tomans.'],
    ['pasargad', ['resultCode' => 13055], 'Invalid transaction amount', ['invoiceDate' => '1405-07-18']],
]);

it('opens Zarinpal sessions in rials regardless of extra options', function () {
    Http::fake(['*' => Http::response(['data' => ['code' => 100, 'authority' => 'A1']])]);

    PaymentProvider::make('zarinpal')->create(10000, 'Order', callback: 'https://example.com/callback', extra: ['currency' => 'IRT', 'amount' => 1]);

    Http::assertSent(fn ($request) => $request['currency'] === 'IRR' && $request['amount'] === 10000);
});

it('declares the NextPay unit explicitly and keeps extra fields from replacing the amount', function () {
    Http::fake([
        'https://nextpay.org/nx/gateway/token' => Http::response(['code' => -1, 'trans_id' => 'nx-1']),
        'https://nextpay.org/nx/gateway/verify' => Http::response(['code' => 0, 'amount' => 1000, 'order_id' => 'order-1', 'Shaparak_Ref_Id' => 'nx-ref']),
    ]);

    $gateway = PaymentProvider::make('nextpay');
    $gateway->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback', extra: ['currency' => 'IRR', 'amount' => 1]);
    $gateway->verify('nx-1', 10000, 'order-1', ['currency' => 'IRR', 'amount' => 1]);

    Http::assertSent(fn ($request) => $request->url() === 'https://nextpay.org/nx/gateway/token' && $request['amount'] === 1000 && $request['currency'] === 'IRT');
    Http::assertSent(fn ($request) => $request->url() === 'https://nextpay.org/nx/gateway/verify' && $request['amount'] === 1000 && $request['currency'] === 'IRT');
});

it('keeps optional gateway fields supplied through extra when standard contact fields are empty', function () {
    Http::fake(['https://api.payping.ir/v3/pay' => Http::response(['paymentCode' => 'pp-1', 'url' => 'https://api.payping.ir/v3/pay/start/pp-1'])]);

    PaymentProvider::make('payping')->create(10000, 'Order payment', callback: 'https://example.com/callback', extra: ['payerIdentity' => '09120000000']);

    Http::assertSent(fn ($request) => $request->data()['payerIdentity'] === '09120000000');
});

it('refuses provider redirect URLs that are not HTTPS', function () {
    Http::fake(['*' => Http::response(['id' => 'id-1', 'link' => 'http://attacker.example/pay'])]);

    expect(fn () => PaymentProvider::make('idpay')->create(10000, 'Order', 'order-1', callback: 'https://example.com/callback'))
        ->toThrow(InvalidResponseException::class, 'invalid payment URL');
});

it('does not retry a failed payment request and exposes its provider error', function () {
    Http::fake(['https://api.idpay.ir/v1.1/payment' => Http::response(['error_code' => 14, 'error_message' => 'Gateway not approved'], 406)]);

    expect(fn () => (new Idpay)->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback'))
        ->toThrow(ProviderException::class, 'Gateway not approved');

    Http::assertSentCount(1);
});

it('reads Zarinpal error responses and accepts an already verified payment', function () {
    Http::fake(['https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::sequence()
        ->push(['data' => [], 'errors' => ['code' => -54, 'message' => 'Invalid authority']], 400)
        ->push(['data' => ['code' => 101, 'ref_id' => 123, 'card_pan' => '603799******7999']], 200)]);

    $gateway = new Zarinpal;

    expect(fn () => $gateway->verify('authority', 10000))->toThrow(ProviderException::class, 'Invalid authority');

    $verified = $gateway->verify('authority', 10000);

    expect($verified->alreadyVerified)->toBeTrue()
        ->and($verified->referenceId)->toBe('123')
        ->and($verified->amount)->toBe(10000);
});

it('recognises documented repeat-verification answers as already verified', function ($name, $url, $reply, $extra) {
    Http::fake([$url => Http::response($reply)]);

    expect(PaymentProvider::make($name)->verify('payment', 10000, 'order-1', $extra)->alreadyVerified)->toBeTrue();
})->with([
    ['aqayepardakht', 'https://panel.aqayepardakht.ir/api/v2/verify', ['status' => 'error', 'code' => '2'], []],
    ['paystar', 'https://core.paystar.ir/api/pardakht/verify', ['status' => -6, 'message' => 'retryVerification'], ['card_number' => 'card', 'tracking_code' => 'ref']],
    ['zibal', 'https://gateway.zibal.ir/v1/verify', ['result' => 201, 'amount' => 10000, 'refNumber' => 123, 'orderId' => 'order-1'], []],
]);

it('reads Vandar transaction details when verification was already answered', function () {
    Http::fake([
        'https://ipg.vandar.io/api/v3/verify' => Http::response(['status' => 2, 'errors' => ['already verified']], 422),
        'https://ipg.vandar.io/api/v3/transaction' => Http::response(['status' => 1, 'amount' => '10000', 'transId' => 155058785697, 'factorNumber' => 'order-1', 'code' => 2]),
    ]);

    $verified = PaymentProvider::make('vandar')->verify('token', 10000, 'order-1');

    expect($verified->alreadyVerified)->toBeTrue()->and($verified->referenceId)->toBe('155058785697');
});

it('accepts a Vandar amount that includes the payer fee but no other amount', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['status' => 1, 'amount' => '10500.00', 'wage' => '500', 'transId' => 1])
        ->push(['status' => 1, 'amount' => '10700.00', 'wage' => '500', 'transId' => 1])]);

    $gateway = PaymentProvider::make('vandar');

    expect($gateway->verify('token', 10000)->fee)->toBe(500);
    expect(fn () => $gateway->verify('token', 10000))->toThrow(PaymentVerificationException::class);
});

it('keeps the POST fields required by bank gateway redirects', function () {
    Http::fake([
        'https://sep.shaparak.ir/onlinepg/onlinepg' => Http::response(['status' => 1, 'token' => 'sep-token']),
        'https://sepehr.shaparak.ir/Rest/V1/PeymentApi/GetToken' => Http::response(['Status' => 0, 'Accesstoken' => 'sepehr-token']),
    ]);

    $sep = PaymentProvider::make('sep')->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback');
    $sepehr = PaymentProvider::make('sepehr')->create(10000, 'Order payment', 'order-1', callback: 'https://example.com/callback');

    expect($sep->toArray())->toMatchArray(['method' => 'POST', 'data' => ['Token' => 'sep-token']])
        ->and($sepehr->toArray())->toMatchArray(['method' => 'POST', 'url' => 'https://sepehr.shaparak.ir/Pay', 'data' => ['TerminalID' => 'terminal', 'token' => 'sepehr-token']]);
    Http::assertSent(fn ($request) => $request->url() === 'https://sepehr.shaparak.ir/Rest/V1/PeymentApi/GetToken'
        && $request->data() === ['payload' => '', 'TerminalID' => 'terminal', 'Amount' => '10000', 'InvoiceID' => 'order-1', 'callbackURL' => 'https://example.com/callback']);
});

it('uses the Neo-PG address SEP returns in the X-IPG-Url header', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'token' => 'tok'], 200, ['X-IPG-Url' => 'https://neo-pg.sep.ir/transaction/init'])]);

    $pending = PaymentProvider::make('sep', ['use_neo_ipg' => true, 'use_get_method' => true])
        ->create(10000, 'Order', 'order-1', callback: 'https://example.com/callback');

    expect($pending->url)->toBe('https://neo-pg.sep.ir/transaction/init?token=tok');
});

it('renders an escaped payment form with all provider fields and a manual submit button', function () {
    $pending = new PendingPayment('payment', 'https://bank.example/pay?token="<&', [
        'Token' => new HtmlString('"><script>alert(1)</script>'),
        'order"<id' => '000123',
        'zero' => 0,
    ]);

    try {
        $pending->redirect();
        $this->fail('Expected the payment redirect to send a response.');
    } catch (HttpResponseException $exception) {
        $response = $exception->getResponse();
    }

    expect($response->getStatusCode())->toBe(200);
    $document = new DOMDocument;
    $document->loadHTML($response->getContent());
    $form = $document->getElementById('payment');

    expect($form->getAttribute('action'))->toBe($pending->url)
        ->and($form->getAttribute('method'))->toBe('POST')
        ->and($document->getElementsByTagName('script')->length)->toBe(1)
        ->and($document->getElementsByTagName('button')->item(0)->getAttribute('type'))->toBe('submit');

    $fields = [];
    foreach ($form->getElementsByTagName('input') as $input) {
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }

    expect($fields)->toBe(array_map('strval', $pending->post));
});

it('redirects GET payments directly to the provider URL', function () {
    $pending = new PendingPayment('payment', 'https://bank.example/pay?token=123');

    try {
        $pending->redirect();
        $this->fail('Expected the payment redirect to send a response.');
    } catch (HttpResponseException $exception) {
        $response = $exception->getResponse();
    }

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe($pending->url);
});

it('verifies Sepehr payments with the invoice ID and reports duplicates', function () {
    Http::fake(['https://sepehr.shaparak.ir/Rest/V1/PeymentApi/AdviceWithInvoicId' => Http::sequence()
        ->push(['Status' => 'Ok', 'ReturnId' => '10000', 'Message' => 'OK'])
        ->push(['Status' => 'Duplicate', 'ReturnId' => '10000', 'Message' => 'Duplicate'])]);

    $gateway = PaymentProvider::make('sepehr');
    $verified = $gateway->verify('token', 10000, 'order-1', ['digitalreceipt' => 'receipt', 'respcode' => '0', 'rrn' => '125014252007']);

    expect($verified->amount)->toBe(10000)
        ->and($verified->paymentId)->toBe('token')
        ->and($verified->referenceId)->toBe('125014252007')
        ->and($verified->alreadyVerified)->toBeFalse()
        ->and($gateway->verify('token', 10000, 'order-1', ['digitalreceipt' => 'receipt'])->alreadyVerified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->data() === ['digitalreceipt' => 'receipt', 'Tid' => 'terminal', 'InvoiceID' => 'order-1']);
});

it('reports the Sepehr error code from ReturnId', function () {
    Http::fake(['https://sepehr.shaparak.ir/Rest/V1/PeymentApi/AdviceWithInvoicId' => Http::response(['Status' => 'NOk', 'ReturnId' => '-7', 'Message' => ''])]);

    try {
        PaymentProvider::make('sepehr')->verify('token', 10000, 'order-1', ['digitalreceipt' => 'receipt']);
        $this->fail('Expected a ProviderException.');
    } catch (ProviderException $e) {
        expect($e->providerCode)->toBe('-7')->and($e->getMessage())->toBe('Invoice ID does not match the original transaction');
    }
});

it('binds a SEP verification to the requested order', function ($extra, $detail) {
    Http::fake(['https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction' => Http::response(['ResultCode' => 0, 'Success' => true, 'TransactionDetail' => $detail])]);

    expect(fn () => PaymentProvider::make('sep')->verify('token', 10000, 'order-1', $extra))
        ->toThrow(PaymentVerificationException::class, 'does not match the requested order');
})->with([
    'callback ResNum' => [['RefNum' => 'ref', 'ResNum' => 'order-2'], ['OrginalAmount' => 10000, 'RRN' => 'rrn']],
    'verified ResNum' => [['RefNum' => 'ref'], ['ResNum' => 'order-2', 'OrginalAmount' => 10000, 'RRN' => 'rrn']],
]);
