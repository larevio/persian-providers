<?php

namespace Larevio\PersianProviders\Exchange\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;
use Larevio\PersianProviders\Exchange\Data\ExchangeRate;

class Nobitex extends ExchangeProvider
{
    protected string $name = 'nobitex';

    protected string $title = 'Nobitex';

    protected string $baseUrl = 'https://apiv2.nobitex.ir/';

    protected Currency $fiatUnit = Currency::IRR;

    protected string $fiatSymbol = 'RLS';

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        $src = strtolower($this->symbol($from));
        $dst = strtolower($this->symbol($to));

        $data = $this->successfulData($this->get('/market/stats?srcCurrency='.$src.'&dstCurrency='.$dst, $this->agent()));
        $rate = $data['stats'][$src.'-'.$dst] ?? null;

        if (! is_array($rate)) {
            throw new InvalidArgumentException("Currency pair {$from->name}/{$to->name} not supported.");
        }

        return new ExchangeRate(
            from: $from,
            to: $to,
            last: $this->quote($rate['latest'] ?? null, $from, $to),
            bestSell: $this->quote($rate['bestSell'] ?? null, $from, $to),
            bestBuy: $this->quote($rate['bestBuy'] ?? null, $from, $to),
        );
    }

    public function getWalletAddress(string $currency, ?string $network = null): string
    {
        $data = $this->successfulData($this->post('/users/wallets/generate-address', Arr::whereNotNull([
            'currency' => $currency,
            'network' => $network,
        ]), $this->headers()));

        return $this->requiredString($data, 'address');
    }

    public function getBankAccounts(): array
    {
        $accounts = $this->getProfile()['bankAccounts'] ?? null;
        if (! is_array($accounts)) {
            throw new InvalidResponseException('Nobitex returned invalid bank accounts.');
        }

        return $accounts;
    }

    public function withdraw(
        string|int $wallet,
        string $address,
        string|int $amount,
        ?string $network = null,
        array $extra = [],
    ): array {
        $this->assertPositiveDecimal($amount);
        if (trim($address) === '') {
            throw new InvalidArgumentException('A withdrawal address is required.');
        }

        $headers = $this->headers();
        if (isset($extra['totp'])) {
            $headers['X-TOTP'] = (string) $extra['totp'];
        }
        unset($extra['totp']);

        $data = $this->successfulData($this->post('/users/wallets/withdraw', Arr::whereNotNull([
            ...$extra,
            'wallet' => $wallet,
            'network' => $network,
            'amount' => $amount,
            'address' => $address,
        ]), $headers));
        $this->requiredString($data, 'withdraw.id');

        return $data['withdraw'];
    }

    public function confirmWithdrawal(string|int $id, ?string $otp = null): bool
    {
        $this->assertPositiveDecimal($id);
        $this->successfulData($this->post('/users/wallets/withdraw-confirm', Arr::whereNotNull([
            'withdraw' => $id,
            'otp' => $otp,
        ]), $this->headers()));

        return true;
    }

    public function getProfile(): array
    {
        $data = $this->successfulData($this->get('/users/profile', $this->headers()));
        if (! is_array($data['profile'] ?? null)) {
            throw new InvalidResponseException('Nobitex returned an invalid profile.');
        }

        return $data['profile'];
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
        $type = strtolower($type);
        if (! in_array($type, ['buy', 'sell'], true)) {
            throw new InvalidArgumentException('Order side must be buy or sell.');
        }

        $this->successfulData($this->post('/market/orders/add', [
            ...$extra,
            'type' => $type,
            'srcCurrency' => $from,
            'dstCurrency' => $to,
            'amount' => $amount,
            'price' => $price,
        ], $this->headers()));

        return true;
    }

    public function withdrawFiat(string|int $accountId, int $rials): bool
    {
        $this->assertPositiveRials($rials);
        $this->successfulData($this->post('/cobank/withdraw', [
            'destinationBankAccountId' => $accountId,
            'amount' => (string) $rials,
        ], $this->headers()));

        return true;
    }

    protected function isSuccessful(array $data): bool
    {
        return ($data['status'] ?? null) === 'ok';
    }

    private function headers(): array
    {
        return [...$this->agent(), 'Authorization' => 'Token '.$this->credential('token')];
    }

    /** Nobitex asks API clients to identify themselves with this User-Agent pattern. */
    private function agent(): array
    {
        return ['User-Agent' => 'TraderBot/PersianProviders'];
    }
}
