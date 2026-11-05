<?php

namespace Larevio\PersianProviders\Payment;

use BadMethodCallException;
use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;
use SensitiveParameter;

class Payment
{
    private mixed $id = null;

    private ?int $rials = null;

    private ?string $callback = null;

    private string $description = '';

    private ?string $email = null;

    private ?string $mobile = null;

    private mixed $orderId = null;

    private array $extra = [];

    private function __construct(private readonly PaymentProvider $provider) {}

    public static function make(string|PaymentProvider|null $provider = null, #[SensitiveParameter] ?array $config = null): Payment
    {
        return new Payment($provider instanceof PaymentProvider ? $provider : PaymentProvider::make($provider, $config));
    }

    public function amount(int $amount, Currency $currency): Payment
    {
        $this->rials = $currency->toRials($amount);

        return $this;
    }

    public function callback(string $callback): Payment
    {
        $this->callback = $callback;

        return $this;
    }

    public function description(string $description): Payment
    {
        $this->description = $description;

        return $this;
    }

    public function email(string $email): Payment
    {
        $this->email = $email;

        return $this;
    }

    public function mobile(string $mobile): Payment
    {
        $this->mobile = $mobile;

        return $this;
    }

    public function extra(array $extra): Payment
    {
        $this->extra = $extra;

        return $this;
    }

    public function order(mixed $orderId): Payment
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function id(mixed $id): Payment
    {
        $this->id = $id;

        return $this;
    }

    public function create(): PendingPayment
    {
        return $this->provider->create(
            $this->requireAmount(),
            $this->description,
            $this->orderId,
            $this->mobile,
            $this->email,
            $this->callback,
            $this->extra
        );
    }

    public function supportsReverse(): bool
    {
        return $this->provider instanceof Reversible;
    }

    public function reverse(): ReversedPayment
    {
        if (! $this->provider instanceof Reversible) {
            throw new BadMethodCallException('The selected payment provider does not support reversal.');
        }

        return $this->provider->reverse($this->id, $this->rials, $this->orderId, $this->extra);
    }

    public function verify(): false|VerifiedPayment
    {
        return $this->provider->verify($this->id, $this->requireAmount(), $this->orderId, $this->extra);
    }

    private function requireAmount(): int
    {
        return $this->rials ?? throw new InvalidArgumentException('A payment amount is required.');
    }
}
