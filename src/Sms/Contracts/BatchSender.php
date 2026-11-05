<?php

namespace Larevio\PersianProviders\Sms\Contracts;

use Larevio\PersianProviders\Sms\Data\SmsSubmission;

interface BatchSender
{
    /** @param array<array{phone: string, text: string}> $messages */
    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission;
}
