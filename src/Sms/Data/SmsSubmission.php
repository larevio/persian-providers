<?php

namespace Larevio\PersianProviders\Sms\Data;

use DateTimeImmutable;

/** Accepted by the provider. Not proof of delivery. */
readonly class SmsSubmission
{
    /** @param list<string> $messageIds */
    public function __construct(
        public array $messageIds,
        public ?string $batchId = null,
        public ?DateTimeImmutable $scheduledAt = null,
        public array $raw = [],
    ) {}
}
