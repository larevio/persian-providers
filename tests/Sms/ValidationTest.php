<?php

use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;

beforeEach(fn () => Http::preventStrayRequests());

it('sends MeliPayamak and FaraPayamak REST messages as encoded forms', function ($name) {
    Http::fake(['*' => Http::response(['RetStatus' => 1, 'Value' => '123'])]);
    $gateway = SmsProvider::make($name, ['username' => 'user', 'password' => 'secret', 'line' => '3000']);
    expect($gateway->send('09120000000', 'سلام & +'))->toBeTrue();
    expect($gateway->sendPattern(42, ['علی', '1234'], '09120000000'))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/SendSMS/SendSMS') && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request['text'] === 'سلام & +' && $request['isflash'] === false);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/BaseServiceNumber') && $request['text'] === 'علی;1234' && $request['bodyId'] === 42
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
})->with(['melipayamak', 'farapayamak']);

it('sends Kavenegar named multiword tokens without shifting their positions', function () {
    Http::fake(['*' => Http::response(['return' => ['status' => 200]])]);
    SmsProvider::make('kavenegar', ['token' => 'key'])->sendPattern('template', ['token' => '1234', 'token10' => 'First Last', 'token20' => 'Full address'], '09120000000');
    Http::assertSent(fn ($request) => $request['token'] === '1234' && $request['token10'] === 'First Last' && $request['token20'] === 'Full address'
        && ! isset($request['token2']) && ! isset($request['token3']));
});

it('sends Sabanovin patterns with JSON named variables', function () {
    Http::fake(['https://api.sabanovin.com/v1/key/sms/pattern.json' => Http::response(['status' => ['code' => 200], 'entries' => [['reference_id' => 123]]])]);
    expect(SmsProvider::make('sabanovin', ['token' => 'key'])->sendPattern('A7K9P2M4Q8X1', ['firstname' => 'علی', 'code' => '1234'], '09120000000'))->toBeTrue();
    Http::assertSent(fn ($request) => $request->hasHeader('Content-Type', 'application/json') && $request['code'] === 'A7K9P2M4Q8X1'
        && $request['receptors'] === ['09120000000'] && json_decode($request->body(), true)['variables']['firstname'] === 'علی');
});

it('rejects missing recipients, text and templates in the fluent builder', function () {
    Http::fake();
    expect(fn () => sms()->send())->toThrow(InvalidArgumentException::class);
    expect(fn () => sms()->to('09120000000')->send())->toThrow(InvalidArgumentException::class);
    expect(fn () => sms()->to('09120000000')->sendPattern())->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('rejects empty direct bulk messages for every SMS provider', function ($name) {
    Http::fake();
    expect(fn () => SmsProvider::make($name)->sendBulk([], 'hello'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SmsProvider::make($name)->sendBulk(['09120000000'], '   '))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['melipayamak', 'farapayamak', 'kavenegar', 'smsir', 'ghasedak', 'sabanovin']);

it('sends the configured sender line unless a call names one', function () {
    Http::fake(['*' => Http::response(['status' => 1])]);
    $gateway = SmsProvider::make('smsir', ['line' => '3000']);
    $gateway->send('09120000000', 'Hello');
    $gateway->send('09120000000', 'Hello', '4000');
    Http::assertSent(fn ($request) => $request['lineNumber'] === '3000');
    Http::assertSent(fn ($request) => $request['lineNumber'] === '4000');
});

it('requires a sender line where the provider has no default', function ($name) {
    Http::fake();
    expect(fn () => SmsProvider::make($name, ['line' => ''])->send('09120000000', 'Hello'))
        ->toThrow(ConfigurationException::class, "'line'");
    Http::assertNothingSent();
})->with(['smsir', 'melipayamak', 'sabanovin']);
