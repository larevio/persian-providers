<?php

namespace Larevio\PersianProviders\Exceptions;

use RuntimeException;

/** The provider confirmed a payment whose amount, order or reference differs from yours. Don't fulfil the order. */
class PaymentVerificationException extends RuntimeException implements PersianProvidersException {}
