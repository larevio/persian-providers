<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Nextpay extends PaymentProvider implements Reversible
{
    protected string $name = 'nextpay';

    protected string $title = 'NextPay';

    protected string $baseUrl = 'https://nextpay.org/nx/gateway/';

    protected string $paymentUrl = 'https://nextpay.org/nx/gateway/payment/';

    protected Currency $currency = Currency::IRT;

    protected array $errors = [
        -2 => 'Payment was rejected by the customer or bank',
        -3 => 'Payment is awaiting the bank response',
        -4 => 'Payment was canceled',
        -20 => 'API key was not provided',
        -21 => 'Transaction ID was not provided',
        -22 => 'Amount was not provided',
        -23 => 'Callback URL was not provided',
        -24 => 'Invalid amount',
        -25 => 'Transaction has already been processed',
        -26 => 'Token was not provided',
        -27 => 'Invalid order ID',
        -28 => 'Custom fields must be JSON',
        -30 => 'Amount is below the minimum',
        -32 => 'Invalid callback URL',
        -33 => 'Invalid API key',
        -34 => 'Invalid transaction ID',
        -36 => 'Order ID was not provided',
        -37 => 'Transaction was not found',
        -38 => 'Token was not found',
        -39 => 'API key was not found',
        -40 => 'API key is blocked',
        -42 => 'Payment system error',
        -43 => 'Payment gateway was not found',
        -44 => 'Invalid bank response',
        -45 => 'Payment system is inactive',
        -46 => 'Invalid request',
        -49 => 'Duplicate transaction',
        -72 => 'Bank did not respond',
        -73 => 'Invalid or overly long callback URL',
        -91 => 'Refund failed',
        -92 => 'Refund error',
        -93 => 'Insufficient balance for refund',
        -94 => 'Refund key was not found',
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

        $response = $this->post('token', Arr::whereNotNull([
            ...$extra,
            'api_key' => $this->credential('token'),
            'order_id' => $orderId,
            'amount' => $this->providerAmount($rials),
            'currency' => 'IRT',
            'callback_uri' => $callback,
            'customer_phone' => $phone,
            'payer_desc' => $description,
        ]));
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['code'] ?? '') !== '-1') {
            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        $id = $this->requiredString($data, 'trans_id');

        return new PendingPayment($id, $this->paymentUrl.$id);
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        $response = $this->post('verify', [
            'api_key' => $this->credential('token'),
            'trans_id' => $id,
            'amount' => $this->providerAmount($rials),
            'currency' => 'IRT',
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['code'] ?? '') !== '0') {
            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        $this->assertAmount($data['amount'] ?? null, $rials);
        $this->assertOrder($data['order_id'] ?? null, $orderId);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'Shaparak_Ref_Id'),
            cardPan: $data['card_holder'] ?? null,
            amount: $this->chargedRials($rials),
            paymentId: $id,
        );
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);

        if ($rials === null) {
            throw new InvalidArgumentException('NextPay requires the original amount to reverse a transaction.');
        }

        $response = $this->post('verify', [
            'api_key' => $this->credential('token'),
            'trans_id' => $id,
            'amount' => $this->providerAmount($rials),
            'refund_request' => 'yes_money_back',
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['code'] ?? '') !== '-90') {
            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        return new ReversedPayment($id, amount: $this->chargedRials($rials));
    }
}
