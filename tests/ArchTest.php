<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('it does not ship provider-specific exception classes')
    ->expect('Larevio\PersianProviders\Exceptions')
    ->toImplement('Larevio\PersianProviders\Exceptions\PersianProvidersException')
    ->ignoring('Larevio\PersianProviders\Exceptions\PersianProvidersException');
