<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Idpay extends PaymentProvider
{
    protected string $name = 'idpay';

    protected string $title = 'IDPay';

    protected string $baseUrl = 'https://api.idpay.ir/v1.1/';

    protected array $errors = [
        -1 => 'Unknown error',
        11 => 'User is banned',
        12 => 'API key not found',
        13 => 'The IP address is unauthorized',
        14 => 'Your gateway is not approved',
        15 => 'Service is not available',
        21 => 'Bank account is not approved',
        22 => 'Service not found',
        23 => 'Service authorization failed',
        24 => 'Bank account is disabled',
        31 => 'Transaction id should not be empty',
        32 => 'Order id should not be empty',
        33 => 'Amount should not be empty',
        34 => 'Amount is below the minimum allowed amount',
        35 => 'Amount exceeds the maximum allowed amount',
        36 => 'Amount exceeds the permitted limit',
        37 => 'Callback should not be empty',
        38 => 'The callback domain is unauthorized',
        39 => 'Callback is not validated',
        51 => 'Transaction creation failed',
        52 => 'Inquiry returned no result',
        53 => 'Payment approval failed',
        54 => 'The maximum time of approval has been passed',
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

        $response = $this->call('payment', [
            ...$extra,
            'order_id' => $orderId,
            'amount' => $this->providerAmount($rials),
            ...Arr::whereNotNull(['phone' => $phone, 'mail' => $email]),
            'desc' => $description,
            'callback' => $callback,
        ]);
        $data = $this->json($response);

        if (! $response->successful() || isset($data['error_code'])) {
            throw $this->rejected($response, $data['error_code'] ?? null, $data['error_message'] ?? null);
        }

        return new PendingPayment($this->requiredString($data, 'id'), $this->redirectUrl($data['link'] ?? null));
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (isset($extra['status']) && (string) $extra['status'] !== '10') {
            return false;
        }

        $response = $this->call('payment/verify', [
            'id' => $id,
            'order_id' => $orderId,
        ]);
        $data = $this->json($response);
        $status = (string) ($data['status'] ?? '');

        if (! $response->successful() || ! in_array($status, ['100', '101'], true)) {
            throw $this->rejected($response, $data['error_code'] ?? $data['status'] ?? null, $data['error_message'] ?? null);
        }

        $this->assertAmount($data['amount'] ?? null, $rials);
        $this->assertOrder($data['order_id'] ?? null, $orderId);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'track_id'),
            cardHash: $data['payment']['hashed_card_no'] ?? null,
            cardPan: $data['payment']['card_no'] ?? null,
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $status === '101',
        );
    }

    private function call(string $endpoint, array $payload): Response
    {
        return $this->post($endpoint, $payload, [
            'X-API-KEY' => $this->credential('token'),
            'X-SANDBOX' => (int) ($this->getConfig()['sandbox'] ?? 0),
        ]);
    }
}
