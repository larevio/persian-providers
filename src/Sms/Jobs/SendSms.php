<?php

namespace Larevio\PersianProviders\Sms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $phone,
        public readonly string $text,
        public readonly string|int|null $line = null,
        public readonly ?string $provider = null,
    ) {}

    public function handle(): void
    {
        sms($this->provider)
            ->to($this->phone)
            ->message($this->text)
            ->send($this->line);
    }
}
