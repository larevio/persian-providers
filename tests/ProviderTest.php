<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Abstracts\Provider;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\TimeoutException;
use Larevio\PersianProviders\Exceptions\TransportException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;

function testProvider(?array $config = null): Provider
{
    return new class($config) extends Provider
    {
        protected string $name = 'fixture';

        protected string $title = 'Fixture';

        protected string $baseUrl = 'https://example.test/';

        public function options(): array
        {
            return $this->request()->getOptions();
        }

        public function fetch(): Response
        {
            return $this->get('data');
        }

        protected function getConfigNamespace(): string
        {
            return 'exchange';
        }
    };
}

it('merges partial instance config and preserves explicit null values', function () {
    config(['persianproviders.exchange.fixture' => ['token' => 'default', 'optional' => 'default']]);
    $provider = testProvider(['optional' => null]);
    expect($provider->getConfig('token'))->toBe('default')
        ->and($provider->getConfig('optional'))->toBeNull()
        ->and($provider->getConfig())->toBe(['token' => 'default', 'optional' => null]);
    expect(fn () => $provider->getConfig('missing'))->toThrow(ConfigurationException::class);
});

it('applies configurable timeouts, refuses redirects and honours proxy precedence', function () {
    config(['persianproviders.proxy' => 'http://global:8080', 'persianproviders.exchange.fixture.proxy' => 'http://provider:8080']);
    expect(testProvider()->options())->toMatchArray(['timeout' => 15, 'connect_timeout' => 5, 'proxy' => 'http://provider:8080', 'allow_redirects' => false]);
    expect(testProvider(['timeout' => 30, 'connect_timeout' => 2])->options())->toMatchArray(['timeout' => 30, 'connect_timeout' => 2]);
    expect(testProvider(['proxy' => 'http://instance:8080'])->options()['proxy'])->toBe('http://instance:8080');
    expect(testProvider(['proxy' => null])->options()['proxy'])->toBe('http://global:8080');
    expect(testProvider(['proxy' => false])->options())->not->toHaveKey('proxy');
});

it('returns HTTP error responses to the adapter instead of throwing transport errors', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
    expect(testProvider()->fetch()->status())->toBe(503);
    Http::assertSentCount(1);
});

it('reports connection failures and timeouts once, without retrying', function ($message, $exception) {
    $attempts = 0;
    Http::fake(function () use (&$attempts, $message) {
        $attempts++;
        throw new ConnectionException($message);
    });
    expect(fn () => testProvider()->fetch())->toThrow($exception);
    expect($attempts)->toBe(1);
})->with([
    ['cURL error 28: Operation timed out after 15000 milliseconds', TimeoutException::class],
    ['cURL error 6: Could not resolve host', TransportException::class],
]);

it('does not leak credentials embedded in request URLs through transport errors', function () {
    Http::fake(fn ($request) => throw new ConnectionException('cURL error 7 for '.$request->url()));

    try {
        SmsProvider::make('kavenegar', ['token' => 'super-secret-api-key'])->send('09120000000', 'Hello');
        $this->fail('Expected a transport exception.');
    } catch (TransportException $e) {
        expect($e->getMessage())->not->toContain('super-secret-api-key')->toContain('[redacted]')
            ->and($e->getPrevious()->getMessage())->not->toContain('super-secret-api-key');
    }
});

it('keeps credentials out of var_dump and missing settings out of HTTP', function () {
    Http::preventStrayRequests();
    Http::fake();
    $gateway = SmsProvider::make('smsir', ['token' => 'super-secret-api-key']);
    expect(print_r($gateway, true))->not->toContain('super-secret-api-key');

    expect(fn () => SmsProvider::make('smsir', ['token' => ''])->send('09120000000', 'Hello'))
        ->toThrow(ConfigurationException::class, "The 'token' setting for 'smsir' is empty.");
    Http::assertNothingSent();
});
