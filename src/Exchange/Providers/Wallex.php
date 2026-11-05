<?php

namespace Larevio\PersianProviders\Exchange\Providers;

use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;
use Larevio\PersianProviders\Exchange\Data\ExchangeRate;

class Wallex extends ExchangeProvider
{
    protected string $name = 'wallex';

    protected string $title = 'Wallex';

    protected string $baseUrl = 'https://api.wallex.ir/v1/';

    protected Currency $fiatUnit = Currency::IRT;

    protected string $fiatSymbol = 'TMN';

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        $symbol = $this->symbol($from).$this->symbol($to);
        $data = $this->successfulData($this->get('https://api.wallex.ir/hector/web/v1/markets'));
        $markets = $data['result']['markets'] ?? null;
        if (! is_array($markets)) {
            throw new InvalidResponseException('Wallex returned invalid markets.');
        }

        $market = collect($markets)->firstWhere('symbol', $symbol);
        if (! is_array($market)) {
            throw new InvalidArgumentException("Currency pair {$from->name}/{$to->name} not supported.");
        }

        $book = $this->successfulData($this->get('depth?'.http_build_query(['symbol' => $symbol])))['result'] ?? null;
        if (! is_array($book) || ! is_array($book['ask'] ?? null) || ! is_array($book['bid'] ?? null)) {
            throw new InvalidResponseException('Wallex returned an invalid order book.');
        }

        return new ExchangeRate(
            from: $from,
            to: $to,
            last: $this->quote($market['price'] ?? null, $from, $to),
            bestSell: $book['ask'] === [] ? null : $this->quote($book['ask'][0]['price'] ?? null, $from, $to),
            bestBuy: $book['bid'] === [] ? null : $this->quote($book['bid'][0]['price'] ?? null, $from, $to),
        );
    }

    public function getWalletAddress(string $currency, ?string $network = null): string
    {
        if ($network === null || trim($network) === '') {
            throw new InvalidArgumentException('Wallex requires an explicit deposit network.');
        }

        $response = $this->get('account/wallets/'.rawurlencode(strtolower($currency)).'?'.http_build_query(['network' => strtolower($network)]), $this->headers());

        return $this->requiredString($this->successfulData($response), 'result.wallets.'.strtoupper($network).'.address');
    }

    public function getProfile(): array
    {
        $data = $this->successfulData($this->get('account/profile', $this->headers()));
        if (! is_array($data['result'] ?? null)) {
            throw new InvalidResponseException('Wallex returned an invalid profile.');
        }

        return $data['result'];
    }

    public function getBankAccounts(): array
    {
        $data = $this->successfulData($this->get('account/ibans', $this->headers()));
        if (! is_array($data['result'] ?? null)) {
            throw new InvalidResponseException('Wallex returned invalid bank accounts.');
        }

        return $data['result'];
    }

    public function order(
        string $type,
        string $from,
        string $to,
        string|int $amount,
        string|int $price,
        array $extra = [],
    ): bool {
        $this->assertPositiveDecimal($amount);
        $this->assertPositiveDecimal($price);
        $side = strtoupper($type);
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            throw new InvalidArgumentException('Order side must be buy or sell.');
        }

        $this->successfulData($this->post('account/orders', [
            ...$extra,
            'type' => $extra['type'] ?? 'LIMIT',
            'side' => $side,
            'quantity' => $amount,
            'price' => $price,
            'symbol' => strtoupper($from.$to),
        ], $this->headers()));

        return true;
    }

    public function withdrawFiat(string|int $accountId, int $rials): bool
    {
        $this->assertPositiveRials($rials);
        $this->successfulData($this->post('account/money-withdrawal', [
            'iban' => $accountId,
            'value' => $this->fiatUnit->fromRials($rials),
        ], $this->headers()));

        return true;
    }

    protected function isSuccessful(array $data): bool
    {
        return ($data['success'] ?? null) === true;
    }

    private function headers(): array
    {
        return ['X-API-Key' => $this->credential('token')];
    }
}
