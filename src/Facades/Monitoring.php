<?php

namespace Abigah\BotCopTrafficDivision\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Abigah\BotCopTrafficDivision\Monitoring
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abigah\BotCopTrafficDivision\Monitoring::class;
    }
}
