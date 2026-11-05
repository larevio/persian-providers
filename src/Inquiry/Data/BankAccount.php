<?php

namespace Larevio\PersianProviders\Inquiry\Data;

use Larevio\PersianProviders\Enums\Bank;

readonly class BankAccount
{
    public function __construct(
        public ?string $iban = null,
        public ?string $cardNumber = null,
        public ?string $accountNumber = null,
        public ?string $bankName = null,
        public ?string $ownerName = null,
    ) {}

    public function bank(): ?Bank
    {
        if ($this->iban) {
            $bank = Bank::fromIban($this->iban);
            if ($bank !== null) {
                return $bank;
            }
        }

        if ($this->cardNumber) {
            return Bank::fromCard($this->cardNumber);
        }

        return null;
    }
}
