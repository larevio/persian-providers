<?php

namespace Larevio\PersianProviders\Exceptions;

use RuntimeException;

/** No response was received, so the provider may still have processed the request. Check before repeating it. */
class TransportException extends RuntimeException implements PersianProvidersException {}
