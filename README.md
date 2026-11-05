<h1 align="center">Persian Providers</h1>

<p align="center">Iranian payment gateways, SMS services, identity inquiries and crypto exchanges for Laravel.</p>

<p align="center">
  <a href="#installation">Installation</a> ·
  <a href="#amounts">Amounts</a> ·
  <a href="#payments">Payments</a> ·
  <a href="#sms">SMS</a> ·
  <a href="#inquiries">Inquiries</a> ·
  <a href="#exchanges">Exchanges</a> ·
  <a href="#errors">Errors</a>
</p>

Persian Providers supports 25 Iranian services through four helpers: `payment()`, `sms()`, `inquiry()` and `exchange()`.

## Installation

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

```bash
composer require larevio/persian-providers
php artisan vendor:publish --tag="persianproviders-config"
```

The service provider is discovered automatically. Credentials are read from environment variables, listed in [the configuration file](config/persianproviders.php):

```dotenv
PERSIAN_PROVIDERS_PAYMENT=zarinpal
ZARINPAL_TOKEN=your-merchant-id

PERSIAN_PROVIDERS_SMS=smsir
SMSIR_TOKEN=your-api-key
SMSIR_LINE=your-sender-line

PERSIAN_PROVIDERS_INQUIRY=zohal
ZOHAL_TOKEN=your-token

PERSIAN_PROVIDERS_EXCHANGE=nobitex
NOBITEX_TOKEN=your-token
```

You can also pass settings for a single call. They are merged over the configured values:

```php
payment('zarinpal', ['token' => $merchantId]);
sms('smsir', ['token' => $apiKey, 'line' => $senderLine]);
inquiry('zohal', ['token' => $token]);
exchange('nobitex', ['token' => $token]);
```

### Common settings

| Setting | Default | Scope |
| --- | --- | --- |
| `timeout` | 15 seconds (`PERSIAN_PROVIDERS_TIMEOUT`) | Global or per provider |
| `connect_timeout` | 5 seconds (`PERSIAN_PROVIDERS_CONNECT_TIMEOUT`) | Global or per provider |
| `proxy` | `PERSIAN_PROVIDERS_PROXY` | Global or per provider. Set `false` to turn it off for one provider |
| `sandbox` | `false` | Zarinpal, IDPay and AqayePardakht |

## Amounts

Payment amounts are in rials. The fluent builder takes the unit along with the amount:

```php
use Larevio\PersianProviders\Enums\Currency;

payment()->amount(150_000, Currency::IRT);   // 150,000 tomans = 1,500,000 rials
payment()->amount(1_500_000, Currency::IRR); // same amount
```

Gateways that work in tomans round down to whole tomans, so 12,505 rials is charged as 1,250 tomans.

| Unit sent to the provider | Gateways |
| --- | --- |
| Rials | Zarinpal (with `currency=IRR`), Zibal, IDPay, SEP, Sepehr, PayStar, Vandar, Pasargad, Sadad, Damavand |
| Tomans | NextPay (with `currency=IRT`), AqayePardakht, PanaPal, PayPing |

Amounts in `VerifiedPayment` and `ReversedPayment` are in rials.

## Payments

