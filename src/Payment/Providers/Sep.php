<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Sep extends PaymentProvider implements Reversible
{
    private const TRANSACTION_API = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/';

    protected string $name = 'sep';

    protected string $title = 'SEP';

    protected string $baseUrl = 'https://sep.shaparak.ir/onlinepg/onlinepg';

    protected string $paymentUrl = 'https://sep.shaparak.ir/onlinepg/onlinepg';

    protected array $errors = [
        -2 => 'Transaction was not found',
        -6 => 'More than half an hour has passed since the transaction',
        -104 => 'Terminal is inactive',
        -105 => 'Terminal was not found',
        -106 => 'The requesting IP address is not allowed',
        5 => 'Transaction was reversed',
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

        $response = $this->post($this->baseUrl, Arr::whereNotNull([
            ...$extra,
            'Action' => 'token',
            'Amount' => $this->providerAmount($rials),
            'TerminalId' => $this->credential('terminal_id'),
            'ResNum' => $orderId,
            'CellNumber' => $phone,
            'RedirectUrl' => $callback,
        ]));
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['status'] ?? '') !== '1') {
            throw $this->rejected($response, $data['errorCode'] ?? null, $data['errorDesc'] ?? null);
        }

        $token = $this->requiredString($data, 'token');
        $useGet = (bool) ($this->getConfig()['use_get_method'] ?? false);
        $url = $useGet ? 'https://sep.shaparak.ir/OnlinePG/SendToken?token='.$token : $this->paymentUrl;

        if ($this->getConfig()['use_neo_ipg'] ?? false) {
            $neoUrl = $this->redirectUrl($response->header('X-IPG-Url'));
            $url = $useGet ? $neoUrl.'?token='.$token : $neoUrl;
        }

        return new PendingPayment($token, $url, $useGet ? null : ['Token' => $token]);
    }

    /** SEP's verify response omits ResNum, so also reject any RefNum your application has already consumed. */
    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (empty($extra['RefNum']) || (isset($extra['State']) && $extra['State'] !== 'OK')) {
            return false;
        }

        if (isset($extra['ResNum'])) {
            $this->assertOrder($extra['ResNum'], $orderId);
        }

        $response = $this->post(self::TRANSACTION_API.'VerifyTransaction', $this->transactionRequest($extra));
        $data = $this->json($response);
        $code = (string) ($data['ResultCode'] ?? '');

        if (! $response->successful() || ! in_array($code, ['0', '2'], true) || ($code === '0' && ($data['Success'] ?? null) !== true)) {
            throw $this->rejected($response, $data['ResultCode'] ?? null, $data['ResultDescription'] ?? null);
        }

        $detail = $data['TransactionDetail'] ?? null;
        if (! is_array($detail)) {
            throw new InvalidResponseException('SEP returned no transaction details.');
        }

        if (isset($detail['RefNum']) && (string) $detail['RefNum'] !== (string) $extra['RefNum']) {
            throw new PaymentVerificationException('SEP verified a reference that does not match the requested payment.');
        }

        if (isset($detail['TerminalNumber']) && (string) $detail['TerminalNumber'] !== $this->credential('terminal_id')) {
            throw new PaymentVerificationException('SEP verified a terminal that does not match the configured terminal.');
        }

        if (isset($detail['ResNum'])) {
            $this->assertOrder($detail['ResNum'], $orderId);
        }

        $this->assertAmount($detail['OrginalAmount'] ?? null, $rials);

        return new VerifiedPayment(
            referenceId: $this->requiredString($detail, 'RRN'),
            cardHash: $detail['HashedPan'] ?? null,
            cardPan: $detail['MaskedPan'] ?? null,
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $code === '2',
        );
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);

        if (empty($extra['RefNum'])) {
            throw new InvalidArgumentException('SEP requires the callback RefNum to reverse a transaction.');
        }

        $response = $this->post(self::TRANSACTION_API.'ReverseTransaction', $this->transactionRequest($extra));
        $data = $this->json($response);
        $code = (string) ($data['ResultCode'] ?? '');

        if (! $response->successful() || ! in_array($code, ['0', '2'], true)) {
            throw $this->rejected($response, $data['ResultCode'] ?? null, $data['ResultDescription'] ?? null);
        }

        return new ReversedPayment($id, (string) $extra['RefNum'], alreadyReversed: $code === '2');
    }

    private function transactionRequest(array $extra): array
    {
        return Arr::whereNotNull([
            'RefNum' => $extra['RefNum'],
            'TerminalNumber' => $this->credential('terminal_id'),
            'TxnRandomSessionKey' => $extra['TxnRandomSessionKey'] ?? null,
        ]);
    }
}
