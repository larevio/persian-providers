<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Sadad extends PaymentProvider
{
    protected string $name = 'sadad';

    protected string $title = 'Sadad';

    protected string $baseUrl = 'https://sadad.shaparak.ir/vpg/api/v0/';

    protected string $paymentUrl = 'https://sadad.shaparak.ir/VPG/Purchase?Token=';

    public function create(
        int $rials,
        string $description,
        mixed $orderId = null,
        mixed $phone = null,
        mixed $email = null,
        mixed $callback = null,
        array $extra = [],
    ): PendingPayment {
        $this->validateCreate($rials, $callback);
        $this->requireOrderId($orderId);

        $amount = $this->providerAmount($rials);
        $terminal = $this->credential('terminal_id');
        $response = $this->post('Request/PaymentRequest', [
            ...$extra,
            'TerminalId' => $terminal,
            'MerchantId' => $this->credential('merchant_id'),
            'Amount' => $amount,
            'SignData' => $this->sign($terminal.';'.$orderId.';'.$amount),
            'ReturnUrl' => $callback,
            'LocalDateTime' => date('m/d/Y g:i:s a'),
            'OrderId' => (string) $orderId,
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['ResCode'] ?? '') !== '0') {
            throw $this->rejected($response, $data['ResCode'] ?? null, $data['Description'] ?? null);
        }

        $token = $this->requiredString($data, 'Token');

        return new PendingPayment($token, $this->paymentUrl.urlencode($token));
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (isset($extra['ResCode']) && (string) $extra['ResCode'] !== '0') {
            return false;
        }

        $response = $this->post('Advice/Verify', [
            'Token' => $id,
            'SignData' => $this->sign((string) $id),
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['ResCode'] ?? '') !== '0') {
            throw $this->rejected($response, $data['ResCode'] ?? null, $data['Description'] ?? null);
        }

        $this->assertOrder($data['OrderId'] ?? null, $orderId);

        // The published sample does not list Amount in this response, so it is only checked when present.
        if (isset($data['Amount'])) {
            $this->assertAmount($data['Amount'], $rials);
        }

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'RetrivalRefNo'),
            amount: $rials,
            paymentId: $id,
        );
    }

    private function sign(string $value): string
    {
        $key = base64_decode($this->credential('key'), true);
        if ($key === false || strlen($key) !== 24) {
            throw new ConfigurationException('The Sadad key must be base64 encoded and decode to 24 bytes.');
        }

        $encrypted = openssl_encrypt($value, 'DES-EDE3', $key, OPENSSL_RAW_DATA);
        if ($encrypted === false) {
            throw new ConfigurationException('Sadad request signing failed. Check that OpenSSL can use DES-EDE3.');
        }

        return base64_encode($encrypted);
    }
}
