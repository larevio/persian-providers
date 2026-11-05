<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Exceptions\TransportException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

beforeEach(function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Carbon::setTestNow('2030-03-19T00:00:00Z');
});

afterEach(fn () => Carbon::setTestNow());

dataset('sms credit providers', [
    'kavenegar' => ['kavenegar', ['token' => 'key'], 'https://api.kavenegar.com/v1/key/account/info.json', ['return' => ['status' => 200], 'entries' => ['remaincredit' => '12345.67']], 'IRR', 'GET'],
    'smsir' => ['smsir', ['token' => 'key'], 'https://api.sms.ir/v1/credit', ['status' => 1, 'data' => '12345.67'], null, 'GET'],
    'ghasedak' => ['ghasedak', ['token' => 'key'], 'https://gateway.ghasedak.me/rest/api/v1/WebService/GetAccountInformation', ['IsSuccess' => true, 'StatusCode' => 200, 'Data' => ['Credit' => '12345.67']], 'IRR', 'GET'],
    'sabanovin' => ['sabanovin', ['token' => 'key'], 'https://api.sabanovin.com/v1/key/account/balance.json', ['status' => ['code' => 200], 'entry' => ['balance' => '12345.67']], null, 'GET'],
    'melipayamak' => ['melipayamak', ['username' => 'meli', 'password' => 'secret'], 'https://rest.payamak-panel.com/api/SendSMS/GetCredit', ['RetStatus' => 1, 'Value' => '12345.67'], null, 'POST'],
    'farapayamak' => ['farapayamak', ['username' => 'fara', 'password' => 'secret'], 'https://rest.payamak-panel.com/api/SendSMS/GetCredit', ['RetStatus' => 1, 'Value' => '12345.67'], null, 'POST'],
]);

dataset('sms batch providers', [
    'kavenegar' => ['kavenegar', 'https://api.kavenegar.com/v1/key/sms/sendarray.json', ['return' => ['status' => 200], 'entries' => [['messageid' => 101], ['messageid' => 102]]], null],
    'smsir' => ['smsir', 'https://api.sms.ir/v1/send/likeToLike', ['status' => 1, 'data' => ['packId' => 'pack-1', 'messageIds' => [101, 102], 'cost' => 2.5]], 'pack-1'],
    'ghasedak' => ['ghasedak', 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendPairToPairSMS', ['isSuccess' => true, 'statusCode' => 200, 'data' => ['items' => [['messageId' => 101], ['messageId' => 102]]]], null],
    'sabanovin' => ['sabanovin', 'https://api.sabanovin.com/v1/key/sms/send_array.json', ['status' => ['code' => 200], 'batch_id' => 'pack-1', 'entries' => [['reference_id' => 101], ['reference_id' => 102]]], 'pack-1'],
]);

dataset('sms schedule providers', [
    'kavenegar' => ['kavenegar', 'https://api.kavenegar.com/v1/key/sms/send.json', ['return' => ['status' => 200], 'entries' => [['messageid' => 101]]], null],
    'smsir' => ['smsir', 'https://api.sms.ir/v1/send/bulk', ['status' => 1, 'data' => ['packId' => 'pack-1', 'messageIds' => null]], 'pack-1'],
    'ghasedak' => ['ghasedak', 'https://gateway.ghasedak.me/rest/api/v1/WebService/SendBulkSMS', ['IsSuccess' => true, 'StatusCode' => 200, 'Data' => ['Receptors' => [['MessageId' => 101]]]], null],
    'sabanovin' => ['sabanovin', 'https://api.sabanovin.com/v1/key/sms/send.json', ['status' => ['code' => 200], 'batch_id' => 'pack-1', 'entries' => [['reference_id' => 101]]], 'pack-1'],
]);

it('retrieves credit without losing precision or inventing a unit', function ($name, $config, $url, $reply, $unit, $method) {
    Http::fake([$url => Http::response($reply)]);
    $sms = sms($name, $config);
    expect($sms->supportsBalance())->toBeTrue();
    $credit = $sms->balance();
    expect($credit)->toBeInstanceOf(SmsBalance::class)
        ->and($credit->amount)->toBe('12345.67')
        ->and($credit->unit)->toBe($unit);
    Http::assertSent(fn ($request) => $request->url() === $url && $request->method() === $method);
    if ($method === 'POST') {
        Http::assertSent(fn ($request) => $request['username'] === $config['username'] && $request['password'] === 'secret');
    }
    if ($name === 'smsir' || $name === 'ghasedak') {
        Http::assertSent(fn ($request) => $request->hasHeader($name === 'smsir' ? 'X-API-KEY' : 'ApiKey', 'key'));
    }
    Http::assertSentCount(1);
})->with('sms credit providers');

it('accepts zero credit as a real balance', function ($name, $config, $url, $reply) {
    $field = match ($name) {
        'kavenegar' => 'entries.remaincredit', 'smsir' => 'data', 'ghasedak' => 'Data.Credit',
        'sabanovin' => 'entry.balance', default => 'Value',
    };
    data_set($reply, $field, 0);
    Http::fake([$url => Http::response($reply)]);
    expect(SmsProvider::make($name, $config)->balance()->amount)->toBe('0');
})->with('sms credit providers');

it('rejects missing or invalid credit instead of returning a zero balance', function ($name, $config, $url, $reply) {
    $field = match ($name) {
        'kavenegar' => 'entries.remaincredit', 'smsir' => 'data', 'ghasedak' => 'Data.Credit',
        'sabanovin' => 'entry.balance', default => 'Value',
    };
    foreach ([null, false, [], 'invalid'] as $invalid) {
        data_set($reply, $field, $invalid);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([$url => Http::response($reply)]);
        expect(fn () => SmsProvider::make($name, $config)->balance())->toThrow(RuntimeException::class);
    }
})->with('sms credit providers');

it('rejects provider and HTTP errors for credit', function ($name, $config, $url, $reply) {
    foreach ([Http::response([]), Http::response($reply, 403)] as $response) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([$url => $response]);
        expect(fn () => SmsProvider::make($name, $config)->balance())->toThrow(RuntimeException::class);
        Http::assertSentCount(1);
    }
})->with('sms credit providers');

