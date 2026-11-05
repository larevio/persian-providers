<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Paystar extends PaymentProvider
{
    protected string $name = 'paystar';

    protected string $title = 'PayStar';

    protected string $baseUrl = 'https://core.paystar.ir/api/pardakht/';

    protected string $paymentUrl = 'https://core.paystar.ir/api/pardakht/payment?token=';

    protected array $errors = [
        -1 => 'Invalid request',
        -2 => 'Gateway is not active',
        -3 => 'Duplicate token',
        -4 => 'Amount exceeds the gateway limit',
        -5 => 'Invalid ref_num',
        -6 => 'Transaction was already verified',
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

        $amount = $this->providerAmount($rials);
        $response = $this->post('create', Arr::whereNotNull([
            ...$extra,
            'amount' => $amount,
            'order_id' => (string) $orderId,
            'callback' => $callback,
            'sign' => $this->sign($amount.'#'.$orderId.'#'.$callback),
            'phone' => $phone,
            'mail' => $email,
            'description' => $description,
        ]), $this->headers());
        $json = $this->json($response);

        if (! $response->successful() || (string) ($json['status'] ?? '') !== '1') {
            throw $this->rejected($response, $json['status'] ?? null, $json['message'] ?? null);
        }

        return new PendingPayment(
            $this->requiredString($json, 'data.ref_num'),
            $this->paymentUrl.urlencode($this->requiredString($json, 'data.token')),
        );
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        if (isset($extra['status']) && (string) $extra['status'] !== '1') {
            return false;
        }

        if (! isset($extra['card_number'], $extra['tracking_code'])) {
            throw new InvalidArgumentException('PayStar requires the callback card_number and tracking_code to verify a transaction.');
        }

        if (isset($extra['order_id'])) {
            $this->assertOrder($extra['order_id'], $orderId);
        }

        $amount = $this->providerAmount($rials);
        $response = $this->post('verify', [
            'ref_num' => $id,
            'amount' => $amount,
            'sign' => $this->sign($amount.'#'.$id.'#'.$extra['card_number'].'#'.$extra['tracking_code']),
        ], $this->headers());
        $json = $this->json($response);
        $status = (string) ($json['status'] ?? '');
        $alreadyVerified = $status === '-6';

        if (! $alreadyVerified && (! $response->successful() || $status !== '1')) {
            throw $this->rejected($response, $json['status'] ?? null, $json['message'] ?? null);
        }

        if (! $alreadyVerified) {
            $this->assertAmount($json['data']['price'] ?? null, $rials);
        }

        return new VerifiedPayment(
            referenceId: (string) $extra['tracking_code'],
            cardPan: $json['data']['card_number'] ?? $extra['card_number'],
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $alreadyVerified,
        );
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->credential('gateway_id')];
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha512', $value, $this->credential('sign_key'));
    }
}
