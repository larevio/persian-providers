<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use Larevio\PersianProviders\Enums\Currency;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Panapal extends PaymentProvider
{
    protected string $name = 'panapal';

    protected string $title = 'PanaPal';

    protected string $baseUrl = 'https://paanapardakht.com/webservice/rest/';

    protected Currency $currency = Currency::IRT;

    protected array $errors = [
        -1 => 'Request must be sent through the web service',
        -2 => 'Merchant ID is required',
        -3 => 'Amount is required',
        -4 => 'Callback URL is required',
        -5 => 'Amount is below the minimum',
        -6 => 'Merchant ID was not found',
        -7 => 'Merchant ID is inactive',
        -8 => 'Business account is required',
        -9 => 'Invalid IP address',
        -10 => 'Callback URL does not match the registered gateway URL',
        -11 => 'Transaction creation failed',
        -12 => 'Invalid authority or transaction was not found',
        -13 => 'Authority is required',
        -14 => 'Transaction details were not found',
        -15 => 'Transaction has not been paid',
        -16 => 'Amount does not match the transaction',
        -17 => 'Transaction does not belong to this merchant',
        -18 => 'Merchant wallet could not be updated',
        -19 => 'Transaction status is unknown',
        -20 => 'Invalid card number',
        -21 => 'Payment card does not match the allowed card',
        -26 => 'Web service access is restricted to Iranian IP addresses',
        101 => 'Transaction was already verified',
        102 => 'Web service connection failed',
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

        $response = $this->post('PaymentRequest', Arr::whereNotNull([
            ...$extra,
            'MerchantID' => $this->credential('merchant_id'),
            'Amount' => $this->providerAmount($rials),
            'InvoiceID' => $orderId,
            'Email' => $email,
            'Mobile' => $phone,
            'Description' => $description,
            'CallbackURL' => $callback,
        ]), form: true);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['Status'] ?? '') !== '100') {
            throw $this->rejected($response, $data['Status'] ?? null);
        }

        return new PendingPayment($this->requiredString($data, 'Authority'), $this->redirectUrl($data['PaymentUrl'] ?? null));
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        if (isset($extra['PaymentStatus']) && $extra['PaymentStatus'] !== 'OK') {
            return false;
        }

        if ($orderId !== null && isset($extra['InvoiceID']) && (string) $extra['InvoiceID'] !== (string) $orderId) {
            throw new PaymentVerificationException('The PanaPal callback order does not match the requested order.');
        }

        $response = $this->post('PaymentVerification', [
            'MerchantID' => $this->credential('merchant_id'),
            'Authority' => $id,
            'Amount' => $this->providerAmount($rials),
        ], form: true);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['Status'] ?? '') !== '100') {
            throw $this->rejected($response, $data['Status'] ?? null);
        }

        $this->assertAmount($data['Amount'] ?? null, $rials);

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'RefID'),
            cardPan: $data['MaskCardNumber'] ?? null,
            amount: $this->chargedRials($rials),
            paymentId: $id,
        );
    }
}
