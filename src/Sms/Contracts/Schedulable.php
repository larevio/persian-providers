<?php

namespace Larevio\PersianProviders\Sms\Contracts;

use DateTimeInterface;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

interface Schedulable
{
    /** @param array<string> $phones */
    public function schedule(
        array $phones,
        string $text,
        DateTimeInterface $sendAt,
        string|int|null $line = null,
    ): SmsSubmission;
}
