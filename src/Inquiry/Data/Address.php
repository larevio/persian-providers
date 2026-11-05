<?php

namespace Larevio\PersianProviders\Inquiry\Data;

readonly class Address
{
    public function __construct(
        public string $postalCode,
        public ?string $province,
        public ?string $city,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $district = null,
        public ?string $buildingName = null,
        public string|int|null $floor = null,
        public ?string $extra = null,
    ) {}
}
