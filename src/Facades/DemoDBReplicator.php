<?php

namespace Ilbronza\DemoDBReplicator\Facades;

use Illuminate\Support\Facades\Facade;

class DemoDBReplicator extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'demodbreplicator';
    }
}
