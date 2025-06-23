<?php

use Apiato\Core\Repositories\Exceptions\ResourceCreationFailed;
use Apiato\Core\Repositories\Exceptions\ResourceNotFound;

arch()->preset()->php();

arch('src')
    ->expect('Apiato')
    ->toUseStrictEquality()
    ->ignoring([
        'Apiato\\Repository\\Criteria\\RequestCriteria', // Ignore strict equality for this class
    ])
    ->not->toUse('sleep')
    ->not->toUse('usleep')
    ->ignoring([
        'Apiato\\Repository\\Traits\\TransactionalRepository',
    ]);

arch('src - final classes')
    ->expect('Apiato')
    ->classes()->toBeFinal()
    ->ignoring([
        'Apiato\Core',
        'Apiato\Generator',
        'Apiato\Support\Facades',
        'Apiato\Repository',
        Apiato\Http\Response::class,

    ]);

arch('src/abstract')
    ->expect('Apiato\Core')
    ->classes()->toBeAbstract()->ignoring([
        ResourceCreationFailed::class,
        ResourceNotFound::class,
    ]);

arch('tests')
    ->expect('Workbench\App')
    ->toOnlyBeUsedIn('Tests');
