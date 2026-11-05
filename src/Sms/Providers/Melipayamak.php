<?php

namespace Larevio\PersianProviders\Sms\Providers;

use Illuminate\Http\Client\Response;
use Larevio\PersianProviders\Sms\Abstracts\SmsProvider;
use Larevio\PersianProviders\Sms\Contracts\HasBalance;
use Larevio\PersianProviders\Sms\Data\SmsBalance;

class Melipayamak extends SmsProvider implements HasBalance
{
    protected string $name = 'melipayamak';

    protected string $title = 'MeliPayamak';

    protected string $baseUrl = 'https://rest.payamak-panel.com/api/';

    public function sendBulk(array $phones, string $text, string|int|null $line = null): bool
    {
        $this->validateRecipients($phones, $text);

        $response = $this->post('/SendSMS/SendSMS', [
            ...$this->credentials(),
            'from' => $this->line($line) ?? $this->credential('line'),
            'to' => implode(',', $phones),
            'text' => $text,
            'isflash' => false,
        ], form: true);

        return $this->successful($response);
    }

    public function sendPattern(mixed $template, array $args, string $to): bool
    {
        $response = $this->post('/SendSMS/BaseServiceNumber', [
            ...$this->credentials(),
            'to' => $to,
            'text' => implode(';', $args),
            'bodyId' => $template,
        ], form: true);

        return $this->successful($response);
    }

    public function balance(): SmsBalance
    {
        $response = $this->post('/SendSMS/GetCredit', $this->credentials(), form: true);
        $this->successful($response);

        return $this->balanceResult($response->json('Value'));
    }

    protected function successful(Response $response): bool
    {
        $data = $this->json($response);
        $status = $data['RetStatus'] ?? null;
        $value = $data['Value'] ?? null;

        if (! $response->successful() || (string) $status !== '1') {
            throw $this->rejected(
                $response,
                is_numeric($value) ? (int) $value : (is_numeric($status) ? (int) $status : null),
                $data['StrRetStatus'] ?? (is_string($value) && ! is_numeric($value) ? $value : null),
            );
        }

        return true;
    }

    private function credentials(): array
    {
        return [
            'username' => $this->credential('username'),
            'password' => $this->credential('password'),
        ];
    }
}
