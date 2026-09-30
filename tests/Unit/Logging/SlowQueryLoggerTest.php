<?php

namespace Tests\Unit\Logging;

use App\Logging\SlowQueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class SlowQueryLoggerTest extends TestCase
{
    public function test_it_records_a_slow_query_with_request_ip_and_query_values(): void
    {
        $this->app['request']->server->set('REMOTE_ADDR', '198.51.100.10');
        $handler = new TestHandler;
        $logger = new Logger('query', [$handler]);
        $queryLogger = new SlowQueryLogger($logger, 500);

        $queryLogger(new QueryExecuted(
            sql: 'select * from users where email = ? and id = ?',
            bindings: ['person@example.test', 42],
            time: 501.25,
            connection: $this->app['db']->connection(),
        ));

        $record = $handler->getRecords()[0];

        $this->assertSame('Slow database query', $record->message);
        $this->assertSame([
            'ip_address' => '198.51.100.10',
            'duration_ms' => 501.25,
            'sql' => "select * from users where email = 'person@example.test' and id = 42",
        ], $record->context);
    }

    public function test_it_records_all_normal_and_slow_queries(): void
    {
        $this->app['request']->server->set('REMOTE_ADDR', '198.51.100.11');
        $handler = new TestHandler;
        $logger = new Logger('query', [$handler]);
        $queryLogger = new SlowQueryLogger($logger, 500);
        $connection = $this->app['db']->connection();

        $queryLogger(new QueryExecuted('select 1', [], 499, $connection));
        $queryLogger(new QueryExecuted('select 2', [], 600, $connection));

        $this->assertCount(2, $handler->getRecords());
        $this->assertSame([
            'ip_address' => '198.51.100.11',
            'duration_ms' => 499.0,
            'sql' => 'select 1',
        ], $handler->getRecords()[0]->context);
        $this->assertSame('Database query', $handler->getRecords()[0]->message);
        $this->assertSame('Slow database query', $handler->getRecords()[1]->message);
    }
}
