<?php

namespace Tests\Unit\Logging;

use App\Logging\RedactSensitiveLogData;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

class RedactSensitiveLogDataTest extends TestCase
{
    public function test_it_redacts_sensitive_context_and_messages(): void
    {
        $handler = new TestHandler;
        $logger = new LaravelLogger(new Logger('test', [$handler]));
        (new RedactSensitiveLogData)($logger);

        $logger->warning('Request failed for /callback?token=token-value using Bearer abc.def', [
            'password' => 'not-for-logs',
            'nested' => ['api_key' => 'also-not-for-logs'],
            'safe' => 'visible',
        ]);

        $record = $handler->getRecords()[0];

        $this->assertSame('Request failed for /callback?token=[REDACTED] using Bearer [REDACTED]', $record->message);
        $this->assertSame('[REDACTED]', $record->context['password']);
        $this->assertSame('[REDACTED]', $record->context['nested']['api_key']);
        $this->assertSame('visible', $record->context['safe']);
        $this->assertSame(JsonFormatter::class, config('logging.channels.single.formatter'));
        $this->assertTrue(config('logging.channels.single.formatter_with.includeStacktraces'));
    }

    public function test_it_redacts_exception_messages_before_json_formatting(): void
    {
        $handler = new TestHandler;
        $logger = new LaravelLogger(new Logger('test', [$handler]));
        (new RedactSensitiveLogData)($logger);

        $logger->error('Request failed', [
            'exception' => new RuntimeException('Callback failed with token=token-value'),
        ]);

        $exception = $handler->getRecords()[0]->context['exception'];

        $this->assertSame(RuntimeException::class, $exception['class']);
        $this->assertSame('Callback failed with token=[REDACTED]', $exception['message']);
        $this->assertArrayHasKey('trace', $exception);
    }
}
