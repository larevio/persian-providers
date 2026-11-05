<?php

namespace Larevio\PersianProviders\Sms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendPatternSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly mixed $phone,
        public readonly string $templateId,
        public readonly array $args = [],
        public readonly ?string $provider = null,
    ) {}

    public function handle(): void
    {
        sms($this->provider)
            ->to($this->phone)
            ->templateId($this->templateId)
            ->sendPattern($this->args);
    }
}
