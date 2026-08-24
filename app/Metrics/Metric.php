<?php declare(strict_types=1);

namespace App\Metrics;


enum Metric: string
{
    case COROUTINE_NUM = 'coroutine_num';
    case CONNECTION_COUNT = 'connection_count';
    case REQUEST_COUNT = 'request_count';
    case RESPONSE_COUNT = 'response_count';
    case RAM_USAGE = 'ram_usage';
    case CPU_USAGE = 'cpu_usage';
    case ACCEPT_CLIENT_COUNT = 'accept_client_count';
    case ABORT_CLIENT_COUNT = 'abort_client_count';
    case CLOSE_CLIENT_COUNT = 'close_client_count';


    /**
     * Helper method to get all metric names as a simple array of strings.
     */
    public static function values(): array
    {
        return array_map(fn(Metric $m) => $m->value, self::cases());
    }
}