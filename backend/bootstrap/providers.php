<?php

use App\Providers\AdapterServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;

return [
    AppServiceProvider::class,
    AdapterServiceProvider::class,
    AuthorizationServiceProvider::class,
];
