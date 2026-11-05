<?php

namespace Larevio\PersianProviders\Payment\Data;

readonly class PendingPayment
{
    public function __construct(public string $id, public string $url, public ?array $post = null) {}

    public function redirect(): void
    {
        if ($this->post) {
            response()->view('persianproviders::payment.redirect', [
                'url' => $this->url,
                'fields' => $this->post,
            ])->throwResponse();
        }

        redirect()->to($this->url)->throwResponse();
    }

    public function toArray(): array
    {
        if (! $this->post) {
            return [
                'method' => 'GET',
                'url' => $this->url,
            ];
        }

        return [
            'method' => 'POST',
            'url' => $this->url,
            'data' => $this->post,
        ];
    }
}
