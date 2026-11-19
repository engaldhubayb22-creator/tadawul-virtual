<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Sends commands to the matching engine. The engine reads the "orders:in"
 * Redis stream in order and is the only writer of orders and trades.
 */
class OrderQueue
{
    public const STREAM = 'orders:in';

    public static function send(array $command): void
    {
        Redis::connection('engine')->xadd(self::STREAM, '*', [
            'data' => json_encode($command, JSON_THROW_ON_ERROR),
        ]);
    }
}
