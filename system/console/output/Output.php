<?php

declare(strict_types=1);

namespace System\Console\Output;

class Output
{
    private bool $decorated;
    private Verbosity $verbosity;

    /** @var resource */
    private $stream;

    public function __construct(
        Verbosity $verbosity = Verbosity::NORMAL,
        ?bool $decorated = null,
        mixed $stream = null,
    ) {
        $this->stream = $stream ?? STDOUT;
        $this->verbosity = $verbosity;
        $this->decorated = $decorated ?? $this->supportsAnsi();
    }

    // ─────────────────────────────────────────────────────────────
    // Temel Yazma Metodları
    // ─────────────────────────────────────────────────────────────

    public function write(string $message, Verbosity $verbosity = Verbosity::NORMAL): self
    {
        if ($this->verbosity->value >= $verbosity->value) {
            fwrite($this->stream, $message);
        }
        return $this;
    }

    public function writeln(string $message = '', Verbosity $verbosity = Verbosity::NORMAL): self
    {
        return $this->write($message . PHP_EOL, $verbosity);
    }

    public function newLine(int $count = 1): self
    {
        return $this->write(str_repeat(PHP_EOL, $count));
    }

    // ─────────────────────────────────────────────────────────────
    // Styled Mesajlar
    // ─────────────────────────────────────────────────────────────

    public function success(string $message): self
    {
        return $this->block($message, 'SUCCESS', 'green', 'black');
    }

    public function error(string $message): self
    {
        return $this->writeln($this->format(' HATA ', 'white', 'red') . ' ' . $message);
    }

    public function warning(string $message): self
    {
        return $this->writeln($this->format(' UYARI ', 'black', 'yellow') . ' ' . $message);
    }

    public function info(string $message): self
    {
        return $this->writeln($this->format($message, 'cyan'));
    }

    public function comment(string $message): self
    {
        return $this->writeln($this->format($message, 'gray'));
    }

    public function note(string $message): self
    {
        return $this->writeln($this->format(' NOT ', 'yellow') . ' ' . $message);
    }

    public function caution(string $message): self
    {
        return $this->block($message, 'DİKKAT', 'black', 'yellow');
    }

    public function block(string $message, string $type, string $fg, string $bg): self
    {
        $this->newLine();
        $this->writeln($this->format(" [{$type}] {$message} ", $fg, $bg));
        return $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    // Format Helpers
    // ─────────────────────────────────────────────────────────────

    public function title(string $title): self
    {
        $this->newLine();
        $this->writeln($this->format($title, 'yellow'));
        $this->writeln($this->format(str_repeat('═', mb_strlen($title)), 'yellow'));
        return $this->newLine();
    }

    public function section(string $title): self
    {
        $this->newLine();
        $this->writeln($this->format($title, 'yellow'));
        $this->writeln($this->format(str_repeat('─', mb_strlen($title)), 'yellow'));
        return $this;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int>> $rows
     */
    public function table(array $headers, array $rows): self
    {
        $widths = [];

        // Kolon genişliklerini görünür (display) genişlikle hesapla:
        // ANSI renk kodları ve çok-baytlı (Türkçe) karakterler bayt sayısından
        // farklıdır; aksi halde renkli/Türkçe hücreler hizayı bozar.
        foreach ($headers as $i => $header) {
            $widths[$i] = $this->displayWidth((string) $header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, $this->displayWidth((string) $cell));
            }
        }

        // Header
        $this->writeln($this->tableRow($headers, $widths, 'cyan'));
        $this->writeln($this->tableSeparator($widths));

        // Rows
        foreach ($rows as $row) {
            $this->writeln($this->tableRow($row, $widths));
        }

        return $this;
    }

    /**
     * @param array<int, string|int> $cells
     * @param array<int, int> $widths
     */
    private function tableRow(array $cells, array $widths, ?string $color = null): string
    {
        $parts = [];
        foreach ($cells as $i => $cell) {
            $text = (string) $cell;
            // Görünür genişliğe göre sağdan boşlukla doldur (str_pad bayt sayar,
            // ANSI kodlu/Türkçe hücrelerde yanlış hizalar).
            $padding = str_repeat(' ', max(0, $widths[$i] - $this->displayWidth($text)));
            $parts[] = ($color ? $this->format($text, $color) : $text) . $padding;
        }
        return '  ' . implode('  │  ', $parts);
    }

    /**
     * ANSI renk kodlarını ve çok-baytlı karakterleri hesaba katan görünür genişlik.
     */
    private function displayWidth(string $text): int
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
        return mb_strlen($stripped);
    }

