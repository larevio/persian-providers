<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Enums\Bank;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Inquiry\Data\BankAccount;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
});

it('distinguishes a real Zohal mismatch from a provider failure', function () {
    Http::fake(['*' => Http::response(['result' => 1, 'response_body' => ['error_code' => null, 'data' => ['matched' => false]]])]);
    expect(inquiry('zohal', ['token' => 'key'])->matchMobile('09120000000', '0012345678'))->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === 'https://service.zohal.io/api/v0/services/inquiry/shahkar'
        && $request['national_code'] === '0012345678' && $request['mobile'] === '09120000000' && $request->hasHeader('Authorization', 'Bearer key'));
});

it('rejects HTTP 200 Zohal errors and missing match fields', function ($reply, $exception) {
    Http::fake(['*' => Http::response($reply)]);
    expect(fn () => inquiry('zohal')->matchMobile('09120000000', '0012345678'))->toThrow($exception);
    Http::assertSentCount(1);
})->with([
    [['result' => 1, 'response_body' => ['error_code' => 'SERVICE_UNAVAILABLE', 'message' => 'Unavailable', 'data' => []]], ProviderException::class],
    [['result' => 1, 'response_body' => ['error_code' => null, 'data' => []]], InvalidResponseException::class],
    [['result' => 1, 'response_body' => ['error_code' => null, 'data' => null]], InvalidResponseException::class],
    [['result' => 1, 'response_body' => ['error_code' => null, 'data' => ['matched' => 'false']]], InvalidResponseException::class],
    [['result' => 0], ProviderException::class],
]);

it('uses documented Zohal matching payloads', function ($method, $arguments, $endpoint, $payload) {
    Http::fake(['*' => Http::response(['result' => 1, 'response_body' => ['error_code' => null, 'data' => ['matched' => true]]])]);
    expect(inquiry('zohal')->$method(...$arguments))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/services/inquiry/'.$endpoint) && $request->data() === $payload);
})->with([
    ['matchIban', ['IR123', '0012345678', '1370/01/01'], 'check_iban_with_national_code', ['IBAN' => 'IR123', 'national_code' => '0012345678', 'birth_date' => '1370/01/01']],
    ['matchCard', ['6037990000000000', '0012345678', '1370/01/01'], 'check_card_with_national_code', ['card_number' => '6037990000000000', 'birth_date' => '1370/01/01', 'national_code' => '0012345678']],
    ['matchCardOwner', ['6037990000000000', 'علی'], 'check_card_with_name', ['name' => 'علی', 'card_number' => '6037990000000000']],
    ['matchIbanOwner', ['IR123', 'علی'], 'check_iban_with_name', ['IBAN' => 'IR123', 'name' => 'علی']],
]);

it('reports Zohal lookups that find nothing with the provider code', function () {
    Http::fake(['*' => Http::response(['result' => 1, 'response_body' => ['data' => [], 'message' => 'Card not found', 'error_code' => 'CARD_NOT_FOUND']])]);
    try {
        inquiry('zohal')->cardOwnerInquiry('6037990000000000');
        $this->fail('Expected a provider rejection.');
    } catch (ProviderException $e) {
        expect($e->providerCode)->toBe('CARD_NOT_FOUND')->and($e->getMessage())->toBe('Card not found');
    }
});

it('rejects unsupported Jibit lookups before requesting', function ($method, $arguments) {
    Http::fake();
    expect(fn () => inquiry('jibit')->$method(...$arguments))->toThrow(BadMethodCallException::class, 'Jibit does not support');
    Http::assertNothingSent();
})->with([
    ['matchCard', ['6037990000000000', '0012345678', '1370/01/01']],
    ['cardToIban', ['6037990000000000']],
    ['postalCodeInquiry', ['1234567890']],
]);

it('maps Zohal card ownership, bank, identity and postal data', function () {
    $response = fn ($data) => Http::response(['result' => 1, 'response_body' => ['error_code' => null, 'data' => $data]]);
    Http::fake([
        '*card_inquiry' => $response(['name' => 'علی']),
        '*card_to_iban' => $response(['IBAN' => 'IR000170000000000000000000', 'name' => 'علی', 'bank_name' => 'ملی']),
        '*national_identity_inquiry' => $response(['matched' => true, 'first_name' => 'علی', 'last_name' => 'احمدی', 'is_dead' => false]),
        '*postal_code_inquiry' => $response(['address' => ['city' => 'تهران', 'town' => 'old', 'street' => 'خیابان']]),
    ]);
    $gateway = inquiry('zohal');
    expect($gateway->cardOwnerInquiry('6037990000000000'))->toBe('علی');
    expect($gateway->cardToAccount('6037990000000000')->ownerName)->toBe('علی');
    $identity = $gateway->nationalIdentityInquiry('0012345678', '1370/01/01');
    expect($identity->firstName)->toBe('علی')->and($identity->alive)->toBeTrue()->and($identity->nationalCode)->toBe('0012345678');
    expect($gateway->postalCodeInquiry('1234567890')->city)->toBe('تهران');
});

