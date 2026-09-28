<?php

namespace Tests\Unit\Logging;

use App\Logging\SlowQueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class SlowQueryLoggerTest extends TestCase
{
    public function test_it_records_a_slow_query_without_bindings_or_literals(): void
    {
        $handler = new TestHandler;
        $logger = new Logger('query', [$handler]);
        $queryLogger = new SlowQueryLogger($logger, 500, 1.0, 10);

        $queryLogger(new QueryExecuted(
            sql: "select * from users where email = 'person@example.test' and id = 42",
            bindings: ['person@example.test', 42],
            time: 501.25,
            connection: $this->app['db']->connection(),
        ));

        $record = $handler->getRecords()[0];

        $this->assertSame('Slow database query', $record->message);
        $this->assertSame('select * from users where email = ? and id = ?', $record->context['sql']);
        $this->assertSame(501.25, $record->context['duration_ms']);
        $this->assertArrayNotHasKey('bindings', $record->context);
    }

    public function test_it_ignores_fast_queries_and_caps_logs_per_process(): void
    {
        $handler = new TestHandler;
        $logger = new Logger('query', [$handler]);
        $queryLogger = new SlowQueryLogger($logger, 500, 1.0, 1);
        $connection = $this->app['db']->connection();

        $queryLogger(new QueryExecuted('select 1', [], 499, $connection));
        $queryLogger(new QueryExecuted('select 1', [], 500, $connection));
        $queryLogger(new QueryExecuted('select 2', [], 600, $connection));

        $this->assertCount(1, $handler->getRecords());
    }
}
