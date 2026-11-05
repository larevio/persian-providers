<?php

namespace Larevio\PersianProviders\Sms\Contracts;

use Larevio\PersianProviders\Sms\Data\SmsBalance;

interface HasBalance
{
    public function balance(): SmsBalance;
}
