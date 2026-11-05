<?php

namespace Larevio\PersianProviders\Exchange\Data;

use Larevio\PersianProviders\Enums\Currency;

/** Rates are decimal strings: $to units per one $from unit. */
readonly class ExchangeRate
{
    public function __construct(
        public Currency $from,
        public Currency $to,
        public string $last,
        public ?string $bestSell,
        public ?string $bestBuy,
        public ?int $timestamp = null,
    ) {}
}
