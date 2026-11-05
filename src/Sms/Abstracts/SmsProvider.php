<?php

namespace Larevio\PersianProviders\Sms\Abstracts;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Larevio\PersianProviders\Abstracts\Provider;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;
use Larevio\PersianProviders\Sms\Providers\Farapayamak;
use Larevio\PersianProviders\Sms\Providers\Ghasedak;
use Larevio\PersianProviders\Sms\Providers\Kavenegar;
use Larevio\PersianProviders\Sms\Providers\Melipayamak;
use Larevio\PersianProviders\Sms\Providers\Sabanovin;
use Larevio\PersianProviders\Sms\Providers\SmsIr;
use SensitiveParameter;

abstract class SmsProvider extends Provider
{
    public function send(string $phone, string $text, string|int|null $line = null): bool
    {
        return $this->sendBulk([$phone], $text, $line);
    }

    /** @param array<string> $phones */
    abstract public function sendBulk(array $phones, string $text, string|int|null $line = null): bool;

    abstract public function sendPattern(mixed $template, array $args, string $to): bool;

    public static function make(?string $name = null, #[SensitiveParameter] ?array $config = null): self
    {
        $name = self::providerName($name, 'sms');
        $providerClass = match ($name) {
            'kavenegar' => Kavenegar::class,
            'smsir' => SmsIr::class,
            'ghasedak' => Ghasedak::class,
            'sabanovin' => Sabanovin::class,
            'melipayamak' => Melipayamak::class,
            'farapayamak' => Farapayamak::class,
            default => throw new ConfigurationException("Unknown SMS provider '$name'."),
        };

        return new $providerClass($config);
    }

    protected function getConfigNamespace(): string
    {
        return 'sms';
    }

    protected function balanceResult(mixed $amount, ?string $unit = null): SmsBalance
    {
        if (
            (! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || ! is_numeric($amount)
            || ! is_finite((float) $amount)
        ) {
            throw new InvalidResponseException("$this->title returned an invalid credit balance.");
        }

        return new SmsBalance((string) $amount, $unit);
    }

    protected function submission(
        array $ids,
        mixed $batchId,
        ?DateTimeInterface $sendAt,
        array $raw,
    ): SmsSubmission {
        $ids = array_values(array_map('strval', array_filter($ids, fn ($id) => $this->validIdentifier($id))));
        $batchId = $this->validIdentifier($batchId) ? (string) $batchId : null;
        if ($ids === [] && $batchId === null) {
            throw new InvalidResponseException("$this->title returned no submission identifiers.");
        }

        return new SmsSubmission(
            messageIds: $ids,
            batchId: $batchId,
            scheduledAt: $sendAt === null ? null : DateTimeImmutable::createFromInterface($sendAt),
            raw: $raw,
        );
    }

    protected function validIdentifier(mixed $id): bool
    {
        return (is_int($id) && $id > 0) || (is_string($id) && trim($id) !== '' && (! is_numeric($id) || (float) $id > 0));
    }

    /** @return list<array{phone: string, text: string}> */
    protected function validateMessages(array $messages): array
    {
        if ($messages === []) {
            throw new InvalidArgumentException('At least one message is required.');
        }
        foreach ($messages as $message) {
            if (
                ! is_array($message)
                || ! is_string($message['phone'] ?? null)
                || ! is_string($message['text'] ?? null)
                || trim($message['phone']) === ''
                || trim($message['text']) === ''
            ) {
                throw new InvalidArgumentException('Each batch item must contain a non-empty phone and text string.');
            }
        }

        return array_values($messages);
    }

    protected function validateRecipients(array $phones, string $text): void
    {
        if ($phones === [] || trim($text) === '') {
            throw new InvalidArgumentException('At least one recipient and a non-empty message are required.');
        }

        foreach ($phones as $phone) {
            if (! is_string($phone) || trim($phone) === '') {
                throw new InvalidArgumentException('Each recipient must be a non-empty phone string.');
            }
        }
    }

    /** A configured or explicit sender line, or null to let the provider choose. */
    protected function line(string|int|null $line): string|int|null
    {
        $line ??= $this->getConfig()['line'] ?? null;

        return $line === '' ? null : $line;
    }

    protected function validateSchedule(array $phones, string $text, DateTimeInterface $sendAt): void
    {
        $this->validateRecipients($phones, $text);
        if ($sendAt->getTimestamp() <= now()->getTimestamp()) {
            throw new InvalidArgumentException('The scheduled time must be in the future.');
        }
    }
}
