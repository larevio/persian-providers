<?php

namespace Larevio\PersianProviders\Sms;

use BadMethodCallException;
use DateTimeInterface;
use InvalidArgumentException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\BatchSender;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Contracts\Schedulable;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;
use SensitiveParameter;

class Sms
{
    private array $mobiles = [];

    private string $message = '';

    private mixed $templateId = null;

    private function __construct(private readonly SmsProvider $provider) {}

    public static function make(string|SmsProvider|null $provider = null, #[SensitiveParameter] ?array $config = null): Sms
    {
        return new Sms($provider instanceof SmsProvider ? $provider : SmsProvider::make($provider, $config));
    }

    /** @param string|array<string> $mobile */
    public function to(string|array $mobile): Sms
    {
        if (is_array($mobile)) {
            $this->mobiles = array_values($mobile);
        } else {
            $this->mobiles = [$mobile];
        }

        return $this;
    }

    public function message(string $message): Sms
    {
        $this->message = $message;

        return $this;
    }

    public function templateId(mixed $templateId): Sms
    {
        $this->templateId = $templateId;

        return $this;
    }

    public function send(string|int|null $line = null): bool
    {
        if ($this->mobiles === [] || trim($this->message) === '') {
            throw new InvalidArgumentException('At least one recipient and a non-empty message are required.');
        }

        if (count($this->mobiles) > 1) {
            return $this->provider->sendBulk($this->mobiles, $this->message, $line);
        }

        return $this->provider->send($this->mobiles[0], $this->message, $line);
    }

    public function sendPattern(array $args = []): bool
    {
        if (count($this->mobiles) !== 1 || $this->templateId === null || $this->templateId === '') {
            throw new InvalidArgumentException('Pattern sending requires exactly one recipient and a template ID.');
        }

        return $this->provider->sendPattern($this->templateId, $args, $this->mobiles[0]);
    }

    public function supportsBatch(): bool
    {
        return $this->provider instanceof BatchSender;
    }

    public function supportsSchedule(): bool
    {
        return $this->provider instanceof Schedulable;
    }

    public function supportsBalance(): bool
    {
        return $this->provider instanceof HasBalance;
    }

    /** @param array<array{phone: string, text: string}> $messages */
    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission
    {
        if (! $this->provider instanceof BatchSender) {
            throw new BadMethodCallException('The selected SMS provider does not support batch sending.');
        }

        return $this->provider->sendBatch($messages, $line);
    }

    public function schedule(DateTimeInterface $sendAt, string|int|null $line = null): SmsSubmission
    {
        if (! $this->provider instanceof Schedulable) {
            throw new BadMethodCallException('The selected SMS provider does not support scheduling.');
        }

        return $this->provider->schedule($this->mobiles, $this->message, $sendAt, $line);
    }

    public function balance(): SmsBalance
    {
        if (! $this->provider instanceof HasBalance) {
            throw new BadMethodCallException('The selected SMS provider does not support balance queries.');
        }

        return $this->provider->balance();
    }

    public function header(string $text): Sms
    {
        $this->message = $text."\n".$this->message;

        return $this;
    }

    /** Appends a line to the message, by default the opt-out text Iranian operators require on advertising SMS. */
    public function footer(string $text = 'لغو 11'): Sms
    {
        $this->message = $this->message."\n".$text;

        return $this;
    }
}