it('sends distinct messages in a single native batch and preserves provider identifiers', function ($name, $url, $reply, $batchId) {
    Http::fake([$url => Http::response($reply)]);
    $messages = [
        4 => ['phone' => '09120000000', 'text' => 'سلام & + first'],
        9 => ['phone' => '09120000001', 'text' => 'Second message'],
    ];
    $sms = sms($name, ['token' => 'key', 'line' => 'default']);
    expect($sms->supportsBatch())->toBeTrue();
    $result = $sms->sendBatch($messages, '3000');
    expect($result)->toBeInstanceOf(SmsSubmission::class)
        ->and($result->messageIds)->toBe(['101', '102'])
        ->and($result->batchId)->toBe($batchId)
        ->and($result->scheduledAt)->toBeNull()
        ->and($result->raw)->toBe($reply);
    Http::assertSent(function ($request) use ($name) {
        $data = $request->data();

        return match ($name) {
            'kavenegar' => json_decode($data['receptor'], true) === ['09120000000', '09120000001']
                && json_decode($data['message'], true) === ['سلام & + first', 'Second message']
                && json_decode($data['sender'], true) === ['3000', '3000'],
            'smsir' => $data['mobiles'] === ['09120000000', '09120000001']
                && $data['messageTexts'] === ['سلام & + first', 'Second message'] && $data['lineNumber'] === '3000'
                && $request->hasHeader('X-API-KEY', 'key'),
            'ghasedak' => $data['items'][0]['receptor'] === '09120000000'
                && $data['items'][0]['message'] === 'سلام & + first' && $data['items'][1]['message'] === 'Second message'
                && $data['items'][0]['lineNumber'] === '3000'
                && $data['items'][0]['clientReferenceId'] !== $data['items'][1]['clientReferenceId']
                && $request->hasHeader('ApiKey', 'key'),
            'sabanovin' => json_decode($data['to'], true) === ['09120000000', '09120000001']
                && json_decode($data['text'], true) === ['سلام & + first', 'Second message'] && $data['gateway'] === '3000',
        };
    });
    Http::assertSentCount(1);
})->with('sms batch providers');

