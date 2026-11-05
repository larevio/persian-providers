<?php

namespace Larevio\PersianProviders\Sms\Providers;

use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\BatchSender;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Contracts\Schedulable;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

class Ghasedak extends SmsProvider implements BatchSender, HasBalance, Schedulable
{
    protected string $name = 'ghasedak';

    protected string $title = 'Ghasedak';

    protected string $baseUrl = 'https://gateway.ghasedak.me/rest/api/v1/WebService/';

    protected array $errors = [
        400 => 'Invalid request parameters',
        401 => 'Invalid API key or inactive account',
        402 => 'Operation failed',
        406 => 'Sender line ownership is unverified',
        412 => 'Sender line or service is unavailable',
        413 => 'Message or recipient count exceeds the limit',
        416 => 'IP address is unauthorized',
        418 => 'Insufficient credit',
        419 => 'Invalid sending rate',
        420 => 'Message contains an unauthorized link',
        422 => 'Message contains an invalid character',
        426 => 'Plan upgrade is required',
        428 => 'Invalid template',
        429 => 'Template parameter was not found',
        451 => 'Duplicate request',
        500 => 'Service error',
    ];

    public function send(string $phone, string $text, string|int|null $line = null): bool
    {
        $this->validateMessages([['phone' => $phone, 'text' => $text]]);

        return $this->successful($this->post('/SendSingleSMS', Arr::whereNotNull([
            'receptor' => $phone,
            'message' => $text,
            'lineNumber' => $this->line($line),
        ]), $this->headers()));
    }

    public function sendBulk(array $phones, string $text, string|int|null $line = null): bool
    {
        $this->validateRecipients($phones, $text);

        return $this->successful($this->post('/SendBulkSMS', Arr::whereNotNull([
            'receptors' => array_values($phones),
            'message' => $text,
            'lineNumber' => $this->line($line),
            'clientReferenceId' => (string) Str::uuid(),
        ]), $this->headers()));
    }

    public function sendPattern(mixed $template, array $args, string $to): bool
    {
        if (trim($to) === '') {
            throw new InvalidArgumentException('A recipient is required.');
        }

        $inputs = [];
        foreach ($args as $name => $value) {
            $inputs[] = [
                'param' => (string) $name,
                'value' => (string) $value,
            ];
        }

        return $this->successful($this->post('/SendOtpSMS', [
            'receptors' => [[
                'mobile' => $to,
                'clientReferenceId' => (string) Str::uuid(),
            ]],
            'templateName' => $template,
            'inputs' => $inputs,
        ], $this->headers()));
    }

    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission
    {
        $messages = $this->validateMessages($messages);
        $line = $this->line($line);
        $items = array_map(fn ($message) => Arr::whereNotNull([
            'receptor' => $message['phone'],
            'message' => $message['text'],
            'lineNumber' => $line,
            'clientReferenceId' => (string) Str::uuid(),
        ]), $messages);
        $response = $this->post('/SendPairToPairSMS', ['items' => $items], $this->headers());

        return $this->submissionResult($response, 'Items');
    }

    public function schedule(
        array $phones,
        string $text,
        DateTimeInterface $sendAt,
        string|int|null $line = null,
    ): SmsSubmission {
        $this->validateSchedule($phones, $text, $sendAt);
        $response = $this->post('/SendBulkSMS', Arr::whereNotNull([
            'receptors' => array_values($phones),
            'message' => $text,
            'lineNumber' => $this->line($line),
            'clientReferenceId' => (string) Str::uuid(),
            'sendDate' => $sendAt->format(DATE_ATOM),
        ]), $this->headers());

        return $this->submissionResult($response, 'Receptors', $sendAt);
    }

    public function balance(): SmsBalance
    {
        $response = $this->get('/GetAccountInformation', $this->headers());
        $this->successful($response);
        $data = $this->json($response);

        return $this->balanceResult($data['Data']['Credit'] ?? $data['data']['credit'] ?? null, 'IRR');
    }

    protected function submissionResult(
        Response $response,
        string $collection,
        ?DateTimeInterface $sendAt = null,
    ): SmsSubmission {
        $this->successful($response);
        $data = $this->json($response);
        $entries = $data['Data'][$collection] ?? $data['data'][lcfirst($collection)] ?? null;
        if (! is_array($entries)) {
            throw new InvalidResponseException('Ghasedak returned invalid message results.');
        }
        $ids = array_map(fn ($entry) => is_array($entry) ? ($entry['MessageId'] ?? $entry['messageId'] ?? null) : null, $entries);

        return $this->submission($ids, null, $sendAt, $data);
    }

    protected function headers(): array
    {
        return ['ApiKey' => $this->credential('token')];
    }

    protected function successful(Response $response): bool
    {
        $data = $this->json($response);
        $success = $data['IsSuccess'] ?? $data['isSuccess'] ?? null;
        $status = $data['StatusCode'] ?? $data['statusCode'] ?? null;

        if (! $response->successful() || $success !== true || (string) $status !== '200') {
            throw $this->rejected($response, is_numeric($status) ? (int) $status : null, $data['Message'] ?? $data['message'] ?? null);
        }

        return true;
    }
}
