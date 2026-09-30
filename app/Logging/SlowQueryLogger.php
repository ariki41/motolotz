<?php

namespace App\Logging;

use Illuminate\Database\Events\QueryExecuted;
use Psr\Log\LoggerInterface;

class SlowQueryLogger
{
    private int $loggedQueries = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $slowQueryMilliseconds,
        private readonly float $sampleRate,
        private readonly int $maxQueries,
    ) {}

    /**
     * Record sampled queries, distinguishing normal and slow queries. Bindings
     * are included to support query-level debugging.
     */
    public function __invoke(QueryExecuted $query): void
    {
        if ($this->loggedQueries >= $this->maxQueries
            || mt_rand() / mt_getrandmax() > $this->sampleRate) {
            return;
        }

        $this->loggedQueries++;

        $isSlowQuery = $query->time >= $this->slowQueryMilliseconds;

        $this->logger->{$isSlowQuery ? 'warning' : 'info'}($isSlowQuery ? 'Slow database query' : 'Database query', [
            'connection' => $query->connectionName,
            'duration_ms' => round($query->time, 2),
            'sql' => $this->redactLiterals($query->sql),
            'bindings' => $query->bindings,
        ]);
    }

    private function redactLiterals(string $sql): string
    {
        $sql = preg_replace("/(?<![[:alnum:]_])(?:x'[^']*'|0x[0-9a-f]+)/i", '?', $sql) ?? $sql;
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql) ?? $sql;

        return preg_replace('/(?<![[:alnum:]_])[-+]?\\d+(?:\\.\\d+)?(?![[:alnum:]_])/', '?', $sql) ?? $sql;
    }
}
