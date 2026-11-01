<?php

use Illuminate\Container\Container;

require dirname(__DIR__).'/vendor/autoload.php';

// Small container adapter for running the production components without a web app.
function app(?string $abstract = null): mixed
{
    $container = Container::getInstance();

    return $abstract === null ? $container : $container->make($abstract);
}
function config(array|string|null $key = null, mixed $default = null): mixed
{
    $repository = app('config');
    if (is_array($key)) {
        $repository->set($key);

        return null;
    }

    return $key === null ? $repository : $repository->get($key, $default);
}
