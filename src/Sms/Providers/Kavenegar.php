<?php

namespace Larevio\PersianProviders\Sms\Providers;

use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\BatchSender;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Contracts\Schedulable;
use Larevio\PersianProviders\Sms\Data\SmsBalance;
use Larevio\PersianProviders\Sms\Data\SmsSubmission;

class Kavenegar extends SmsProvider implements BatchSender, HasBalance, Schedulable
{
    protected string $name = 'kavenegar';

    protected string $title = 'Kavenegar';

    protected string $baseUrl = 'https://api.kavenegar.com/v1/';

    protected array $errors = [
        400 => 'Incomplete request parameters',
        401 => 'Account is inactive',
        402 => 'Operation failed',
        403 => 'Invalid API key',
        404 => 'Method not found',
        405 => 'Invalid request method',
        409 => 'Service is temporarily unavailable',
        411 => 'Invalid recipient',
        412 => 'Invalid sender',
        413 => 'Invalid message',
        414 => 'Too many recipients',
        418 => 'Insufficient credit',
        432 => 'Template token was not found',
    ];

    public function sendBulk(array $phones, string $text, string|int|null $line = null): bool
    {
        $this->validateRecipients($phones, $text);

        $data = [
            'receptor' => implode(',', $phones),
            'message' => $text,
        ];

        $sender = $this->line($line);
        if ($sender !== null) {
            $data['sender'] = $sender;
        }

        return $this->successful($this->post($this->path('sms/send.json'), $data, form: true));
    }

    public function sendPattern(mixed $template, array $args, string $to): bool
    {
        $tokens = array_is_list($args) ? $args : [];
        $token = $args['token'] ?? $tokens[0] ?? null;
        if ($token === null || $token === '') {
            throw new InvalidArgumentException('The Kavenegar template token is required.');
        }

        $data = [
            'receptor' => $to,
            'template' => $template,
            'token' => $token,
        ];

        foreach (['token2', 'token3', 'token10', 'token20'] as $index => $key) {
            $value = $args[$key] ?? $tokens[$index + 1] ?? null;
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $this->successful($this->post($this->path('verify/lookup.json'), $data, form: true));
    }

    public function sendBatch(array $messages, string|int|null $line = null): SmsSubmission
    {
        $messages = $this->validateMessages($messages);
        $sender = $this->line($line);
        if ($sender === null) {
            throw new InvalidArgumentException('Kavenegar batch sending requires a sender line.');
        }
        $response = $this->post($this->path('sms/sendarray.json'), [
            'receptor' => json_encode(array_column($messages, 'phone'), JSON_THROW_ON_ERROR),
            'message' => json_encode(array_column($messages, 'text'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sender' => json_encode(array_fill(0, count($messages), (string) $sender), JSON_THROW_ON_ERROR),
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
        $response = $this->post($this->path('sms/send.json'), Arr::whereNotNull([
            'receptor' => implode(',', $phones),
            'message' => $text,
            'sender' => $this->line($line),
            'date' => $sendAt->getTimestamp(),
        ]), form: true);

        return $this->submissionResult($response, $sendAt);
    }

    public function balance(): SmsBalance
    {
        $response = $this->get($this->path('account/info.json'));
        $this->successful($response);

        return $this->balanceResult($this->json($response)['entries']['remaincredit'] ?? null, 'IRR');
    }

    protected function submissionResult(Response $response, ?DateTimeInterface $sendAt = null): SmsSubmission
    {
        $this->successful($response);
        $data = $this->json($response);
        if (! is_array($data['entries'] ?? null)) {
            throw new InvalidResponseException('Kavenegar returned invalid message results.');
        }

        return $this->submission(array_column($data['entries'], 'messageid'), null, $sendAt, $data);
    }

    protected function path(string $method): string
    {
        return '/'.rawurlencode($this->credential('token')).'/'.$method;
    }

    protected function successful(Response $response): bool
    {
        $data = $this->json($response);
        $status = $data['return']['status'] ?? null;

        if (! $response->successful() || (string) $status !== '200') {
            throw $this->rejected($response, is_numeric($status) ? (int) $status : null, $data['return']['message'] ?? null);
        }

        return true;
    }
}
