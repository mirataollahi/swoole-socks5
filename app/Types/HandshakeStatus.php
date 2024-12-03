<?php
/**
 * User: Mirataollahi ( @Mirataollahi124 )
 * Date: 12/3/24  Time: 11:24 AM
 */

namespace App\Types;

enum HandshakeStatus:int
{
    case NOT_STARTED = 2;
    case RUNNING = 4;
    case FINISHED = 8;
    case FAILED = 16;
}