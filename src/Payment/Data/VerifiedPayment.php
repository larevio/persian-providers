<?php

namespace Larevio\PersianProviders\Payment\Data;

use Larevio\PersianProviders\Enums\Bank;

/** $amount and $fee are in rials. */
readonly class VerifiedPayment
{
    public function __construct(
        public ?string $referenceId = null,
        public ?string $cardHash = null,
        public ?string $cardPan = null,
        public ?int $fee = null,
        public ?int $amount = null,
        public mixed $paymentId = null,
        public bool $alreadyVerified = false,
    ) {}

    public function bank(): ?Bank
    {
        return $this->cardPan ? Bank::fromCard($this->cardPan) : null;
    }
}
