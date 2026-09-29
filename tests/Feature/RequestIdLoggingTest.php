<?php

namespace Tests\Feature;

use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use RuntimeException;
use Tests\TestCase;

class RequestIdLoggingTest extends TestCase
{
    public function test_it_reuses_the_nginx_request_id_for_logs_and_the_response(): void
    {
        Log::spy();

        $this->withHeader('X-Request-ID', '0123456789abcdef0123456789abcdef')
            ->get('/up')
            ->assertOk()
            ->assertHeader('X-Request-ID', '0123456789abcdef0123456789abcdef');

        Log::shouldHaveReceived('shareContext')->with([
            'environment' => app()->environment(),
            'request_id' => '0123456789abcdef0123456789abcdef',
        ]);
    }

    public function test_it_replaces_an_unsafe_request_id(): void
    {
        $requestId = $this->withHeader('X-Request-ID', "unsafe\nvalue")
            ->get('/up')
            ->assertOk()
            ->headers->get('X-Request-ID');

        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[47][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $requestId,
        );
    }

    public function test_exception_reports_keep_the_request_id_context(): void
    {
        $handler = new TestHandler;

        Log::extend(
            'request-id-test-driver',
            fn () => new LaravelLogger(new MonologLogger('request-id-test', [$handler])),
        );
        config([
            'app.debug' => false,
            'logging.default' => 'request-id-test',
            'logging.channels.request-id-test' => ['driver' => 'request-id-test-driver'],
        ]);
        Log::forgetChannel('request-id-test');

        Route::get('/_test/request-id-exception', function (): never {
            throw new RuntimeException('Expected request ID test exception.');
        });

        $this->withHeader('X-Request-ID', 'exception-request-id')
            ->get('/_test/request-id-exception')
            ->assertInternalServerError();

        $record = collect($handler->getRecords())
            ->first(fn ($record) => $record->message === 'Expected request ID test exception.');

        $this->assertNotNull($record);
        $this->assertSame('exception-request-id', $record->context['request_id']);
        $this->assertSame(app()->environment(), $record->context['environment']);
    }
}
