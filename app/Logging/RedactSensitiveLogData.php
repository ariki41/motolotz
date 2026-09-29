<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactSensitiveLogData
{
    /**
     * Values may originate from request payloads or third-party errors. Redact
     * them before they enter the shared log volume, not only at the log shipper.
     */
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: $this->redactString($record->message),
                context: $this->redact($record->context),
                extra: $this->redact($record->extra),
            );
        });
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactString($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(?:pass(?:word)?|pwd|cookie|token|authorization|api[._-]?key|secret)/i', $key);
    }

    private function redactString(string $value): string
    {
        $value = preg_replace(
            '/(?i)(password|passwd|pwd|cookie|token|authorization|api[_-]?key|secret)(=|%3d|"\s*:\s*")[^&\s",}]+/',
            '$1$2[REDACTED]',
            $value,
        ) ?? $value;

        return preg_replace('/(?i)bearer\s+[^\s",}]+/', 'Bearer [REDACTED]', $value) ?? $value;
    }
}
