<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;
use SensitiveParameter;

class Zarinpal extends PaymentProvider implements Reversible
{
    protected string $name = 'zarinpal';

    protected string $title = 'Zarinpal';

    protected string $baseUrl = 'https://payment.zarinpal.com/pg/v4/payment/';

    protected string $paymentUrl = 'https://payment.zarinpal.com/pg/StartPay/';

    protected array $errors = [
        -9 => 'Validation error',
        -10 => 'Terminal is not valid, please check merchant_id or ip address.',
        -11 => 'Terminal is not active, please contact our support team.',
        -12 => 'Too many attempts, please try again later.',
        -13 => 'Terminal limit reached.',
        -14 => 'The callback URL domain does not match the registered terminal domain.',
        -15 => 'Terminal user is suspended; please contact support.',
        -16 => 'Terminal user level is not valid; please contact support.',
        -17 => 'Terminal user level is not valid; please contact support.',
        -18 => 'The referrer address does not match the registered domain.',
        -19 => 'Terminal user transactions are banned.',
        -30 => 'Terminal do not allow to accept floating wages.',
        -31 => 'Terminal do not allow to accept wages, please add default bank account in panel.',
        -32 => 'Wages is not valid, Total wages(floating) has been overload max amount.',
        -33 => 'Wages floating is not valid.',
        -34 => 'Wages is not valid, Total wages(fixed) has been overload max amount.',
        -35 => 'Wages is not valid, Total wages(floating) has been reached the limit in max parts.',
        -36 => 'The minimum amount for wages(floating) should be 10,000 Rials',
        -37 => 'One or more iban entered for wages(floating) from the bank side are inactive.',
        -40 => 'Invalid extra params, expire_in is not valid.',
        -41 => 'Maximum amount is 100,000,000 tomans.',
        -50 => 'Session is not valid, amounts values is not the same.',
        -51 => 'Session is not valid, session is not active paid try.',
        -52 => 'Oops!!, please contact our support team',
        -53 => 'Session is not this merchant_id session.',
        -54 => 'Invalid authority.',
        -55 => 'Manual payment request not found.',
        -60 => 'Session can not be reversed with bank.',
        -61 => 'Session is not in success status.',
        -62 => 'Terminal ip limit must be active.',
        -63 => 'Maximum time for reverse this session is expired.',
    ];

    public function __construct(#[SensitiveParameter] ?array $config = null)
    {
        parent::__construct($config);

        if ($this->getConfig()['sandbox'] ?? false) {
            $this->baseUrl = 'https://sandbox.zarinpal.com/pg/v4/payment/';
            $this->paymentUrl = 'https://sandbox.zarinpal.com/pg/StartPay/';
        }
    }

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

        $metadata = $extra['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('Zarinpal metadata must be an array.');
        }

        $response = $this->post('request.json', [
            ...$extra,
            'merchant_id' => $this->credential('token'),
            'amount' => $this->providerAmount($rials),
            // The verify endpoint is documented in rials, so the session is always opened in rials.
            'currency' => 'IRR',
            'description' => $description,
            'metadata' => array_filter([
                ...$metadata,
                ...Arr::whereNotNull([
                    'mobile' => $phone,
                    'email' => $email,
                    'order_id' => $orderId === null ? null : (string) $orderId,
                ]),
            ]),
            'callback_url' => $callback,
        ]);
        $json = $this->json($response);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if (! $response->successful() || (string) ($data['code'] ?? '') !== '100') {
            throw $this->rejected($response, $json['errors']['code'] ?? $data['code'] ?? null, $json['errors']['message'] ?? null);
        }

        $authority = $this->requiredString($data, 'authority');

        return new PendingPayment($authority, $this->paymentUrl.$authority);
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);

        if (isset($extra['Status']) && $extra['Status'] !== 'OK') {
            return false;
        }

        $response = $this->post('verify.json', [
            'merchant_id' => $this->credential('token'),
            'amount' => $this->providerAmount($rials),
            'authority' => $id,
        ]);
        $json = $this->json($response);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $code = (string) ($data['code'] ?? '');

        if (! $response->successful() || ! in_array($code, ['100', '101'], true)) {
            throw $this->rejected($response, $json['errors']['code'] ?? $data['code'] ?? null, $json['errors']['message'] ?? null);
        }

        return new VerifiedPayment(
            referenceId: $this->requiredString($data, 'ref_id'),
            cardHash: $data['card_hash'] ?? null,
            cardPan: $data['card_pan'] ?? null,
            fee: $this->feeInRials($data['fee'] ?? null),
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $code === '101',
        );
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);

        $response = $this->post('reverse.json', [
            'merchant_id' => $this->credential('token'),
            'authority' => $id,
        ]);
        $json = $this->json($response);
        $code = $json['data']['code'] ?? null;

        if (! $response->successful() || (string) $code !== '100') {
            throw $this->rejected($response, $json['errors']['code'] ?? $code, $json['errors']['message'] ?? null);
        }

        return new ReversedPayment($id);
    }
}
