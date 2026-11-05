<?php

use Larevio\PersianProviders\Exchange\Abstracts\ExchangeProvider;
use Larevio\PersianProviders\Inquiry\Abstracts\InquiryProvider;
use Larevio\PersianProviders\Payment\Payment;
use Larevio\PersianProviders\Sms\Sms;

if (! function_exists('payment')) {
    function payment(?string $provider = null, #[SensitiveParameter] ?array $config = null): Payment
    {
        return Payment::make($provider, $config);
    }
}

if (! function_exists('sms')) {
    function sms(?string $provider = null, #[SensitiveParameter] ?array $config = null): Sms
    {
        return Sms::make($provider, $config);
    }
}

if (! function_exists('inquiry')) {
    function inquiry(?string $provider = null, #[SensitiveParameter] ?array $config = null): InquiryProvider
    {
        return InquiryProvider::make($provider, $config);
    }
}

if (! function_exists('exchange')) {
    function exchange(?string $provider = null, #[SensitiveParameter] ?array $config = null): ExchangeProvider
    {
        return ExchangeProvider::make($provider, $config);
    }
}
