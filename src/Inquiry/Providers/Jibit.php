<?php

namespace Larevio\PersianProviders\Inquiry\Providers;

use Illuminate\Support\Facades\Cache;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Inquiry\Abstracts\InquiryProvider;
use Larevio\PersianProviders\Inquiry\Data\BankAccount;

/** Jibit identity services, https://developers.jibit.ir/ */
class Jibit extends InquiryProvider
{
    protected string $name = 'jibit';

    protected string $title = 'Jibit';

    protected string $baseUrl = 'https://napi.jibit.ir/ide';

    public function getAccessToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addHours(6), function (): string {
            $response = $this->post('/v1/tokens/generate', [
                'apiKey' => $this->credential('api_key'),
                'secretKey' => $this->credential('secret_key'),
            ]);
            $data = $this->json($response);

            if (! $response->successful()) {
                throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
            }

            return $this->requiredString($data, 'accessToken');
        });
    }

    public function matchMobile(string $mobile, string $nationalCode): bool
    {
        return $this->matched($this->sendRequest('/v1/services/matching', [
            'mobileNumber' => $mobile,
            'nationalCode' => $nationalCode,
        ]));
    }

    /** Sending birthDate selects Jibit's "Match IBAN with identity" variant. */
    public function matchIban(string $iban, string $nationalCode, string $birthday): bool
    {
        return $this->matched($this->sendRequest('/v1/services/matching', [
            'iban' => $iban,
            'nationalCode' => $nationalCode,
            'birthDate' => str_replace(['/', '-', '.'], '', $birthday),
        ]));
    }

    public function ibanInquiry(string $iban): BankAccount
    {
        $info = $this->sendRequest('/v1/ibans', ['value' => $iban])['ibanInfo'] ?? null;
        if (! is_array($info) || ! is_array($info['owners'] ?? null) || $info['owners'] === []) {
            throw new InvalidResponseException('Jibit returned invalid IBAN information.');
        }

        $owners = array_map(fn ($owner) => $this->requiredString($owner, 'firstName').' '.$this->requiredString($owner, 'lastName'), $info['owners']);

        return new BankAccount(
            iban: $iban,
            accountNumber: isset($info['depositNumber']) ? (string) $info['depositNumber'] : null,
            bankName: $info['bank'] ?? null,
            ownerName: implode(', ', $owners),
        );
    }

    private function sendRequest(string $endpoint, array $payload): array
    {
        $response = $this->get($endpoint.'?'.http_build_query($payload), [
            'Authorization' => 'Bearer '.$this->getAccessToken(),
        ]);
        $data = $this->json($response);

        if (! $response->successful()) {
            // Jibit doesn't document the token lifetime. Drop a rejected token so the next call fetches a new one.
            if ($response->status() === 401 || ($data['code'] ?? null) === 'forbidden') {
                Cache::forget($this->tokenCacheKey());
            }

            throw $this->rejected($response, $data['code'] ?? null, $data['message'] ?? null);
        }

        return $data;
    }

    private function matched(array $data): bool
    {
        if (! is_bool($data['matched'] ?? null)) {
            throw new InvalidResponseException('Jibit returned an invalid matching result.');
        }

        return $data['matched'];
    }

    private function tokenCacheKey(): string
    {
        return 'persianproviders:jibit:'.hash('sha256', $this->credential('api_key').':'.$this->credential('secret_key'));
    }
}
