<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

/** Sepehr IPG guide v4.0.2 (token, AdviceWithInvoicId and Rollback). */
class Sepehr extends PaymentProvider implements Reversible
{
    protected string $name = 'sepehr';

    protected string $title = 'Sepehr';

    protected string $baseUrl = 'https://sepehr.shaparak.ir/Rest/V1/PeymentApi/';

    protected array $errors = [
        -1 => 'Transaction was not found',
        -2 => 'IP address mismatch, or the transaction was already reversed',
        -3 => 'General error',
        -4 => 'The request is not possible for this transaction (callback URL mismatch)',
        -5 => 'IP address is not registered for this merchant',
        -6 => 'Transaction reversal is not enabled for this merchant',
        -7 => 'Invoice ID does not match the original transaction',
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

        $terminal = $this->credential('terminal_id');
        $response = $this->post('GetToken', Arr::whereNotNull([
            'payload' => '',
            ...$extra,
            'TerminalID' => $terminal,
            'Amount' => (string) $this->providerAmount($rials),
            'InvoiceID' => (string) $orderId,
            'callbackURL' => $callback,
            'CellNumber' => $phone,
        ]));
        $data = $this->json($response);
        $token = $data['Accesstoken'] ?? $data['AccessToken'] ?? null;

        if (! $response->successful() || (string) ($data['Status'] ?? '') !== '0' || ! is_string($token) || $token === '') {
            throw $this->rejected($response, $data['Status'] ?? null);
        }

        if ($this->getConfig()['use_get_method'] ?? false) {
            return new PendingPayment($token, 'https://sepehr.shaparak.ir/Payment/Pay?'.http_build_query(['token' => $token, 'terminalId' => $terminal]));
        }

        return new PendingPayment($token, 'https://sepehr.shaparak.ir/Pay', ['TerminalID' => $terminal, 'token' => $token]);
    }

    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (empty($extra['digitalreceipt']) || (isset($extra['respcode']) && (string) $extra['respcode'] !== '0')) {
            return false;
        }

        $response = $this->post('AdviceWithInvoicId', [
            'digitalreceipt' => $extra['digitalreceipt'],
            'Tid' => $this->credential('terminal_id'),
            'InvoiceID' => (string) $orderId,
        ]);
        $status = $this->status($response);

        $this->assertAmount($response->json('ReturnId'), $rials);

        return new VerifiedPayment(
            referenceId: isset($extra['rrn']) ? (string) $extra['rrn'] : null,
            cardPan: $extra['cardnumber'] ?? null,
            amount: $rials,
            paymentId: $id,
            alreadyVerified: $status === 'duplicate',
        );
    }

    public function reverse(
        mixed $id,
        ?int $rials = null,
        mixed $orderId = null,
        array $extra = [],
    ): ReversedPayment {
        $this->validateTransaction($id, $rials);

        if (empty($extra['digitalreceipt'])) {
            throw new InvalidArgumentException('Sepehr requires the callback digitalreceipt to reverse a transaction.');
        }

        $response = $this->post('Rollback', [
            'digitalreceipt' => $extra['digitalreceipt'],
            'Tid' => $this->credential('terminal_id'),
        ]);
        $status = $this->status($response);
        $amount = $response->json('ReturnId');

        return new ReversedPayment(
            $id,
            (string) $extra['digitalreceipt'],
            is_scalar($amount) && ctype_digit((string) $amount) ? (int) $amount : null,
            alreadyReversed: $status === 'duplicate',
        );
    }

    /** Advice and Rollback answer OK or Duplicate with the amount in ReturnId, or NOK with an error code there. */
    private function status(Response $response): string
    {
        $data = $this->json($response);
        $status = strtolower((string) ($data['Status'] ?? ''));

        if (! $response->successful() || ! in_array($status, ['ok', 'duplicate'], true)) {
            throw $this->rejected($response, $data['ReturnId'] ?? null, $data['Message'] ?? null);
        }

        return $status;
    }
}
