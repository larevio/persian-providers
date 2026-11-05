<?php

namespace Larevio\PersianProviders\Exceptions;

use InvalidArgumentException;

/** A provider is unknown or a required setting is missing. */
class ConfigurationException extends InvalidArgumentException implements PersianProvidersException {}
