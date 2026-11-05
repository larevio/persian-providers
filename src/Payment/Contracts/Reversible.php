<?php

namespace Larevio\PersianProviders\Payment\Contracts;

use Larevio\PersianProviders\Payment\Data\ReversedPayment;

interface Reversible
{
    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment;
}
