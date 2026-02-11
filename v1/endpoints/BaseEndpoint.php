<?php
namespace GarageMinder\API\Endpoints;

use GarageMinder\API\Core\Request;

abstract class BaseEndpoint
{
    abstract public function handle(Request $request): void;
}