it('schedules with the provider date format and preserves the effective time', function ($name, $url, $reply, $batchId) {
    if ($name === 'sabanovin' && ! extension_loaded('intl')) {
        $this->markTestSkipped('The intl extension is required for Sabanovin scheduling.');
    }
    Http::fake([$url => Http::response($reply)]);
    $sendAt = new DateTime('2030-03-21T09:00:30Z');
    $sms = sms($name, ['token' => 'key', 'line' => '3000']);
    expect($sms->supportsSchedule())->toBeTrue();
    $result = $sms->to(['09120000000'])->message('Reminder & سلام')->schedule($sendAt);
    expect($result->batchId)->toBe($batchId)
        ->and($result->scheduledAt->format(DATE_ATOM))->toBe($name === 'sabanovin' ? '2030-03-21T09:01:00+00:00' : '2030-03-21T09:00:30+00:00')
        ->and($sendAt->format(DATE_ATOM))->toBe('2030-03-21T09:00:30+00:00');
    Http::assertSent(function ($request) use ($name, $sendAt) {
        $data = $request->data();

        return match ($name) {
            'kavenegar' => $data['date'] === $sendAt->getTimestamp() && $data['message'] === 'Reminder & سلام',
            'smsir' => $data['sendDateTime'] === $sendAt->getTimestamp() && $data['mobiles'] === ['09120000000'],
            'ghasedak' => $data['sendDate'] === '2030-03-21T09:00:30+00:00' && $data['receptors'] === ['09120000000'],
            'sabanovin' => $data['at'] === '12:31 1409/01/01' && $data['text'] === 'Reminder & سلام',
        };
    });
    $sendAt->modify('+1 day');
    expect($result->scheduledAt->format('Y-m-d'))->toBe('2030-03-21');
    Http::assertSentCount(1);
})->with('sms schedule providers');

it('rejects malformed batch input before sending', function ($name, $messages) {
    Http::fake();
    expect(fn () => SmsProvider::make($name)->sendBatch($messages))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['kavenegar', 'smsir', 'ghasedak', 'sabanovin'])->with([
    [[]], [[['phone' => '09120000000']]], [[['phone' => '', 'text' => 'hello']]],
    [[['phone' => 9120000000, 'text' => 'hello']]], [[['phone' => '09120000000', 'text' => '   ']]],
]);

it('rejects empty recipients and past schedules before sending', function ($name) {
    Http::fake();
    $gateway = SmsProvider::make($name);
    expect(fn () => $gateway->schedule([], 'Hello', new DateTimeImmutable('2030-03-21')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->schedule(['09120000000'], 'Hello', new DateTimeImmutable('2030-03-18')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->schedule(['09120000000'], 'Hello', now()))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['kavenegar', 'smsir', 'ghasedak', 'sabanovin']);

it('does not advertise SOAP-only operations on REST adapters', function ($name) {
    Http::fake();
    $sms = sms($name);
    expect($sms->supportsBatch())->toBeFalse()->and($sms->supportsSchedule())->toBeFalse()->and($sms->supportsBalance())->toBeTrue();
    expect(fn () => $sms->sendBatch([['phone' => '09120000000', 'text' => 'Hello']]))->toThrow(BadMethodCallException::class);
    expect(fn () => $sms->to('09120000000')->message('Hello')->schedule(now()->addHour()))->toThrow(BadMethodCallException::class);
    Http::assertNothingSent();
})->with(['melipayamak', 'farapayamak']);

it('does not accept HTTP errors or identifier-free submissions', function ($name, $url, $reply) {
    $gateway = SmsProvider::make($name, ['token' => 'key', 'line' => '3000']);
    foreach ([Http::response($reply, 500), Http::response([])] as $response) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([$url => $response]);
        expect(fn () => $gateway->sendBatch([['phone' => '09120000000', 'text' => 'Hello']]))->toThrow(RuntimeException::class);
        Http::assertSentCount(1);
    }
    foreach (['entries', 'data', 'Data'] as $key) {
        if (array_key_exists($key, $reply)) {
            $reply[$key] = [];
        }
    }
    unset($reply['batch_id']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    expect(fn () => $gateway->sendBatch([['phone' => '09120000000', 'text' => 'Hello']]))->toThrow(RuntimeException::class);
})->with('sms batch providers');

it('wraps a connection failure and never resubmits the SMS batch', function ($name, $url) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::failedConnection();
    });
    expect(fn () => SmsProvider::make($name, ['token' => 'key', 'line' => '3000'])
        ->sendBatch([['phone' => '09120000000', 'text' => 'Hello']]))->toThrow(TransportException::class);
    expect($attempts)->toBe(1);
})->with('sms batch providers');

it('does not lose partial batch results', function () {
    $reply = ['return' => ['status' => 200], 'entries' => [['messageid' => 101, 'status' => 1], ['messageid' => -1, 'status' => 100]]];
    Http::fake(['*' => Http::response($reply)]);
    $result = SmsProvider::make('kavenegar', ['token' => 'key', 'line' => '3000'])
        ->sendBatch([['phone' => '09120000000', 'text' => 'First'], ['phone' => '09120000001', 'text' => 'Second']]);
    expect($result->messageIds)->toBe(['101'])->and($result->raw)->toBe($reply);
});
