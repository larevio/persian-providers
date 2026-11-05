<?php

namespace Larevio\PersianProviders\Sms\Providers;

use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\BatchSender;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Contracts\Schedulable;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

class SmsIr extends SmsProvider implements BatchSender, HasBalance, Schedulable
{
    protected string $name = 'smsir';

    protected string $title = 'SMS.ir';

    protected string $baseUrl = 'https://api.sms.ir/v1/';

    protected array $errors = [
        10 => 'Invalid API key',
    ];

    public function sendBulk(array $phones, string $text, string|int|null $line = null): bool
    {
        $this->validateRecipients($phones, $text);

        $response = $this->post('/send/bulk', [
            'lineNumber' => $this->line($line) ?? $this->credential('line'),
            'messageText' => $text,
            'mobiles' => $phones,
        ], $this->headers());

        return $this->successful($response);
    }

    public function sendPattern(mixed $template, array $args, string $to): bool
    {
        $response = $this->post('/send/verify', [
            'mobile' => $to,
            'templateId' => $template,
            'parameters' => collect($args)->map(function (mixed $value, string|int $key): array {
                return [
                    'name' => $key,
                    'value' => $value,
                ];
            })->values()->toArray(),
        ], $this->headers());

        return $this->successful($response);
    }

    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission
    {
        $messages = $this->validateMessages($messages);
        $response = $this->post('/send/likeToLike', [
            'lineNumber' => $this->line($line) ?? $this->credential('line'),
            'messageTexts' => array_column($messages, 'text'),
            'mobiles' => array_column($messages, 'phone'),
        ], $this->headers());

        return $this->submissionResult($response);
    }

    public function schedule(
        array $phones,
        string $text,
        DateTimeInterface $sendAt,
        string|int|null $line = null,
    ): SmsSubmission {
        $this->validateSchedule($phones, $text, $sendAt);
        $response = $this->post('/send/bulk', [
            'lineNumber' => $this->line($line) ?? $this->credential('line'),
            'messageText' => $text,
            'mobiles' => array_values($phones),
            'sendDateTime' => $sendAt->getTimestamp(),
        ], $this->headers());

        return $this->submissionResult($response, $sendAt);
    }

    public function balance(): SmsBalance
    {
        $response = $this->get('/credit', $this->headers());
        $this->successful($response);

        return $this->balanceResult($this->json($response)['data'] ?? null);
    }

    protected function submissionResult(Response $response, ?DateTimeInterface $sendAt = null): SmsSubmission
    {
        $this->successful($response);
        $json = $this->json($response);
        $data = $json['data'] ?? null;
        if (! is_array($data) || (isset($data['messageIds']) && ! is_array($data['messageIds']))) {
            throw new InvalidResponseException('SMS.ir returned invalid message results.');
        }

        return $this->submission($data['messageIds'] ?? [], $data['packId'] ?? null, $sendAt, $json);
    }

    protected function successful(Response $response): bool
    {
        $data = $this->json($response);
        $status = $data['status'] ?? null;

        if (! $response->successful() || (string) $status !== '1') {
            throw $this->rejected($response, is_numeric($status) ? (int) $status : null, $data['message'] ?? null);
        }

        return true;
    }

    private function headers(): array
    {
        return ['X-API-KEY' => $this->credential('token')];
    }
}
