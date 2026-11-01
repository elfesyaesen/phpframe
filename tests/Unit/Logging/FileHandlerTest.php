<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use ErrorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Logging\Handlers\FileHandler;
use System\Logging\LogLevel;
use System\Logging\LogRecord;

#[CoversClass(FileHandler::class)]
final class FileHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpframe-filehandler-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($this->dir);
    }

    public function testUnwritableTargetDoesNotThrowThroughErrorHandler(): void
    {
        $handler = new FileHandler($this->dir . '/app.log');

        mkdir($this->dir . '/app-' . date('Y-m-d') . '.log');

        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $previousLog = ini_set('error_log', $this->dir . '/fallback.txt');

        try {
            $handler->handle($this->record());
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
            ini_set('error_log', (string) $previousLog);
        }

        self::assertStringContainsString('yazilamayan kayit', (string) file_get_contents($this->dir . '/fallback.txt'));
    }

    private function record(): LogRecord
    {
        return new LogRecord(LogLevel::ERROR, 'yazilamayan kayit');
    }
}
