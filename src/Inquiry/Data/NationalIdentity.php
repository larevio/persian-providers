<?php

namespace Larevio\PersianProviders\Inquiry\Data;

readonly class NationalIdentity
{
    public function __construct(
        public string $nationalCode,
        public string $firstName,
        public string $lastName,
        public ?string $fatherName = null,
        public ?bool $alive = null,
    ) {}

}