it('separates Jibit cached credentials by account and reuses each token', function () {
    Http::fake(fn ($request) => Http::response(['accessToken' => 'token-'.$request['apiKey']]));
    $first = inquiry('jibit', ['api_key' => 'first', 'secret_key' => 'secret']);
    $second = inquiry('jibit', ['api_key' => 'second', 'secret_key' => 'secret']);
    expect($first->getAccessToken())->toBe('token-first')->and($second->getAccessToken())->toBe('token-second')->and($first->getAccessToken())->toBe('token-first');
    Http::assertSentCount(2);
});

it('does not cache a malformed Jibit token response', function () {
    Http::fake(['*' => Http::sequence()->push([])->push(['accessToken' => 'valid'])]);
    $gateway = inquiry('jibit', ['api_key' => 'key', 'secret_key' => 'secret']);
    expect(fn () => $gateway->getAccessToken())->toThrow(InvalidResponseException::class);
    expect($gateway->getAccessToken())->toBe('valid');
    Http::assertSentCount(2);
});

it('preserves all Jibit IBAN owners', function () {
    Http::fake([
        '*tokens/generate' => Http::response(['accessToken' => 'key']),
        '*ibans?*' => Http::response(['ibanInfo' => ['owners' => [['firstName' => 'First', 'lastName' => 'Owner'], ['firstName' => 'Second', 'lastName' => 'Owner']], 'depositNumber' => '00123']]),
    ]);
    $account = inquiry('jibit')->ibanInquiry('IR123');
    expect($account->ownerName)->toBe('First Owner, Second Owner')->and($account->accountNumber)->toBe('00123');
});

it('does not interpret an incomplete Jibit result as a mismatch', function () {
    Http::fake(['*tokens/generate' => Http::response(['accessToken' => 'key']), '*matching?*' => Http::response([])]);
    expect(fn () => inquiry('jibit')->matchMobile('09120000000', '0012345678'))->toThrow(InvalidResponseException::class);
});

it('keeps the provider bank name when the bank code is unknown', function () {
    $account = new BankAccount(iban: 'IR000000000000000000000000', bankName: 'Unknown bank');
    expect($account->bank())->toBeNull()->and($account->bankName)->toBe('Unknown bank')
        ->and((new BankAccount)->bank())->toBeNull();
});

it('normalizes bank identifiers and does not identify incomplete values', function () {
    expect(Bank::fromBankCode(17))->toBe(Bank::MELLI)
        ->and(Bank::fromIban(' ir00 0170 0000 0000 0000 0000 00 '))->toBe(Bank::MELLI)
        ->and(Bank::fromCard('6037-9900 0000 0000'))->toBe(Bank::MELLI)
        ->and(Bank::fromCard('603799******0000'))->toBe(Bank::MELLI)
        ->and(Bank::fromCard('603799'))->toBeNull()
        ->and(Bank::fromIban('IR00017'))->toBeNull();
});

it('discards a Jibit token the provider rejects', function (int $status) {
    Http::fake([
        '*tokens/generate' => Http::sequence()->push(['accessToken' => 'stale'])->push(['accessToken' => 'fresh']),
        '*matching?*' => Http::sequence()->push(['code' => 'forbidden'], $status)->push(['matched' => true]),
    ]);
    $gateway = inquiry('jibit');
    expect(fn () => $gateway->matchMobile('09120000000', '0012345678'))->toThrow(ProviderException::class);
    expect($gateway->matchMobile('09120000000', '0012345678'))->toBeTrue();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh'));
})->with([401, 403]);

it('keeps the Jibit token when only the daily limit is reached', function () {
    Http::fake([
        '*tokens/generate' => Http::response(['accessToken' => 'token']),
        '*matching?*' => Http::response(['code' => 'daily_limit.reached', 'message' => 'Daily limit reached'], 403),
    ]);
    expect(fn () => inquiry('jibit')->matchMobile('09120000000', '0012345678'))->toThrow(ProviderException::class, 'Daily limit reached');
    expect(fn () => inquiry('jibit')->matchMobile('09120000000', '0012345678'))->toThrow(ProviderException::class);
    Http::assertSentCount(3);
});
