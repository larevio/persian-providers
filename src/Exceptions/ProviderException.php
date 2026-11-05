<?php

namespace Larevio\PersianProviders\Exceptions;

use RuntimeException;

/** The provider received the request and rejected it. */
class ProviderException extends RuntimeException implements PersianProvidersException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly int|string|null $providerCode = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, is_numeric($providerCode) ? (int) $providerCode : 0);
    }
}
