# Contributing

## Local setup

Use PHP 8.2 or later.

```bash
composer install
composer prepare
composer check
```

`composer check` runs Pest, PHPStan, Pint and `composer audit`. Use `composer test -- --filter=Payment` for a focused run and `composer format` to apply formatting.

## Adding or changing a provider

1. **Start from the provider's official documentation** and link it from the README table. If no documentation is available, say so in the README and in the class docblock. Do not invent endpoints, fields or error codes.
2. **Extend the domain base class** (`PaymentProvider`, `SmsProvider`, `InquiryProvider`, `ExchangeProvider`). Set `$name` to the config key and `$title` to the name used in messages, then register the key in the `make()` factory and in `config/persianproviders.php`.
3. **Declare the money unit.** Payment providers set `protected Currency $currency` to the unit the API uses. Public methods take rials, and the adapter converts with `providerAmount()`. Never use floats for money.
4. **Read credentials with `credential()`**, so a missing value fails before sending. Add any secret config key to `Provider::SECRET_KEYS` so it is redacted from messages.
5. **Use the shared error helpers.** Throw `$this->rejected($response, $code, $message)` when the provider refuses, `InvalidResponseException` for malformed answers, and `PaymentVerificationException` (through `assertAmount()`/`assertOrder()`) when a confirmed payment differs from the request. Put documented code-only messages in `$errors`.
6. **Never add automatic retries.** A timeout can follow an accepted payment, withdrawal or SMS.
7. **Verify with server-side values.** Never let `extra()` or callback data override the amount, order or credentials sent to the provider.

## Tests

Use `Http::preventStrayRequests()` and `Http::fake()`. Fixtures must reflect documented or observed provider responses. Cover the success path, the documented failure codes, malformed responses, and the amount unit sent on the wire. Tests must never need credentials or the network.

When public behaviour changes, update the README.