| Gateway | Key | Create | Verify | Reverse | Docs |
| --- | --- | :---: | :---: | :---: | --- |
| <img src="docs/assets/providers/zarinpal.png" height="20" alt=""> Zarinpal | `zarinpal` | ✅ | ✅ | ✅ | [Docs](https://www.zarinpal.com/docs/paymentGateway/connectToGateway) |
| <img src="docs/assets/providers/zibal.png" height="20" alt=""> Zibal | `zibal` | ✅ | ✅ | — | [Docs](https://help.zibal.ir/ipg/) |
| <img src="docs/assets/providers/idpay.png" height="20" alt=""> IDPay | `idpay` | ✅ | ✅ | — | [Docs (archived)](https://web.archive.org/web/20260219014036/https://idpay.ir/web-service/v1.1/) |
| <img src="docs/assets/providers/sep.png" height="20" alt=""> SEP (Saman) | `sep` | ✅ | ✅ | ✅ | [Docs v3.6](https://sep.ir/uploads/1/2026/May/11/IPG_Doc.zip) |
| <img src="docs/assets/providers/sepehr.png" height="20" alt=""> Sepehr | `sepehr` | ✅ | ✅ | ✅ | Request from provider |
| <img src="docs/assets/providers/nextpay.png" height="20" alt=""> NextPay | `nextpay` | ✅ | ✅ | ✅ | [Docs](https://nextpay.org/nx/docs) |
| <img src="docs/assets/providers/aqayepardakht.png" height="20" alt=""> AqayePardakht | `aqayepardakht` | ✅ | ✅ | — | [Docs](https://aqayepardakht.ir/api/) |
| <img src="docs/assets/providers/panapal.png" height="20" alt=""> PanaPal | `panapal` | ✅ | ✅ | — | [Docs (archived)](https://web.archive.org/web/20260218193749/https://paanapardakht.com/public/document) |
| <img src="docs/assets/providers/payping.png" height="20" alt=""> PayPing | `payping` | ✅ | ✅ | ✅ | [OpenAPI](https://statics.payping.ir/openapi.json) |
| <img src="docs/assets/providers/paystar.png" height="20" alt=""> PayStar | `paystar` | ✅ | ✅ | — | [Docs](https://docs.paystar.ir/docs/gateway/) |
| <img src="docs/assets/providers/vandar.svg" height="20" alt=""> Vandar | `vandar` | ✅ | ✅ | — | [Docs](https://vandarpay.github.io/docs/ipg/) |
| <img src="docs/assets/providers/pasargad.svg" height="20" alt=""> Pasargad | `pasargad` | ✅ | ✅ | ✅ | [Docs](https://github.com/pep-ipg/Dorsa-IPG-pasargad/blob/main/docs/api-reference.md) |
| <img src="docs/assets/providers/sadad.png" height="20" alt=""> | `sadad` | ✅ | ✅ | — | [Sample code](https://github.com/SadadPsp-Lab/PHP) |
| <img src="docs/assets/providers/damavand.png" height="20" alt=""> Damavand | `damavand` | ✅ | ✅ | ✅ | [Docs](https://github.com/ecdco/docs/blob/master/IPG.md) |

### 1. Create the payment and redirect

```php
use Larevio\PersianProviders\Enums\Currency;

$pending = payment('zibal')
    ->amount($order->total_tomans, Currency::IRT)
    ->order($order->id)
    ->description("Order #{$order->id}")
    ->mobile($customer->mobile)
    ->callback(route('payment.callback'))
    ->create();

// Save these before redirecting. Verification uses your stored values, not the callback's.
$order->update(['payment_id' => $pending->id, 'gateway' => 'zibal']);

$pending->redirect(); // Auto-submitting form for POST gateways, otherwise a 302.
```

To build the redirect yourself, for example in an SPA, `$pending->toArray()` returns the method, URL and form fields.

### 2. Verify on the callback

```php
$verified = payment($order->gateway)
    ->id($order->payment_id)
    ->amount($order->total_tomans, Currency::IRT)
    ->order($order->id)
    ->extra($request->all())
    ->verify();

if ($verified === false) {
    // The callback says the payment failed or was cancelled. No request was sent.
}

DB::transaction(function () use ($order, $verified) {
    // Fulfil the order once. Verifying again returns $verified->alreadyVerified === true.
});
```

Pass the callback request to `extra()`. Callback data can't change the amount, order or credentials used for verification.

<details>
<summary><strong>Gateway-specific requirements</strong></summary>

| Gateway | Order ID | `extra()` | Notes |
| --- | --- | --- | --- |
| IDPay | Create, verify | — | — |
| SEP | Create, verify | Verify: `RefNum` | Reject a `RefNum` you have already used. Set `use_get_method` for GET redirects and `use_neo_ipg` for Neo-PG terminals |
| Sepehr | Create, verify | Verify and reverse: `digitalreceipt` | Reject a `digitalreceipt` you have already used. Reversal must be enabled by Sepehr for your terminal |
| NextPay | Create | — | — |
| PayStar | Create | Verify: `card_number`, `tracking_code` | — |
| Pasargad | Create, verify | Create: Jalali `invoiceDate` | Can be reversed after `verify()` until your contract's window closes. To settle it right away, call `PaymentProvider::make('pasargad')->confirm($id, $rials, $orderId)` |
| Sadad | Create, verify | — | — |
| Damavand | Create, verify | Verify: `Amount` | — |
| PayPing | — | Create: `isReversible => true` to allow reversal<br>Verify: `paymentRefId` from the callback's JSON `data` field | If verify throws `ProviderException` with HTTP 202 or 502, call it again |

</details>

### Reversal

```php
if ($payment->supportsReverse()) {
    $reversed = $payment->reverse(); // Reverses the full amount.
}
```

Zarinpal, PayPing and Sepehr allow reversal for 30 minutes and NextPay for 20. SEP, Pasargad and Damavand windows depend on the provider or your contract. Zarinpal also requires your server IP to be registered.

## SMS

| Provider | Key | Send / Bulk | Pattern | Batch | Schedule | Balance | Docs |
| --- | --- | :---: | :---: | :---: | :---: | :---: | --- |
| <img src="docs/assets/providers/kavenegar.png" height="20" alt=""> Kavenegar | `kavenegar` | ✅ | ✅ | ✅ | ✅ | ✅ | [Docs](https://kavenegar.com/rest.html) |
| <img src="docs/assets/providers/smsir.png" height="20" alt=""> SMS.ir | `smsir` | ✅ | ✅ | ✅ | ✅ | ✅ | [Docs](https://sms.ir/rest-api/) |
| <img src="docs/assets/providers/ghasedak.png" height="20" alt=""> Ghasedak | `ghasedak` | ✅ | ✅ | ✅ | ✅ | ✅ | [Docs](https://ghasedak.me/docs) |
| <img src="docs/assets/providers/sabanovin.png" height="20" alt=""> Sabanovin | `sabanovin` | ✅ | ✅ | ✅ | ✅ | ✅ | [Docs](https://sabanovin.com/documentation) |
| <img src="docs/assets/providers/melipayamak.png" height="20" alt=""> MeliPayamak | `melipayamak` | ✅ | ✅ | — | — | ✅ | [REST client](https://github.com/Melipayamak/melipayamak-php) |
| <img src="docs/assets/providers/farapayamak.png" height="20" alt=""> FaraPayamak | `farapayamak` | ✅ | ✅ | — | — | ✅ | [REST client](https://github.com/Farapayamak/PHP) |

```php
sms('smsir')->to('09120000000')->message('Your order is ready.')->send();

sms('kavenegar')
    ->to('09120000000')
    ->templateId('verify')
    ->sendPattern(['token' => '1234']);

$submission = sms('ghasedak')->sendBatch([
    ['phone' => '09120000000', 'text' => 'Your order is ready.'],
    ['phone' => '09120000001', 'text' => 'Your appointment is tomorrow.'],
]);

$scheduled = sms('kavenegar')
    ->to(['09120000000', '09120000001'])
    ->message('Appointment reminder')
    ->schedule(now()->addHour());

$balance = sms('smsir')->balance();
```

- Messages go out from the configured `line` unless you pass another one. SMS.ir, Sabanovin, MeliPayamak and FaraPayamak need a line.
- Pattern variables are named for Sabanovin, SMS.ir and Ghasedak, positional for MeliPayamak and FaraPayamak, and `token`, `token2`, `token3`, `token10` and `token20` for Kavenegar.
- Use `supportsBatch()`, `supportsSchedule()` and `supportsBalance()` to check what a provider can do.
- `header($text)` and `footer($text)` add a line before or after the message. `footer()` defaults to the opt-out text `لغو 11`.
- Sabanovin scheduling needs the `intl` extension.

## Inquiries

| Provider | Key | Mobile | IBAN | Card | Owner name | Card lookup | IBAN lookup | Identity | Postal code | Docs |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | --- |
| <img src="docs/assets/providers/zohal.svg" height="20" alt=""> Zohal | `zohal` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | [OpenAPI](https://service.zohal.io/api/v0/services/openapi/) |
| <img src="docs/assets/providers/jibit.png" height="20" alt=""> Jibit | `jibit` | ✅ | ✅ | — | — | — | ✅ | — | — | [OpenAPI](https://developers.jibit.ir/) |

| Column | Methods |
| --- | --- |
| Mobile | `matchMobile($mobile, $nationalCode)` |
| IBAN | `matchIban($iban, $nationalCode, $birthday)` |
| Card | `matchCard($card, $nationalCode, $birthday)` |
| Owner name | `matchCardOwner($card, $name)`, `matchIbanOwner($iban, $name)` |
| Card lookup | `cardToIban($card)`, `cardToAccount($card)`, `cardOwnerInquiry($card)` |
| IBAN lookup | `ibanInquiry($iban)` |
| Identity | `nationalIdentityInquiry($nationalCode, $birthday)` |
| Postal code | `postalCodeInquiry($postalCode)` |

```php
$matched = inquiry('zohal')->matchMobile($mobile, $nationalCode);
$account = inquiry('zohal')->ibanInquiry($iban);
```

The match methods return `false` when the details don't match. If the provider can't answer, for example because the card is unknown, `ProviderException` is thrown with the provider's code, such as `CARD_NOT_FOUND`.

`BankAccount::bank()` and `VerifiedPayment::bank()` return a `Bank` enum, detected from the IBAN or card number. It has `fa()` for the Persian name and `color()` for a brand color. Bank icons are published with `php artisan vendor:publish --tag="persianproviders-assets"` to `public/vendor/persianproviders/icons/{bank}.png`, where `{bank}` is the lowercase case name, for example `mellat.png`.

## Exchanges

| Exchange | Key | Rates | Deposit address | Profile | Orders | Crypto withdrawal | Rial withdrawal | Docs |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | --- |
| <img src="docs/assets/providers/nobitex.png" height="20" alt=""> Nobitex | `nobitex` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | [Docs](https://apidocs.nobitex.ir/) |
| <img src="docs/assets/providers/bitpin.png" height="20" alt=""> Bitpin | `bitpin` | ✅ | ✅ | — | — | — | — | [Docs](https://docs.bitpin.ir/v1/docs) |
| <img src="docs/assets/providers/wallex.png" height="20" alt=""> Wallex | `wallex` | ✅ | ✅ | ✅ | ✅ | — | ✅ | [Docs](https://developers.wallex.ir/docs) |

| Column | Methods |
| --- | --- |
| Rates | `getRate($from, $to)` |
| Deposit address | `getWalletAddress($currency, $network)`, `getNetworks($currency)` (Bitpin) |
| Profile | `getProfile()`, `getBankAccounts()` |
| Orders | `order($side, $from, $to, $amount, $price)` |
| Crypto withdrawal | `withdraw(...)`, `confirmWithdrawal($id, $otp)` |
| Rial withdrawal | `withdrawFiat($accountId, $rials)` |

```php
use Larevio\PersianProviders\Enums\Currency;

$rate = exchange('nobitex')->getRate(Currency::BTC, Currency::IRT);
$rate->last;     // "21513079017.5" tomans per BTC
$rate->bestSell; // lowest ask
$rate->bestBuy;  // highest bid
```

- Rates are decimal strings, not floats.
- `order()` and `withdraw()` take decimal strings or integers in the exchange's market units.
- `withdrawFiat()` takes rials.
- Bitpin and Wallex need the deposit network.
- A Nobitex crypto withdrawal takes two calls: `withdraw()`, then `confirmWithdrawal()` with the code sent by SMS. Pass a TOTP code as `extra: ['totp' => ...]`.

## Errors

The package's exceptions implement `Larevio\PersianProviders\Exceptions\PersianProvidersException`. It also throws the standard `InvalidArgumentException` for bad input and `BadMethodCallException` for operations a provider doesn't support.

| Exception | Meaning | Retry? |
| --- | --- | --- |
| `ConfigurationException` | Unknown provider, or a missing or invalid credential | No. Fix the configuration |
| `InvalidArgumentException` | Your input was rejected before sending | No |
| `TransportException` (`TimeoutException`) | No HTTP response was received | Only after checking the outcome. The provider may have processed the request |
| `ProviderException` (`AuthenticationException` for HTTP 401/403) | The provider rejected the request. `$provider`, `$providerCode` and `$httpStatus` are set | Depends on the code |
| `InvalidResponseException` | The response was malformed or missing a required field | Only after checking the outcome |
| `PaymentVerificationException` | The provider confirmed a payment whose amount, order, reference or terminal doesn't match yours | No. Don't fulfil the order |

```php
use Larevio\PersianProviders\Exceptions\PaymentVerificationException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Exceptions\TransportException;

try {
    $verified = $payment->verify();
} catch (PaymentVerificationException $e) {
    report($e);                 // Mismatch: investigate, and reverse the payment if needed.
} catch (TransportException $e) {
    // Outcome unknown. Call verify again; if it went through, alreadyVerified is true.
} catch (ProviderException $e) {
    $e->providerCode;           // e.g. -51 from Zarinpal
}
```

## Security

- Verify each payment on your server with the amount and order ID you stored. Don't trust callback data.
- Store the provider reference and check it before fulfilling, so an order can't be fulfilled twice.
- After a `TransportException`, check the outcome before retrying a payment, withdrawal or SMS.
- To report a vulnerability, follow [SECURITY.md](SECURITY.md).

## Testing

Fake provider requests with Laravel's HTTP client:

```php
Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'trackId' => 123])]);
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE.md). Provider names and logos belong to their owners.
