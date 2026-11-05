<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Pasargad extends PaymentProvider implements Reversible
{
    protected string $name = 'pasargad';

    protected string $title = 'Pasargad';

    protected string $baseUrl = 'https://pep.shaparak.ir/dorsa1/';

    protected array $errors = [
        0 => 'Pasargad response was incomplete',
        1 => 'Payment failed',
        2 => 'Transaction status is unknown',
        401 => 'Not authorized',
        500 => 'Internal server error',
        13000 => 'Invalid input',
        13001 => 'Malformed token',
        13002 => 'Invalid token',
        13003 => 'Invoice is not unique',
        13004 => 'Token is empty',
        13005 => 'Gateway timeout',
        13008 => 'Token is expired',
        13009 => 'URL token was not found',
        13010 => 'Invalid mobile number',
        13016 => 'Transaction was not found',
        13018 => 'Not found',
        13025 => 'Transaction was unsuccessful',
        13026 => 'Invalid card number',
        13028 => 'Invalid callback domain',
        13029 => 'Transaction was already confirmed',
        13030 => 'Transaction was verified and failed',
        13031 => 'Transaction is awaiting verification',
        13033 => 'Transaction was reversed',
        13034 => 'Transaction was verified and is awaiting confirmation',
        13045 => 'Cardholder canceled the transaction',
        13049 => 'Incomplete transaction',
        13054 => 'Invalid transaction',
        13055 => 'Invalid transaction amount',
        13071 => 'Transaction amount does not match the final price',
        13075 => 'Confirmation period has expired',
        13076 => 'Reversal period has expired',
        13093 => 'Transaction was unsuccessful',
        13094 => 'Duplicate transaction',
        13099 => 'Invalid terminal',
        13300 => 'Terminal is inactive',
        13301 => 'Merchant was not found',
        13350 => 'Request validation failed',
        13351 => 'Invalid invoice format',
        13352 => 'Invalid username',
        13366 => 'Token is inactive',
        13399 => 'Terminal number was not found',
        13402 => 'Invalid referrer',
        13407 => 'Invalid authentication',
        13422 => 'Invalid merchant IP address',
    ];

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

        if (! isset($extra['invoiceDate'])) {
            throw new InvalidArgumentException('Pasargad requires a Jalali invoiceDate in extra().');
        }

        $response = $this->post('api/payment/purchase', Arr::whereNotNull([
            ...$extra,
            'amount' => $this->providerAmount($rials),
            'invoice' => (string) $orderId,
            'serviceCode' => 8,
            'serviceType' => 'PURCHASE',
            'callbackApi' => $callback,
            'payerMail' => $email,
            'mobileNumber' => $phone,
            'terminalNumber' => $this->credential('terminal_id'),
        ]), $this->headers());
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['resultCode'] ?? '') !== '0') {
            throw $this->rejected($response, $data['resultCode'] ?? null, $data['resultMsg'] ?? null);
        }

        return new PendingPayment($this->requiredString($data, 'data.urlId'), $this->redirectUrl($data['data']['url'] ?? null));
    }

    /** The payment stays reversible until the contract window ends. Call confirm() to settle it now. */
    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (isset($extra['status']) && $extra['status'] !== 'success') {
            return false;
        }

        return $this->settle('verify-transactions', $id, $rials, $orderId);
    }

    public function confirm(mixed $id, int $rials, mixed $orderId): VerifiedPayment
    {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        return $this->settle('confirm-transactions', $id, $rials, $orderId);
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        $response = $this->post('api/payment/reverse-transactions', [
            'invoice' => (string) $orderId,
            'urlId' => $id,
        ], $this->headers());
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['resultCode'] ?? '') !== '0') {
            throw $this->rejected($response, $data['resultCode'] ?? null, $data['resultMsg'] ?? null);
        }

        return new ReversedPayment($id);
    }

    private function settle(string $operation, mixed $id, int $rials, mixed $orderId): VerifiedPayment
    {
        $response = $this->post('api/payment/'.$operation, [
            'invoice' => (string) $orderId,
            'urlId' => $id,
        ], $this->headers());
        $json = $this->json($response);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if (! $response->successful() || (string) ($json['resultCode'] ?? '') !== '0') {
            throw $this->rejected($response, $json['resultCode'] ?? null, $json['resultMsg'] ?? null);
        }

        $this->assertOrder($data['invoice'] ?? null, $orderId);
        $this->assertAmount($data['amount'] ?? null, $rials);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'referenceNumber'),
            cardHash: $data['hashedCardNumber'] ?? null,
            cardPan: $data['maskedCardNumber'] ?? null,
            amount: $rials,
            paymentId: $id,
        );
    }

    /** Tokens last ten minutes, so each operation authenticates afresh instead of caching one. */
    private function headers(): array
    {
        $response = $this->post('token/getToken', [
            'username' => $this->credential('username'),
            'password' => $this->credential('password'),
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['resultCode'] ?? '') !== '0') {
            throw $this->rejected($response, $data['resultCode'] ?? null, $data['resultMsg'] ?? null);
        }

        return ['Authorization' => 'Bearer '.$this->requiredString($data, 'token')];
    }
}