    /**
     * @param array<int, int> $widths
     */
    private function tableSeparator(array $widths): string
    {
        $parts = array_map(fn(int $w) => str_repeat('─', $w), $widths);
        return '  ' . implode('──┼──', $parts);
    }

    /**
     * @param array<int|string, string> $items
     */
    public function listing(array $items): self
    {
        foreach ($items as $key => $value) {
            $this->writeln(sprintf(
                '  %s %s',
                $this->format('•', 'yellow'),
                is_string($key) ? "$key: $value" : $value
            ));
        }
        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // Progress Bar
    // ─────────────────────────────────────────────────────────────

    private int $progressMax = 0;
    private int $progressCurrent = 0;
    private string $progressMessage = '';

    public function progressStart(int $max, string $message = ''): self
    {
        $this->progressMax = $max;
        $this->progressCurrent = 0;
        $this->progressMessage = $message;
        $this->renderProgress();
        return $this;
    }

    public function progressAdvance(int $step = 1): self
    {
        $this->progressCurrent = min($this->progressCurrent + $step, $this->progressMax);
        $this->renderProgress();
        return $this;
    }

    public function progressFinish(): self
    {
        $this->progressCurrent = $this->progressMax;
        $this->renderProgress();
        $this->newLine();
        return $this;
    }

    private function renderProgress(): void
    {
        $percent = $this->progressMax > 0
            ? (int) (($this->progressCurrent / $this->progressMax) * 100)
            : 0;

        $barWidth = 30;
        $filled = (int) (($percent / 100) * $barWidth);
        $bar = str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled);

        $output = sprintf(
            "\r  %s[%s] %3d%% (%d/%d)",
            $this->progressMessage ? $this->progressMessage . ' ' : '',
            $bar,
            $percent,
            $this->progressCurrent,
            $this->progressMax
        );

        fwrite($this->stream, $output);
    }

    // ─────────────────────────────────────────────────────────────
    // Inline Renk Metodları
    // ─────────────────────────────────────────────────────────────

    public function green(string $text): string
    {
        return $this->format($text, 'green');
    }

    public function red(string $text): string
    {
        return $this->format($text, 'red');
    }

    public function yellow(string $text): string
    {
        return $this->format($text, 'yellow');
    }

    public function blue(string $text): string
    {
        return $this->format($text, 'blue');
    }

    public function cyan(string $text): string
    {
        return $this->format($text, 'cyan');
    }

    public function magenta(string $text): string
    {
        return $this->format($text, 'magenta');
    }

    public function gray(string $text): string
    {
        return $this->format($text, 'gray');
    }

    public function white(string $text): string
    {
        return $this->format($text, 'white');
    }

    public function bold(string $text): string
    {
        return $this->decorated ? "\033[1m{$text}\033[0m" : $text;
    }

    public function dim(string $text): string
    {
        return $this->decorated ? "\033[2m{$text}\033[0m" : $text;
    }

    public function underline(string $text): string
    {
        return $this->decorated ? "\033[4m{$text}\033[0m" : $text;
    }

    // ─────────────────────────────────────────────────────────────
    // Format & Config
    // ─────────────────────────────────────────────────────────────

    public function format(string $text, string $foreground, ?string $background = null): string
    {
        if (!$this->decorated) {
            return $text;
        }

        $colors = [
            'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,
            'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
            'gray' => 90,
        ];

        $bgColors = [
            'black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43,
            'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47,
        ];

        $codes = [];

        if (isset($colors[$foreground])) {
            $codes[] = $colors[$foreground];
        }

        if ($background !== null && isset($bgColors[$background])) {
            $codes[] = $bgColors[$background];
        }

        if (empty($codes)) {
            return $text;
        }

        return sprintf("\033[%sm%s\033[0m", implode(';', $codes), $text);
    }

    private function supportsAnsi(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM_PROGRAM') === 'vscode'
                || (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT));
        }

        return defined('STDOUT') && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    // ─────────────────────────────────────────────────────────────
    // Getters & Setters
    // ─────────────────────────────────────────────────────────────

    public function setVerbosity(Verbosity $verbosity): self
    {
        $this->verbosity = $verbosity;
        return $this;
    }

    public function getVerbosity(): Verbosity
    {
        return $this->verbosity;
    }

    public function isQuiet(): bool
    {
        return $this->verbosity->isQuiet();
    }

    public function isVerbose(): bool
    {
        return $this->verbosity->isVerbose();
    }

    public function isVeryVerbose(): bool
    {
        return $this->verbosity->isVeryVerbose();
    }

    public function isDebug(): bool
    {
        return $this->verbosity->isDebug();
    }

    public function setDecorated(bool $decorated): self
    {
        $this->decorated = $decorated;
        return $this;
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }
}
