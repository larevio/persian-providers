<?php

namespace Larevio\PersianProviders\Enums;

use InvalidArgumentException;

enum Currency
{
    case IRR;
    case IRT;
    case USDT;
    case BTC;
    case LTC;

    public function isFiat(): bool
    {
        return $this === self::IRR || $this === self::IRT;
    }

    public function toRials(int $amount): int
    {
        if (! $this->isFiat() || $amount < 0 || $amount > intdiv(PHP_INT_MAX, 10)) {
            throw new InvalidArgumentException("$amount $this->name cannot be expressed in rials.");
        }

        return $this === self::IRT ? $amount * 10 : $amount;
    }

    /** Tomans are rounded down, so 12505 rials becomes 1250 tomans. */
    public function fromRials(int $rials): int
    {
        if (! $this->isFiat() || $rials < 0) {
            throw new InvalidArgumentException("$rials rials cannot be expressed in $this->name.");
        }

        return $this === self::IRT ? intdiv($rials, 10) : $rials;
    }
}
