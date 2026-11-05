<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class AqayePardakht extends PaymentProvider
{
    protected string $name = 'aqayepardakht';

    protected string $title = 'AqayePardakht';

    protected string $baseUrl = 'https://panel.aqayepardakht.ir/api/v2/';

    protected string $paymentUrl = 'https://panel.aqayepardakht.ir/startpay/';

    protected Currency $currency = Currency::IRT;

    protected array $errors = [
        0 => 'Payment was not completed',
        2 => 'Transaction was already verified and paid',
        -1 => 'Amount is required',
        -2 => 'Gateway PIN is required',
        -3 => 'Callback URL is required',
        -4 => 'Amount must be numeric',
        -5 => 'Amount is outside the allowed range',
        -6 => 'Invalid gateway PIN',
        -7 => 'Transaction ID is required',
        -8 => 'Transaction was not found',
        -9 => 'Gateway PIN does not match the transaction',
        -10 => 'Amount does not match the transaction',
        -11 => 'Gateway is pending approval or inactive',
        -12 => 'Merchant is not allowed to make this request',
        -13 => 'Card number must contain 16 digits',
        -14 => 'Gateway is being used on another site',
        -15 => 'Callback domain does not match the approved gateway domain',
        -16 => 'Invalid referrer',
        -17 => 'Callback method must be POST or GET',
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

        $response = $this->post('create', Arr::whereNotNull([
            ...$extra,
            'pin' => $this->pin(),
            'amount' => $this->providerAmount($rials),
            'callback' => $callback,
            'invoice_id' => $orderId,
            'mobile' => $phone,
            'email' => $email,
            'description' => $description,
        ]));
        $data = $this->json($response);

        if (! $response->successful() || ($data['status'] ?? null) !== 'success') {
            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        $id = $this->requiredString($data, 'transid');
        $path = $this->pin() === 'sandbox' ? 'sandbox/' : '';

        return new PendingPayment($id, $this->paymentUrl.$path.$id);
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

        if ($orderId !== null && isset($extra['invoice_id']) && (string) $extra['invoice_id'] !== (string) $orderId) {
            throw new PaymentVerificationException('The AqayePardakht callback order does not match the requested order.');
        }

        $response = $this->post('verify', [
            'pin' => $this->pin(),
            'amount' => $this->providerAmount($rials),
            'transid' => $id,
        ]);
        $data = $this->json($response);
        $code = (string) ($data['code'] ?? '');
        $alreadyVerified = $code === '2';

        if (! $alreadyVerified && (! $response->successful() || ($data['status'] ?? null) !== 'success' || $code !== '1')) {
            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        return new VerifiedPayment(
            referenceId: isset($extra['tracking_number']) ? (string) $extra['tracking_number'] : null,
            cardPan: $extra['cardnumber'] ?? null,
            amount: $this->chargedRials($rials),
            paymentId: $id,
            alreadyVerified: $alreadyVerified,
        );
    }

    private function pin(): string
    {
        return ($this->getConfig()['sandbox'] ?? false) ? 'sandbox' : $this->credential('pin');
    }
}
