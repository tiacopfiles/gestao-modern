<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = 'C:/xampp2/apps/gestao/storage/framework/maintenance.php')) {
    require $maintenance;
}

require 'C:/xampp2/apps/gestao/vendor/autoload.php';

/** @var Application $app */
$app = require_once 'C:/xampp2/apps/gestao/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
