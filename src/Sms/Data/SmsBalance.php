<?php

namespace Larevio\PersianProviders\Sms\Data;

readonly class SmsBalance
{
    public function __construct(public string $amount, public ?string $unit = null) {}
}
