<?php

namespace App\Types;

interface SafeDestructInterface
{
    /**
     * Clean up running coroutine and timers before destruct
     * @return void
     */
    public function close(): void;
}