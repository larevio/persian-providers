<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Payping extends PaymentProvider implements Reversible
{
    protected string $name = 'payping';

    protected string $title = 'PayPing';

    protected string $baseUrl = 'https://api.payping.ir/v3/pay/';

    protected Currency $currency = Currency::IRT;

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

        $response = $this->post('https://api.payping.ir/v3/pay', Arr::whereNotNull([
            ...$extra,
            ...Arr::whereNotNull(['clientRefId' => $orderId === null ? null : (string) $orderId, 'payerIdentity' => $phone ?? $email]),
            'amount' => $this->providerAmount($rials),
            'description' => $description,
            'returnUrl' => $callback,
        ]), $this->headers());
        $data = $this->json($response);

        if ($response->status() !== 200) {
            throw $this->error($response, $data);
        }

        return new PendingPayment($this->requiredString($data, 'paymentCode'), $this->redirectUrl($data['url'] ?? null));
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        $refId = $this->referenceId($extra);
        $response = $this->post('verify', [
            'paymentCode' => $id,
            'paymentRefId' => $refId,
            'amount' => $this->providerAmount($rials),
        ], $this->headers());
        $data = $this->transactionData($response, $refId, $rials, $orderId, $id, allowDuplicate: true);

        return new VerifiedPayment(
            referenceId: (string) $data['paymentRefId'],
            cardHash: $data['cardHashPan'] ?? null,
            cardPan: $data['cardNumber'] ?? null,
            amount: $this->chargedRials($rials),
            paymentId: $id,
            alreadyVerified: $response->status() === 409,
        );
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);

        $refId = $this->referenceId($extra);
        $response = $this->post('reverse', [
            'paymentCode' => $id,
            'paymentRefId' => $refId,
        ], $this->headers());
        $data = $this->transactionData($response, $refId, $rials, $orderId, $id);

        return new ReversedPayment($id, (string) $data['paymentRefId'], $this->currency->toRials((int) $data['amount']));
    }

    private function referenceId(array $extra): int
    {
        $refId = $extra['paymentRefId'] ?? null;
        if ((! is_int($refId) && ! is_string($refId)) || ! ctype_digit((string) $refId) || (int) $refId <= 0) {
            throw new InvalidArgumentException('PayPing requires the numeric paymentRefId from the v3 callback.');
        }

        return (int) $refId;
    }

    private function transactionData(
        Response $response,
        int $refId,
        ?int $rials,
        mixed $orderId,
        mixed $id,
        bool $allowDuplicate = false,
    ): array {
        $data = $this->json($response);

        $duplicate = $allowDuplicate && $response->status() === 409 && ($data['metaData']['code'] ?? null) === 110;
        if ($duplicate) {
            $details = $data['metaData']['message'] ?? null;
            if (! is_array($details)) {
                throw new InvalidResponseException('PayPing returned incomplete duplicate verification details.');
            }
            $data = array_combine(array_map('lcfirst', array_keys($details)), array_values($details));
        }

        // HTTP 202 and 502 mean verification is still processing: the documented next step is to call verify again.
        if (! $duplicate && $response->status() !== 200) {
            throw $this->error($response, $data);
        }

        if (! isset($data['amount'], $data['paymentRefId']) || ! is_numeric($data['amount'])) {
            throw new InvalidResponseException('PayPing returned an incomplete transaction.');
        }

        if (isset($data['code']) && (string) $data['code'] !== (string) $id) {
            throw new PaymentVerificationException('PayPing returned a transaction code that does not match the requested payment.');
        }

        if ((string) $data['paymentRefId'] !== (string) $refId) {
            throw new PaymentVerificationException('PayPing returned a reference that does not match the requested payment.');
        }

        if ($rials !== null) {
            $this->assertAmount($data['amount'], $rials);
        }

        $this->assertOrder($data['clientRefId'] ?? null, $orderId);

        return $data;
    }

    private function error(Response $response, array $data): ProviderException
    {
        $code = $data['metaData']['code'] ?? null;
        $message = $data['message'] ?? $data['metaData']['errors'][0]['message'] ?? $data['errors'][0]['message'] ?? $data['title'] ?? null;

        return $this->rejected($response, $code, $message);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->credential('token')];
    }
}
