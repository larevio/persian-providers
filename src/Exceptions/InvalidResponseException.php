<?php

namespace Larevio\PersianProviders\Exceptions;

use UnexpectedValueException;

/** The provider answered, but the body is malformed or missing a required field. */
class InvalidResponseException extends UnexpectedValueException implements PersianProvidersException {}
