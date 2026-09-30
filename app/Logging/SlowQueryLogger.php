<?php

namespace App\Logging;

use Illuminate\Database\Events\QueryExecuted;
use Psr\Log\LoggerInterface;

class SlowQueryLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $slowQueryMilliseconds,
    ) {}

    /**
     * Record every query with the request IP, duration, and value-filled SQL.
     */
    public function __invoke(QueryExecuted $query): void
    {
        $isSlowQuery = $query->time >= $this->slowQueryMilliseconds;

        $this->logger->{$isSlowQuery ? 'warning' : 'info'}($isSlowQuery ? 'Slow database query' : 'Database query', [
            'ip_address' => request()->ip(),
            'duration_ms' => round($query->time, 2),
            'sql' => $query->toRawSql(),
        ]);
    }
}
