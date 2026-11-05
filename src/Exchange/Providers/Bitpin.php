<?php

namespace Larevio\PersianProviders\Exchange\Providers;

use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;
use Larevio\PersianProviders\Exchange\Data\ExchangeRate;

class Bitpin extends ExchangeProvider
{
    protected string $name = 'bitpin';

    protected string $title = 'Bitpin';

    protected string $baseUrl = 'https://api.bitpin.ir/api/';

    protected Currency $fiatUnit = Currency::IRT;

    protected string $fiatSymbol = 'IRT';

    public function getNetworks(string $currency): array
    {
        return $this->successfulData($this->get('v2/wlt/networks/?'.http_build_query(['currency_code' => strtoupper($currency)]), $this->headers()));
    }

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        $symbol = $this->symbol($from).'_'.$this->symbol($to);
        $rate = collect($this->successfulData($this->get('v1/mkt/tickers/')))->firstWhere('symbol', $symbol);
        if (! is_array($rate)) {
            throw new InvalidArgumentException("Currency pair {$from->name}/{$to->name} not supported.");
        }

        $book = $this->successfulData($this->get('v1/mth/orderbook/'.$symbol.'/'));
        if (! is_array($book['asks'] ?? null) || ! is_array($book['bids'] ?? null)) {
            throw new InvalidResponseException('Bitpin returned an invalid order book.');
        }

        return new ExchangeRate(
            from: $from,
            to: $to,
            last: $this->quote($rate['price'] ?? null, $from, $to),
            bestSell: $book['asks'] === [] ? null : $this->quote($book['asks'][0][0] ?? null, $from, $to),
            bestBuy: $book['bids'] === [] ? null : $this->quote($book['bids'][0][0] ?? null, $from, $to),
            timestamp: isset($rate['timestamp']) && is_numeric($rate['timestamp']) ? (int) $rate['timestamp'] : null,
        );
    }

    public function getWalletAddress(string $currency, ?string $network = null): string
    {
        if ($network === null || trim($network) === '') {
            throw new InvalidArgumentException('Bitpin requires an explicit deposit network.');
        }

        $response = $this->post('v2/wlt/address/', [
            'currency' => $currency,
            'network' => $network,
        ], $this->headers());

        return $this->requiredString($this->successfulData($response), 'address');
    }

    protected function isSuccessful(array $data): bool
    {
        return ! isset($data['error']) && ! isset($data['detail']);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->credential('token')];
    }
}
