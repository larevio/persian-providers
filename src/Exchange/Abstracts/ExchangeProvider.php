<?php

namespace Larevio\PersianProviders\Exchange\Abstracts;

use BadMethodCallException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Larevio\PersianProviders\Abstracts\Provider;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exchange\Data\ExchangeRate;
use Larevio\PersianProviders\Exchange\Providers\Bitpin;
use Larevio\PersianProviders\Exchange\Providers\Nobitex;
use Larevio\PersianProviders\Exchange\Providers\Wallex;
use SensitiveParameter;

/** Providers implement getRate() and override the account operations they support. */
abstract class ExchangeProvider extends Provider
{
    /** The unit this exchange quotes its fiat markets in. */
    protected Currency $fiatUnit;

    /** This exchange's market symbol for $fiatUnit. */
    protected string $fiatSymbol;

    abstract public function getRate(Currency $from, Currency $to): ExchangeRate;

    public function getWalletAddress(string $currency, ?string $network = null): string
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function getProfile(): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function getBankAccounts(): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** $amount and $price are decimal strings or integers, in the exchange's native market units. */
    public function order(
        string $type,
        string $from,
        string $to,
        string|int $amount,
        string|int $price,
        array $extra = [],
    ): bool {
        throw $this->unsupported(__FUNCTION__);
    }

    public function withdraw(
        string|int $wallet,
        string $address,
        string|int $amount,
        ?string $network = null,
        array $extra = [],
    ): array {
        throw $this->unsupported(__FUNCTION__);
    }

    public function withdrawFiat(string|int $accountId, int $rials): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public static function make(?string $name = null, #[SensitiveParameter] ?array $config = null): self
    {
        $name = self::providerName($name, 'exchange');
        $providerClass = match ($name) {
            'nobitex' => Nobitex::class,
            'bitpin' => Bitpin::class,
            'wallex' => Wallex::class,
            default => throw new ConfigurationException("Unknown exchange provider '$name'."),
        };

        return new $providerClass($config);
    }

    abstract protected function isSuccessful(array $data): bool;

    protected function getConfigNamespace(): string
    {
        return 'exchange';
    }

    protected function successfulData(Response $response): array
    {
        $data = $this->json($response);

        if (! $response->successful() || ! $this->isSuccessful($data)) {
            $message = $data['message'] ?? $data['detail'] ?? $data['error'] ?? null;

            throw $this->rejected($response, $data['code'] ?? null, $message);
        }

        return $data;
    }

    protected function assertPositiveDecimal(string|int $value): void
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value) || trim((string) $value, '0.') === '') {
            throw new InvalidArgumentException('A positive decimal amount or price is required.');
        }
    }

    protected function assertPositiveRials(int $rials): void
    {
        if ($rials <= 0 || $this->fiatUnit->fromRials($rials) === 0) {
            throw new InvalidArgumentException("Withdrawal amounts must be at least one {$this->fiatUnit->name}.");
        }
    }

    protected function symbol(Currency $currency): string
    {
        return $currency->isFiat() ? $this->fiatSymbol : $currency->name;
    }

    /** Converts a native quote to the requested pair by moving the decimal point. */
    protected function quote(mixed $value, Currency $from, Currency $to): string
    {
        $decimal = $this->decimal($value);
        $places = 0;

        if ($from->isFiat() && $from !== $this->fiatUnit) {
            $places += $from === Currency::IRT ? 1 : -1;
        }

        if ($to->isFiat() && $to !== $this->fiatUnit) {
            $places += $to === Currency::IRT ? -1 : 1;
        }

        return $this->shiftDecimal($decimal, $places);
    }

    private function decimal(mixed $value): string
    {
        // Some exchanges send JSON numbers. PHP prints floats with the shortest round-trip form, which may use an exponent.
        if (is_float($value) && is_finite($value)) {
            $value = str_contains((string) json_encode($value), 'e') ? rtrim(number_format($value, 18, '.', ''), '0') : json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]+(?:\.[0-9]*)?$/D', (string) $value) || trim((string) $value, '0.') === '') {
            throw new InvalidResponseException("$this->title returned an invalid price.");
        }

        return (string) $value;
    }

    private function shiftDecimal(string $decimal, int $places): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        if ($places > 0) {
            $whole .= str_pad(substr($fraction, 0, $places), $places, '0');
            $fraction = substr($fraction, $places);
        } elseif ($places < 0) {
            $whole = str_pad($whole, -$places + 1, '0', STR_PAD_LEFT);
            $fraction = substr($whole, $places).$fraction;
            $whole = substr($whole, 0, $places);
        }

        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    private function unsupported(string $method): BadMethodCallException
    {
        return new BadMethodCallException("$this->title does not support $method().");
    }
}
