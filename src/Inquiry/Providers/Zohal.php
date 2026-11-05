<?php

namespace Larevio\PersianProviders\Inquiry\Providers;

use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Inquiry\Abstracts\InquiryProvider;
use Larevio\PersianProviders\Inquiry\Data\Address;
use Larevio\PersianProviders\Inquiry\Data\BankAccount;
use Larevio\PersianProviders\Inquiry\Data\NationalIdentity;

class Zohal extends InquiryProvider
{
    protected string $name = 'zohal';

    protected string $title = 'Zohal';

    protected string $baseUrl = 'https://service.zohal.io/api/v0/';

    public function matchMobile(string $mobile, string $nationalCode): bool
    {
        return $this->matched($this->sendRequest('/services/inquiry/shahkar', [
            'mobile' => $mobile,
            'national_code' => $nationalCode,
        ]));
    }

    public function matchIban(string $iban, string $nationalCode, string $birthday): bool
    {
        return $this->matched($this->sendRequest('/services/inquiry/check_iban_with_national_code', [
            'IBAN' => $iban,
            'national_code' => $nationalCode,
            'birth_date' => $birthday,
        ]));
    }

    public function matchCard(string $card, string $nationalCode, string $birthday): bool
    {
        return $this->matched($this->sendRequest('/services/inquiry/check_card_with_national_code', [
            'card_number' => $card,
            'birth_date' => $birthday,
            'national_code' => $nationalCode,
        ]));
    }

    public function matchCardOwner(string $card, string $name): bool
    {
        return $this->matched($this->sendRequest('/services/inquiry/check_card_with_name', [
            'name' => $name,
            'card_number' => $card,
        ]));
    }

    public function matchIbanOwner(string $iban, string $name): bool
    {
        return $this->matched($this->sendRequest('/services/inquiry/check_iban_with_name', [
            'IBAN' => $iban,
            'name' => $name,
        ]));
    }

    public function cardToIban(string $card): string
    {
        return $this->cardToAccount($card)->iban;
    }

    public function cardToAccount(string $card): BankAccount
    {
        $data = $this->sendRequest('/services/inquiry/card_to_iban', ['card_number' => $card]);

        return new BankAccount(
            iban: $this->requiredString($data, 'IBAN'),
            cardNumber: $card,
            bankName: $data['bank_name'] ?? null,
            ownerName: $this->requiredString($data, 'name'),
        );
    }

    public function cardOwnerInquiry(string $card): string
    {
        return $this->requiredString($this->sendRequest('/services/inquiry/card_inquiry', ['card_number' => $card]), 'name');
    }

    public function nationalIdentityInquiry(string $nationalCode, string $birthday): NationalIdentity|false
    {
        $data = $this->sendRequest('/services/inquiry/national_identity_inquiry', [
            'national_code' => $nationalCode,
            'birth_date' => $birthday,
        ]);

        if (! $this->matched($data)) {
            return false;
        }

        return new NationalIdentity(
            nationalCode: $nationalCode,
            firstName: $this->requiredString($data, 'first_name'),
            lastName: $this->requiredString($data, 'last_name'),
            fatherName: $data['father_name'] ?? null,
            alive: is_bool($data['alive'] ?? null) ? $data['alive'] : (is_bool($data['is_dead'] ?? null) ? ! $data['is_dead'] : null),
        );
    }

    public function ibanInquiry(string $iban): BankAccount
    {
        $data = $this->sendRequest('/services/inquiry/iban', ['iban' => $iban]);

        return new BankAccount(
            iban: $iban,
            bankName: $data['bank_name'] ?? null,
            ownerName: $this->requiredString($data, 'name'),
        );
    }

    public function postalCodeInquiry(string $postalCode): Address
    {
        $data = $this->sendRequest('/services/inquiry/postal_code_inquiry', ['postal_code' => $postalCode])['address'] ?? null;
        if (! is_array($data)) {
            throw new InvalidResponseException('Zohal returned an invalid address.');
        }

        return new Address(
            postalCode: $postalCode,
            province: $data['province'] ?? null,
            city: $data['city'] ?? $data['town'] ?? null,
            address1: $data['street'] ?? null,
            address2: $data['street2'] ?? null,
            district: $data['district'] ?? null,
            buildingName: $data['building_name'] ?? null,
            floor: $data['floor'] ?? null,
            extra: $data['description'] ?? null,
        );
    }

    /** Zohal reports failures such as CARD_NOT_FOUND in the body of an HTTP 200 with result=1. */
    private function sendRequest(string $endpoint, array $payload): array
    {
        $response = $this->post($endpoint, $payload, [
            'Authorization' => 'Bearer '.$this->credential('token'),
        ]);
        $json = $this->json($response);
        $body = is_array($json['response_body'] ?? null) ? $json['response_body'] : [];

        if (! $response->successful() || ($body['error_code'] ?? null) !== null || (string) ($json['result'] ?? '') !== '1') {
            throw $this->rejected($response, $body['error_code'] ?? $json['result'] ?? null, $body['message'] ?? null);
        }

        if (! is_array($body['data'] ?? null)) {
            throw new InvalidResponseException('Zohal returned no inquiry data.');
        }

        return $body['data'];
    }

    private function matched(array $data): bool
    {
        if (! is_bool($data['matched'] ?? null)) {
            throw new InvalidResponseException('Zohal returned an invalid matching result.');
        }

        return $data['matched'];
    }
}
