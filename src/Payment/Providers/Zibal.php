<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Zibal extends PaymentProvider
{
    protected string $name = 'zibal';

    protected string $title = 'Zibal';

    protected string $baseUrl = 'https://gateway.zibal.ir/v1/';

    protected string $paymentUrl = 'https://gateway.zibal.ir/start/';

    protected array $errors = [
        102 => 'Merchant ID not found',
        103 => 'Merchant is inactive',
        104 => 'Merchant is invalid',
        105 => 'Amount should be bigger than 1,000 IRR',
        106 => 'Callback url is invalid',
        113 => 'Amount exceeds maximum limit',
        114 => 'National ID is invalid',
        201 => 'Transaction was verified previously',
        202 => 'Payment was not completed',
        203 => 'trackId is invalid',
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

        $response = $this->post('request', [
            ...$extra,
            'merchant' => $this->credential('token'),
            'amount' => $this->providerAmount($rials),
            'callbackUrl' => $callback,
            'description' => $description,
            ...Arr::whereNotNull(['orderId' => $orderId, 'mobile' => $phone]),
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['result'] ?? '') !== '100') {
            throw $this->rejected($response, $data['result'] ?? null, $data['message'] ?? null);
        }

        $trackId = $this->requiredString($data, 'trackId');

        return new PendingPayment($trackId, $this->paymentUrl.$trackId);
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        if (isset($extra['success']) && (string) $extra['success'] !== '1') {
            return false;
        }

        $response = $this->post('verify', [
            'merchant' => $this->credential('token'),
            'trackId' => is_string($id) && ctype_digit($id) ? (int) $id : $id,
        ]);
        $data = $this->json($response);
        $result = (string) ($data['result'] ?? '');

        if (! $response->successful() || ! in_array($result, ['100', '201'], true)) {
            throw $this->rejected($response, $data['result'] ?? null, $data['message'] ?? null);
        }

        $this->assertAmount($data['amount'] ?? null, $rials);
        $this->assertOrder($data['orderId'] ?? null, $orderId);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'refNumber'),
            cardPan: $data['cardNumber'] ?? null,
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $result === '201',
        );
    }
}
