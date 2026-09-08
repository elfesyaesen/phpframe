<?php

declare(strict_types=1);

namespace System\Logging\Handlers;

use System\Logging\Contracts\FormatterInterface;
use System\Logging\Contracts\HandlerInterface;
use System\Logging\Formatters\JsonFormatter;
use System\Logging\LogLevel;
use System\Logging\LogRecord;
use RuntimeException;

/**
 * Period bazli rotation (daily / weekly / monthly) + size overflow + retention cleanup.
 *
 * Dosya formati period'a gore:
 *   - daily:   {basename}-{YYYY-MM-DD}.{ext}     (ornek: app-2026-05-12.log)
 *   - weekly:  {basename}-{YYYY-Www}.{ext}       (ornek: app-2026-W19.log, ISO 8601)
 *   - monthly: {basename}-{YYYY-MM}.{ext}        (ornek: app-2026-05.log)
 *
 * Ayni period icinde boyut limiti asilirsa overflow dosyasi acilir:
 *   - {basename}-{period-suffix}_{HHMMSS}.{ext}
 *
 * Retention: aktif period regex'ine uyan, $retention birimden daha eski dosyalar silinir.
 * Farkli period formatindaki dosyalara dokunulmaz (defansif — period degisiminde
 * eski dosyalar manuel temizlenmeli).
 */
final class FileHandler implements HandlerInterface
{
    public const PERIOD_DAILY   = 'daily';
    public const PERIOD_WEEKLY  = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    /**
     * Period -> [date_format, regex, strtotime_modifier_unit]
     *
     * @var array<string,array{format:string,regex:string,unit:string}>
     */
    private const PERIODS = [
        self::PERIOD_DAILY   => ['format' => 'Y-m-d',  'regex' => '\d{4}-\d{2}-\d{2}', 'unit' => 'days'],
        self::PERIOD_WEEKLY  => ['format' => 'o-\WW',  'regex' => '\d{4}-W\d{2}',     'unit' => 'weeks'],
        self::PERIOD_MONTHLY => ['format' => 'Y-m',    'regex' => '\d{4}-\d{2}',      'unit' => 'months'],
    ];

    private readonly FormatterInterface $formatter;
    private readonly int $maxFileSize;
    private readonly int $retention;
    private readonly string $period;
    private readonly string $directory;
    private readonly string $basename;
    private readonly string $extension;
    private int $cleanupCounter = 0;

    public function __construct(
        private readonly string $path,
        private readonly LogLevel $minLevel = LogLevel::DEBUG,
        ?FormatterInterface $formatter = null,
        int $maxFileSizeMB = 10,
        int $retention = 15,
        string $period = self::PERIOD_DAILY
    ) {
        if (!isset(self::PERIODS[$period])) {
            throw new RuntimeException(
                "Gecersiz log rotation period: '{$period}'. Desteklenen: daily, weekly, monthly."
            );
        }

        $this->formatter   = $formatter ?? new JsonFormatter();
        $this->maxFileSize = max(1, $maxFileSizeMB) * 1024 * 1024;
        $this->retention   = max(1, $retention);
        $this->period      = $period;

        $info = pathinfo($path);
        $this->directory = $info['dirname'];
        $this->basename  = $info['filename'];
        $this->extension = $info['extension'] ?? 'log';

        $this->ensureDirectoryExists();
    }

    public function handle(LogRecord $record): void
    {
        if (!$this->isHandling($record->level)) {
            return;
        }

        $target = $this->resolveTargetFile();
        $formatted = $this->formatter->format($record);
        file_put_contents($target, $formatted, FILE_APPEND | LOCK_EX);

        // Cleanup overhead'i azaltmak icin her 100. yazimda calistir
        if (($this->cleanupCounter++ % 100) === 0) {
            $this->cleanupExpired();
        }
    }

    public function isHandling(LogLevel $level): bool
    {
        return $this->minLevel->includes($level);
    }

    /**
     * Aktif period icin dosya yolunu dondurur.
     * Boyut limiti asildiysa overflow dosyasina yonlendirir.
     */
    private function resolveTargetFile(): string
    {
        $suffix = date(self::PERIODS[$this->period]['format']);
        $primary = sprintf('%s/%s-%s.%s', $this->directory, $this->basename, $suffix, $this->extension);

        if (!file_exists($primary) || filesize($primary) < $this->maxFileSize) {
            return $primary;
        }

        // Ayni period icinde overflow dosyalari: {basename}-{suffix}_HHMMSS.{ext}
        $pattern  = sprintf('%s/%s-%s_*.%s', $this->directory, $this->basename, $suffix, $this->extension);
        $overflow = glob($pattern) ?: [];
        if (!empty($overflow)) {
            rsort($overflow);
            $latest = $overflow[0];
            if (filesize($latest) < $this->maxFileSize) {
                return $latest;
            }
        }

        return sprintf(
            '%s/%s-%s_%s.%s',
            $this->directory,
            $this->basename,
            $suffix,
            date('His'),
            $this->extension
        );
    }

    /**
     * Aktif period regex'ine uyan, $retention birimden eski dosyalari siler.
     * Period degisiminde eski formattaki dosyalar dokunulmaz.
     */
    private function cleanupExpired(): void
    {
        $unit   = self::PERIODS[$this->period]['unit'];
        $cutoff = strtotime(sprintf('-%d %s', $this->retention, $unit));
        if ($cutoff === false) {
            return;
        }

        $pattern = sprintf('%s/%s-*.%s', $this->directory, $this->basename, $this->extension);
        $files   = glob($pattern) ?: [];

        $periodRegex = self::PERIODS[$this->period]['regex'];
        // Dosya tabani: app-2026-05-12 veya app-2026-05-12_153045
        $fileRegex = '/-(' . $periodRegex . ')(?:_\d{6})?$/';

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match($fileRegex, $name, $m)) {
                continue; // farkli period formatinda — dokunma
            }

            $fileDate = $this->parsePeriodToTimestamp($m[1]);
            if ($fileDate !== null && $fileDate < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Period string'ini timestamp'e cevirir.
     * "2026-05-12" -> daily, "2026-W19" -> weekly, "2026-05" -> monthly.
     */
    private function parsePeriodToTimestamp(string $periodStr): ?int
    {
        if ($this->period === self::PERIOD_WEEKLY) {
            // "2026-W19" -> ISO hafta basi tarihi
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $periodStr, $m)) {
                return null;
            }
            // ISO 8601: yil-W-hafta-1 (Pazartesi)
            $ts = strtotime(sprintf('%dW%02d1', (int) $m[1], (int) $m[2]));
            return $ts !== false ? $ts : null;
        }

        // daily ve monthly icin strtotime dogrudan parse eder
        $ts = strtotime($periodStr);
        return $ts !== false ? $ts : null;
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
                throw new RuntimeException("Log dizini olusturulamadi: {$this->directory}");
            }
        }
    }
}
