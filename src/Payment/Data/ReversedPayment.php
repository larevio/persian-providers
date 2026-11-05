<?php

namespace Larevio\PersianProviders\Payment\Data;

readonly class ReversedPayment
{
    public function __construct(
        public mixed $paymentId,
        public ?string $referenceId = null,
        public ?int $amount = null,
        public bool $alreadyReversed = false,
    ) {}
}
