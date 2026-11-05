<?php

namespace Larevio\PersianProviders\Inquiry\Abstracts;

use BadMethodCallException;
use Larevio\PersianProviders\Abstracts\Provider;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Inquiry\Data\Address;
use Larevio\PersianProviders\Inquiry\Data\BankAccount;
use Larevio\PersianProviders\Inquiry\Data\NationalIdentity;
use Larevio\PersianProviders\Inquiry\Providers\Jibit;
use Larevio\PersianProviders\Inquiry\Providers\Zohal;
use SensitiveParameter;

/** Providers override the lookups they support; the rest throw BadMethodCallException. */
abstract class InquiryProvider extends Provider
{
    public function matchMobile(string $mobile, string $nationalCode): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function matchIban(string $iban, string $nationalCode, string $birthday): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function matchCard(string $card, string $nationalCode, string $birthday): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function matchCardOwner(string $card, string $name): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function matchIbanOwner(string $iban, string $name): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function cardToIban(string $card): string
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function cardToAccount(string $card): BankAccount
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function cardOwnerInquiry(string $card): string
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function nationalIdentityInquiry(string $nationalCode, string $birthday): NationalIdentity|false
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function ibanInquiry(string $iban): BankAccount
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function postalCodeInquiry(string $postalCode): Address
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public static function make(?string $name = null, #[SensitiveParameter] ?array $config = null): self
    {
        $name = self::providerName($name, 'inquiry');
        $providerClass = match ($name) {
            'zohal' => Zohal::class,
            'jibit' => Jibit::class,
            default => throw new ConfigurationException("Unknown inquiry provider '$name'."),
        };

        return new $providerClass($config);
    }

    protected function getConfigNamespace(): string
    {
        return 'inquiry';
    }

    private function unsupported(string $method): BadMethodCallException
    {
        return new BadMethodCallException("$this->title does not support $method().");
    }
}
