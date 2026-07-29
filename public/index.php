<?php

declare(strict_types=1);

use Liminal\Http\ResponseEmitter;
use Liminal\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$kernel = new Kernel(dirname(__DIR__));

(new ResponseEmitter())->emit($kernel->handle($request));
