<?php

namespace Larevio\PersianProviders\Payment\Abstracts;

use InvalidArgumentException;
use Larevio\PersianProviders\Abstracts\Provider;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;
use Larevio\PersianProviders\Payment\Providers\AqayePardakht;
use Larevio\PersianProviders\Payment\Providers\Damavand;
use Larevio\PersianProviders\Payment\Providers\Idpay;
use Larevio\PersianProviders\Payment\Providers\Nextpay;
use Larevio\PersianProviders\Payment\Providers\Panapal;
use Larevio\PersianProviders\Payment\Providers\Pasargad;
use Larevio\PersianProviders\Payment\Providers\Payping;
use Larevio\PersianProviders\Payment\Providers\Paystar;
use Larevio\PersianProviders\Payment\Providers\Sadad;
use Larevio\PersianProviders\Payment\Providers\Sep;
use Larevio\PersianProviders\Payment\Providers\Sepehr;
use Larevio\PersianProviders\Payment\Providers\Vandar;
use Larevio\PersianProviders\Payment\Providers\Zarinpal;
use Larevio\PersianProviders\Payment\Providers\Zibal;
use SensitiveParameter;

/** Every amount in and out of this class is in rials. */
abstract class PaymentProvider extends Provider
{
    /** The unit this provider's API uses for amounts. */
    protected Currency $currency = Currency::IRR;

    protected string $paymentUrl;

    abstract public function create(
        int $rials,
        string $description,
        mixed $orderId = null,
        mixed $phone = null,
        mixed $email = null,
        mixed $callback = null,
        array $extra = [],
    ): PendingPayment;

    /** @return VerifiedPayment|false False when the callback reports an unpaid transaction; no provider call is made. */
    abstract public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false;

    public static function make(?string $name = null, #[SensitiveParameter] ?array $config = null): self
    {
        $name = self::providerName($name, 'payment');
        $providerClass = match ($name) {
            'zarinpal' => Zarinpal::class,
            'zibal' => Zibal::class,
            'idpay' => Idpay::class,
            'sep' => Sep::class,
            'sepehr' => Sepehr::class,
            'nextpay' => Nextpay::class,
            'aqayepardakht' => AqayePardakht::class,
            'panapal' => Panapal::class,
            'payping' => Payping::class,
            'paystar' => Paystar::class,
            'vandar' => Vandar::class,
            'pasargad' => Pasargad::class,
            'sadad' => Sadad::class,
            'damavand' => Damavand::class,
            default => throw new ConfigurationException("Unknown payment provider '$name'."),
        };

        return new $providerClass($config);
    }

    protected function getConfigNamespace(): string
    {
        return 'payment';
    }

    protected function providerAmount(int $rials): int
    {
        return $this->currency->fromRials($rials);
    }

    /** The rial amount the provider actually charges, after rounding to its unit. */
    protected function chargedRials(int $rials): int
    {
        return $this->currency->toRials($this->providerAmount($rials));
    }

    protected function validateAmount(?int $rials): void
    {
        if ($rials === null) {
            return;
        }

        if ($rials <= 0 || $rials > intdiv(PHP_INT_MAX, 10)) {
            throw new InvalidArgumentException('Payment amounts must be positive whole rials within the supported integer range.');
        }

        if ($this->providerAmount($rials) === 0) {
            throw new InvalidArgumentException("$this->title amounts must be at least one {$this->currency->name}.");
        }
    }

    protected function matchesAmount(mixed $actual, int $expected): bool
    {
        if ((! is_string($actual) && ! is_int($actual) && ! is_float($actual)) || ! preg_match('/^[0-9]+(?:\.0+)?$/D', (string) $actual)) {
            return false;
        }

        // Compare digit strings so a fraction or malformed value can't match after truncation.
        return ltrim(explode('.', (string) $actual)[0], '0') === ltrim((string) $expected, '0');
    }

    protected function assertAmount(mixed $actual, int $rials): void
    {
        if (! $this->matchesAmount($actual, $this->providerAmount($rials))) {
            throw new PaymentVerificationException("$this->title verified an amount that does not match the requested amount.");
        }
    }

    protected function assertOrder(mixed $actual, mixed $orderId): void
    {
        if ($orderId !== null && (string) (is_scalar($actual) ? $actual : '') !== (string) $orderId) {
            throw new PaymentVerificationException("$this->title verified an order that does not match the requested order.");
        }
    }

    protected function validateCreate(int $rials, mixed $callback): void
    {
        $this->validateAmount($rials);
        if (
            ! is_string($callback)
            || ! filter_var($callback, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($callback, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('A valid HTTP(S) callback URL is required.');
        }
    }

    protected function requireOrderId(mixed $orderId): void
    {
        if ($orderId === null || $orderId === '') {
            throw new InvalidArgumentException("$this->title requires an order ID.");
        }
    }

    protected function validateTransaction(mixed $id, ?int $rials): void
    {
        $this->validateAmount($rials);
        if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '' || $id === 0) {
            throw new InvalidArgumentException('The original payment ID is required.');
        }
    }

    /** The redirect URL comes from the provider, so require HTTPS. */
    protected function redirectUrl(mixed $url): string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidResponseException("$this->title returned an invalid payment URL.");
        }

        return $url;
    }

    /** A provider-reported fee, or null when it is absent or not a whole number of units. */
    protected function feeInRials(mixed $fee): ?int
    {
        if ((! is_string($fee) && ! is_int($fee)) || ! preg_match('/^([0-9]+)(?:\.0+)?$/D', (string) $fee, $match)) {
            return null;
        }

        return $this->currency->toRials((int) $match[1]);
    }
}
