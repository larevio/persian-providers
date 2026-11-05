<?php

namespace Larevio\PersianProviders\Payment\Providers;

use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Payment\Abstracts\PaymentProvider;
use Larevio\PersianProviders\Payment\Contracts\Reversible;
use Larevio\PersianProviders\Payment\Data\PendingPayment;
use Larevio\PersianProviders\Payment\Data\ReversedPayment;
use Larevio\PersianProviders\Payment\Data\VerifiedPayment;

class Damavand extends PaymentProvider implements Reversible
{
    protected string $name = 'damavand';

    protected string $title = 'Damavand';

    protected string $baseUrl = 'https://ecd.shaparak.ir/ipg_ecd/';

    protected string $paymentUrl = 'https://ecd.shaparak.ir/ipg_ecd/PayStart';

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
        $date = date('Y/m/d');
        $time = date('H:i');
        $terminal = $this->credential('terminal_id');

        $response = $this->post('PayRequest', [
            ...$extra,
            'TerminalNumber' => $terminal,
            'BuyID' => (string) $orderId,
            'Amount' => $amount,
            'Date' => $date,
            'Time' => $time,
            'RedirectURL' => $callback,
            'Language' => $extra['Language'] ?? 'fa',
            'CheckSum' => sha1($terminal.$orderId.$amount.$date.$time.$callback.$this->credential('key')),
        ]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['State'] ?? '') !== '1') {
            throw $this->rejected($response, $data['ErrorCode'] ?? null, $data['ErrorDescription'] ?? null);
        }

        $token = $this->requiredString($data, 'Res');

        return new PendingPayment($token, $this->paymentUrl, ['Token' => $token]);
    }

    /** PayConfirmation returns no amount, so the callback Amount is compared instead, as the documentation asks. */
    public function verify(
        mixed $id,
        int $rials,
        mixed $orderId = null,
        array $extra = [],
    ): VerifiedPayment|false {
        $this->validateTransaction($id, $rials);
        $this->requireOrderId($orderId);

        if (isset($extra['State']) && (string) $extra['State'] !== '1') {
            return false;
        }

        if (isset($extra['Token']) && (string) $extra['Token'] !== (string) $id) {
            throw new PaymentVerificationException('The Damavand callback token does not match the requested payment.');
        }

        if (isset($extra['Amount'])) {
            $this->assertAmount($extra['Amount'], $rials);
        }

        $response = $this->post('PayConfirmation', ['Token' => $id]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['State'] ?? '') !== '1') {
            throw $this->rejected($response, $data['ErrorCode'] ?? null, $data['ErrorDescription'] ?? null);
        }

        $this->assertOrder($data['Res'] ?? null, $orderId);

        return new VerifiedPayment(
            referenceId: isset($extra['ReferenceNumber']) ? (string) $extra['ReferenceNumber'] : null,
            amount: $rials,
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
        $this->requireOrderId($orderId);

        $response = $this->post('PayReverse', ['Token' => $id]);
        $data = $this->json($response);

        if (! $response->successful() || (string) ($data['State'] ?? '') !== '1') {
            throw $this->rejected($response, $data['ErrorCode'] ?? null, $data['ErrorDescription'] ?? null);
        }

        $this->assertOrder($data['Res'] ?? null, $orderId);

        return new ReversedPayment($id);
    }
}
