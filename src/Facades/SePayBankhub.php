<?php

namespace SePay\SePayBankhub\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SePay\SePayBankhub\SePayBankhub
 */
class SePayBankhub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SePay\SePayBankhub\SePayBankhub::class;
    }
}
