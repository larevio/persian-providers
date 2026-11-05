<?php

namespace Larevio\PersianProviders\Sms\Providers;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use IntlDateFormatter;
use InvalidArgumentException;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\BatchSender;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Contracts\Schedulable;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

class Sabanovin extends SmsProvider implements BatchSender, HasBalance, Schedulable
{
    protected string $name = 'sabanovin';

    protected string $title = 'Sabanovin';

    protected string $baseUrl = 'https://api.sabanovin.com/v1/';

    public function sendBulk(array $phones, string $text, string|int|null $line = null): bool
    {
        $this->validateRecipients($phones, $text);

        return $this->successful($this->post($this->path('sms/send.json'), [
            'gateway' => $this->line($line) ?? $this->credential('line'),
            'to' => implode(',', $phones),
            'text' => $text,
        ], form: true));
    }

    public function sendPattern(mixed $template, array $args, string $to): bool
    {
        if (! is_string($template) || trim($template) === '' || trim($to) === '' || ($args !== [] && array_is_list($args))) {
            throw new InvalidArgumentException('Sabanovin patterns require a code, recipient, and named variables.');
        }
        foreach ($args as $name => $value) {
            if (! is_string($name) || (! is_string($value) && ! is_numeric($value))) {
                throw new InvalidArgumentException('Pattern variables must have names and scalar values.');
            }
        }

        return $this->successful($this->post($this->path('sms/pattern.json'), [
            'code' => $template,
            'receptors' => [$to],
            'variables' => (object) array_map('strval', $args),
        ]));
    }

    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission
    {
        $messages = $this->validateMessages($messages);
        $response = $this->post($this->path('sms/send_array.json'), [
            'gateway' => $this->line($line) ?? $this->credential('line'),
            'to' => json_encode(array_column($messages, 'phone'), JSON_THROW_ON_ERROR),
            'text' => json_encode(array_column($messages, 'text'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ], form: true);

        return $this->submissionResult($response);
    }

    public function schedule(
        array $phones,
        string $text,
        DateTimeInterface $sendAt,
        string|int|null $line = null,
    ): SmsSubmission {
        $this->validateSchedule($phones, $text, $sendAt);
        if (! class_exists(IntlDateFormatter::class)) {
            throw new ConfigurationException('Sabanovin scheduling requires the PHP intl extension for Jalali dates.');
        }

        // The API accepts minute precision. Round up to avoid sending before the requested time.
        $scheduledAt = DateTimeImmutable::createFromInterface($sendAt)
            ->setTimestamp((int) (ceil((float) $sendAt->format('U.u') / 60) * 60));
        $formatter = new IntlDateFormatter('en_US@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'HH:mm yyyy/MM/dd');
        $jalali = $formatter->format($scheduledAt);
        if ($jalali === false) {
            throw new InvalidArgumentException('The scheduled time could not be formatted as a Jalali date.');
        }

        $response = $this->post($this->path('sms/send.json'), [
            'gateway' => $this->line($line) ?? $this->credential('line'),
            'to' => implode(',', $phones),
            'text' => $text,
            'at' => $jalali,
        ], form: true);

        return $this->submissionResult($response, $scheduledAt);
    }

    public function balance(): SmsBalance
    {
        $response = $this->get($this->path('account/balance.json'));
        $this->successful($response);

        return $this->balanceResult($this->json($response)['entry']['balance'] ?? null);
    }

    protected function path(string $method): string
    {
        return '/'.rawurlencode($this->credential('token')).'/'.$method;
    }

    protected function successful(Response $response): bool
    {
        $data = $this->json($response);
        $status = $data['status']['code'] ?? null;

        if (! $response->successful() || (string) $status !== '200') {
            throw $this->rejected($response, is_numeric($status) ? (int) $status : null, $data['status']['message'] ?? null);
        }

        return true;
    }

    protected function submissionResult(Response $response, ?DateTimeInterface $sendAt = null): SmsSubmission
    {
        $this->successful($response);
        $data = $this->json($response);
        $entries = $data['entries'] ?? [];
        if (! is_array($entries)) {
            throw new InvalidResponseException('Sabanovin returned invalid message results.');
        }

        return $this->submission(array_column($entries, 'reference_id'), $data['batch_id'] ?? null, $sendAt, $data);
    }
}
