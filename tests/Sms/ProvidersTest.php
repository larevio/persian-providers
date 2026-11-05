<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Exceptions\AuthenticationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;

it('sends Kavenegar messages and lookup templates with documented form fields', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.kavenegar.com/v1/key/sms/send.json' => Http::response(['return' => ['status' => 200, 'message' => 'Accepted']]),
        'https://api.kavenegar.com/v1/key/verify/lookup.json' => Http::response(['return' => ['status' => 200, 'message' => 'Accepted']]),
    ]);

    $gateway = SmsProvider::make('kavenegar', ['token' => 'key', 'line' => '1000']);
    $sent = $gateway->sendBulk(['09120000000', '09120000001'], 'Hello');
    $verified = $gateway->sendPattern('login', ['token' => '1234', 'token2' => 'Ali'], '09120000000');

    expect($sent)->toBeTrue();
    expect($verified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.kavenegar.com/v1/key/sms/send.json'
        && $request->data()['receptor'] === '09120000000,09120000001'
        && $request->data()['sender'] === '1000');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.kavenegar.com/v1/key/verify/lookup.json'
        && $request->data()['template'] === 'login'
        && $request->data()['token'] === '1234'
        && $request->data()['token2'] === 'Ali');
});

it('sends Ghasedak single, bulk, and OTP messages with documented fields', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS' => Http::response(['IsSuccess' => true, 'StatusCode' => 200]),
        'https://gateway.ghasedak.me/rest/api/v1/WebService/SendBulkSMS' => Http::response(['IsSuccess' => true, 'StatusCode' => 200]),
        'https://gateway.ghasedak.me/rest/api/v1/WebService/SendOtpSMS' => Http::response(['IsSuccess' => true, 'StatusCode' => 200]),
    ]);

    $gateway = SmsProvider::make('ghasedak', ['token' => 'key', 'line' => '2100']);
    $sent = $gateway->send('09120000000', 'Hello');
    $bulk = $gateway->sendBulk(['09120000000', '09120000001'], 'Hello');
    $verified = $gateway->sendPattern('login', ['Code' => '1234'], '09120000000');

    expect($sent)->toBeTrue();
    expect($bulk)->toBeTrue();
    expect($verified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS'
        && $request->data()['receptor'] === '09120000000'
        && $request->hasHeader('ApiKey', 'key'));
    Http::assertSent(fn ($request) => $request->url() === 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendBulkSMS'
        && $request->data()['receptors'] === ['09120000000', '09120000001']);
    Http::assertSent(fn ($request) => $request->url() === 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendOtpSMS'
        && $request->data()['templateName'] === 'login'
        && $request->data()['inputs'] === [['param' => 'Code', 'value' => '1234']]
        && $request->data()['receptors'][0]['mobile'] === '09120000000'
        && $request->data()['receptors'][0]['clientReferenceId'] !== '');
});

it('sends SMS.ir bulk and verification messages with documented fields', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.sms.ir/v1/send/bulk' => Http::response(['status' => 1, 'message' => 'Successful']),
        'https://api.sms.ir/v1/send/verify' => Http::response(['status' => 1, 'message' => 'Successful']),
    ]);

    $gateway = SmsProvider::make('smsir', ['token' => 'key', 'line' => 3000]);
    $sent = $gateway->send('09120000000', 'Hello');
    $verified = $gateway->sendPattern(123, ['Code' => '1234'], '09120000000');

    expect($sent)->toBeTrue();
    expect($verified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.sms.ir/v1/send/bulk'
        && $request->data()['messageText'] === 'Hello'
        && $request->data()['mobiles'] === ['09120000000']
        && $request->hasHeader('X-API-KEY', 'key'));
    Http::assertSent(fn ($request) => $request->url() === 'https://api.sms.ir/v1/send/verify'
        && $request->data()['templateId'] === 123
        && $request->data()['parameters'] === [['name' => 'Code', 'value' => '1234']]);
});

it('sends MeliPayamak and FaraPayamak using their own credentials', function ($provider) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://rest.payamak-panel.com/api/SendSMS/SendSMS' => Http::response(['RetStatus' => 1, 'Value' => '123']),
        'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' => Http::response(['RetStatus' => 1, 'Value' => '124']),
    ]);

    $gateway = SmsProvider::make($provider, ['username' => $provider, 'password' => 'secret', 'line' => '5000']);
    $sent = $gateway->sendBulk(['09120000000', '09120000001'], 'Hello');
    $verified = $gateway->sendPattern(123, ['1234', 'Ali'], '09120000000');

    expect($sent)->toBeTrue();
    expect($verified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://rest.payamak-panel.com/api/SendSMS/SendSMS'
        && $request->data()['username'] === $provider
        && $request->data()['to'] === '09120000000,09120000001');
    Http::assertSent(fn ($request) => $request->url() === 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber'
        && $request->data()['bodyId'] === 123
        && $request->data()['text'] === '1234;Ali');
})->with(['melipayamak', 'farapayamak']);

it('throws the provider exception when an SMS API rejects a request', function ($provider, $config, $url, $reply, $exception, $code) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);

    $error = null;
    try {
        SmsProvider::make($provider, $config)->send('09120000000', 'Hello');
    } catch (Throwable $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf($exception)
        ->and($error->getCode())->toBe($code)
        ->and($error->provider)->toBe($provider);
})->with([
    ['kavenegar', ['token' => 'key', 'line' => ''], 'https://api.kavenegar.com/v1/key/sms/send.json', ['return' => ['status' => 418, 'message' => 'Insufficient credit']], ProviderException::class, 418],
    ['smsir', ['token' => 'key', 'line' => 3000], 'https://api.sms.ir/v1/send/bulk', ['status' => 10, 'message' => 'Invalid API key'], ProviderException::class, 10],
    ['melipayamak', ['username' => 'user', 'password' => 'pass', 'line' => '5000'], 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', ['RetStatus' => 0, 'Value' => '12'], ProviderException::class, 12],
    ['farapayamak', ['username' => 'user', 'password' => 'pass', 'line' => '5000'], 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', ['RetStatus' => 0, 'Value' => '12'], ProviderException::class, 12],
    ['ghasedak', ['token' => 'key', 'line' => ''], 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS', ['IsSuccess' => false, 'StatusCode' => 418, 'Message' => 'Insufficient credit'], ProviderException::class, 418],
]);

it('keeps an SMS.ir HTTP error as a provider exception', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.sms.ir/v1/send/bulk' => Http::response(['status' => 10, 'message' => 'Invalid API key'], 401)]);

    $error = null;
    try {
        SmsProvider::make('smsir', ['token' => 'key', 'line' => 3000])->send('09120000000', 'Hello');
    } catch (Throwable $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(AuthenticationException::class);
    expect($error->getCode())->toBe(10);
});

it('gives every Ghasedak bulk send the required client reference', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['IsSuccess' => true, 'StatusCode' => 200])]);

    SmsProvider::make('ghasedak')->sendBulk(['09120000000'], 'Hello');

    Http::assertSent(fn ($request) => is_string($request['clientReferenceId']) && $request['clientReferenceId'] !== '' && ! isset($request['lineNumber']));
});
