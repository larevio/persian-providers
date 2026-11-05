<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Vandar extends PaymentProvider
{
    protected string $name = 'vandar';

    protected string $title = 'Vandar';

    protected string $baseUrl = 'https://ipg.vandar.io/api/v3/';

    protected string $paymentUrl = 'https://ipg.vandar.io/v3/';

    protected array $errors = [
        400 => 'Invalid request',
        401 => 'Invalid or missing token',
        403 => 'Access denied',
        404 => 'Endpoint was not found',
        405 => 'Invalid request method',
        406 => 'Request must use JSON',
        410 => 'Endpoint is no longer available',
        422 => 'Invalid request field',
        429 => 'Too many requests',
        500 => 'Internal server error',
        503 => 'Service is temporarily unavailable',
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

        $response = $this->post('send', Arr::whereNotNull([
            ...$extra,
            'api_key' => $this->credential('token'),
            'amount' => $this->providerAmount($rials),
            'callback_url' => $callback,
            'mobile_number' => $phone,
            'factorNumber' => $orderId,
            'description' => $description,
        ]));
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['status'] ?? '') !== '1') {
            throw $this->error($response, $data);
        }

        $token = $this->requiredString($data, 'token');

        return new PendingPayment($token, $this->paymentUrl.$token);
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        if (isset($extra['payment_status']) && $extra['payment_status'] !== 'OK') {
            return false;
        }

        $request = ['api_key' => $this->credential('token'), 'token' => $id];
        $response = $this->post('verify', $request);
        $data = $this->json($response);
        $status = (string) ($data['status'] ?? '');
        $alreadyVerified = $status === '2';

        // Verify answers only once. A repeat returns status 2, so the details come from the transaction endpoint.
        if ($alreadyVerified) {
            $response = $this->post('transaction', $request);
            $data = $this->json($response);
            $status = (string) ($data['status'] ?? '');
        }

        if (! $response->successful() || $status !== '1') {
            throw $this->error($response, $data);
        }

        $this->assertGrossAmount($data, $rials);
        $this->assertOrder($data['factorNumber'] ?? null, $orderId);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'transId'),
            cardHash: $data['cid'] ?? $data['CID'] ?? null,
            cardPan: $data['cardNumber'] ?? null,
            fee: $this->feeInRials($data['wage'] ?? null),
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $alreadyVerified,
        );
    }

    /** The reported amount includes the fee when the payer bears it. */
    private function assertGrossAmount(array $data, int $rials): void
    {
        $wage = $this->feeInRials($data['wage'] ?? null);
        $withWage = $wage === null ? null : $rials + $wage;

        if (! $this->matchesAmount($data['amount'] ?? null, $rials) && ($withWage === null || ! $this->matchesAmount($data['amount'] ?? null, $withWage))) {
            throw new PaymentVerificationException('Vandar verified an amount that does not match the requested amount.');
        }
    }

    private function error(Response $response, array $data): ProviderException
    {
        $errors = $data['errors'] ?? null;
        $message = is_array($errors) ? implode(', ', array_filter($errors, 'is_string')) : ($errors ?? $data['message'] ?? null);

        return $this->rejected($response, null, $message);
    }
}
