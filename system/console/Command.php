<?php

declare(strict_types=1);

namespace System\Console;

use System\Console\Input\Input;
use System\Console\Input\InputArgument;
use System\Console\Input\InputDefinition;
use System\Console\Input\InputOption;
use System\Console\Output\Output;
use System\Console\Output\Verbosity;

/**
 * Base Command class - tüm komutlar bu sınıftan türetilir.
 */
abstract class Command
{
    protected InputDefinition $definition;
    protected Input $input;
    protected Output $output;

    private string $name = '';
    private string $description = '';
    private string $help = '';

    /** @var array<string> */
    private array $aliases = [];

    private bool $hidden = false;

    public function __construct()
    {
        $this->definition = new InputDefinition();
        $this->configure();
    }

    /**
     * Komut konfigürasyonu - argümanlar, optionlar vs.
     */
    abstract protected function configure(): void;

    /**
     * Komut mantığı
     */
    abstract protected function handle(): ExitCode;

    /**
     * Komutu çalıştır
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $this->input = $input;
        $this->output = $output;

        // Verbosity ayarla
        $this->configureVerbosity();

        // Input'u definition'a bind et
        $this->input->bind($this->definition);

        // Validate
        $this->input->validate();

        // Execute
        return $this->handle();
    }

    private function configureVerbosity(): void
    {
        $options = $this->input->getOptions();
        $verbosity = Verbosity::fromOptions($options);
        $this->output->setVerbosity($verbosity);
    }

    // ─────────────────────────────────────────────────────────────
    // Configuration Methods
    // ─────────────────────────────────────────────────────────────

    protected function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    protected function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    protected function setHelp(string $help): static
    {
        $this->help = $help;
        return $this;
    }

    protected function addAlias(string $alias): static
    {
        $this->aliases[] = $alias;
        return $this;
    }

    /**
     * @param array<string> $aliases
     */
    protected function setAliases(array $aliases): static
    {
        $this->aliases = $aliases;
        return $this;
    }

    protected function setHidden(bool $hidden = true): static
    {
        $this->hidden = $hidden;
        return $this;
    }

    protected function addArgument(
        string $name,
        int $mode = InputArgument::OPTIONAL,
        string $description = '',
        mixed $default = null,
    ): static {
        $this->definition->addArgument(new InputArgument($name, $mode, $description, $default));
        return $this;
    }

    protected function addOption(
        string $name,
        ?string $shortcut = null,
        int $mode = InputOption::VALUE_NONE,
        string $description = '',
        mixed $default = null,
    ): static {
        $this->definition->addOption(new InputOption($name, $shortcut, $mode, $description, $default));
        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // Input Helpers
    // ─────────────────────────────────────────────────────────────

    protected function argument(string $name, mixed $default = null): mixed
    {
        return $this->input->argument($name, $default);
    }

    protected function option(string $name, mixed $default = null): mixed
    {
        return $this->input->option($name, $default);
    }

    protected function hasOption(string $name): bool
    {
        return $this->input->hasOption($name);
    }

    protected function hasArgument(string $name): bool
    {
        return $this->input->hasArgument($name);
    }

    // ─────────────────────────────────────────────────────────────
    // Output Helpers
    // ─────────────────────────────────────────────────────────────

    protected function write(string $message, Verbosity $verbosity = Verbosity::NORMAL): void
    {
        $this->output->write($message, $verbosity);
    }

    protected function writeln(string $message = '', Verbosity $verbosity = Verbosity::NORMAL): void
    {
        $this->output->writeln($message, $verbosity);
    }

    protected function newLine(int $count = 1): void
    {
        $this->output->newLine($count);
    }

    protected function success(string $message): void
    {
        $this->output->success($message);
    }

    protected function error(string $message): void
    {
        $this->output->error($message);
    }

    protected function warning(string $message): void
    {
        $this->output->warning($message);
    }

    protected function info(string $message): void
    {
        $this->output->info($message);
    }

    protected function comment(string $message): void
    {
        $this->output->comment($message);
    }

    protected function note(string $message): void
    {
        $this->output->note($message);
    }

    protected function caution(string $message): void
    {
        $this->output->caution($message);
    }

    protected function title(string $title): void
    {
        $this->output->title($title);
    }

    protected function section(string $title): void
    {
        $this->output->section($title);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int>> $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }

    /**
     * @param array<int|string, string> $items
     */
    protected function listing(array $items): void
    {
        $this->output->listing($items);
    }

    // ─────────────────────────────────────────────────────────────
    // Progress Bar Helpers
    // ─────────────────────────────────────────────────────────────

    protected function progressStart(int $max, string $message = ''): void
    {
        $this->output->progressStart($max, $message);
    }

    protected function progressAdvance(int $step = 1): void
    {
        $this->output->progressAdvance($step);
    }

    protected function progressFinish(): void
    {
        $this->output->progressFinish();
    }

    // ─────────────────────────────────────────────────────────────
    // Interactive Prompts
    // ─────────────────────────────────────────────────────────────

    protected function ask(string $question, ?string $default = null): string
    {
        // Non-interactive (CI, -n, pipe): yarım prompt basmadan default'a düş.
        if (!$this->input->isInteractive()) {
            return $default ?? '';
        }

        $prompt = $this->output->cyan($question);
        if ($default !== null) {
            $prompt .= $this->output->gray(" [{$default}]");
        }
        $prompt .= ': ';

        $this->output->write($prompt);

        $answer = trim((string) fgets(STDIN));

        return $answer !== '' ? $answer : ($default ?? '');
    }

    protected function secret(string $question): string
    {
        // Non-interactive: gizli girdi alınamaz; prompt basmadan boş dön.
        // (Çağıran zorunlu bir secret bekliyorsa boş değeri kendi doğrulamalı.)
        if (!$this->input->isInteractive()) {
            return '';
        }

        $prompt = $this->output->cyan($question) . ': ';
        $this->output->write($prompt);

        // Windows için
        if (DIRECTORY_SEPARATOR === '\\') {
            $exe = __DIR__ . '/Resources/hiddeninput.exe';

            if (file_exists($exe)) {
                $value = rtrim((string) shell_exec($exe));
            } else {
                // Fallback - normal input
                $value = trim((string) fgets(STDIN));
            }
        } else {
            // Unix için
            system('stty -echo');
            $value = trim((string) fgets(STDIN));
            system('stty echo');
        }

        $this->output->writeln('');

        return $value;
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        // Non-interactive: prompt basmadan default'a düş.
        if (!$this->input->isInteractive()) {
            return $default;
        }

        $defaultText = $default ? 'evet' : 'hayır';
        $prompt = sprintf(
            '%s %s [%s]: ',
            $this->output->cyan($question),
            $this->output->gray('(evet/hayır)'),
            $defaultText
        );

        $this->output->write($prompt);

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['e', 'evet', 'y', 'yes', '1', 'true'], true);
    }

    /**
     * @param array<int|string, string> $choices
     */
    protected function choice(string $question, array $choices, mixed $default = null): mixed
    {
        // Non-interactive: seçenek listesini basmadan default'a düş.
        if (!$this->input->isInteractive()) {
            return $default;
        }

        $this->output->writeln($this->output->cyan($question));

        $indexed = array_values($choices);
        foreach ($indexed as $i => $choice) {
            $this->output->writeln(sprintf(
                '  [%s] %s',
                $this->output->yellow((string) $i),
                $choice
            ));
        }

        $defaultIndex = $default !== null ? array_search($default, $indexed, true) : null;
        $defaultText = $defaultIndex !== false && $defaultIndex !== null ? (string) $defaultIndex : '';

        $prompt = $defaultText !== '' ? " [{$defaultText}]: " : ': ';
        $this->output->write($this->output->gray('Seçiminiz') . $prompt);

        $answer = trim((string) fgets(STDIN));

        if ($answer === '' && $default !== null) {
            return $default;
        }

        if (is_numeric($answer) && isset($indexed[(int) $answer])) {
            return $indexed[(int) $answer];
        }

        if (in_array($answer, $choices, true)) {
            return $answer;
        }

        $this->error('Geçersiz seçim!');
        return $this->choice($question, $choices, $default);
    }

    // ─────────────────────────────────────────────────────────────
    // Verbosity Checks
    // ─────────────────────────────────────────────────────────────

    protected function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    protected function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    protected function isVeryVerbose(): bool
    {
        return $this->output->isVeryVerbose();
    }

    protected function isDebug(): bool
    {
        return $this->output->isDebug();
    }

    // ─────────────────────────────────────────────────────────────
    // Getters
    // ─────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    /**
     * @return array<string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getDefinition(): InputDefinition
    {
        return $this->definition;
    }

    /**
     * Komut kullanım örneklerini döndürür
     *
     * @return array<string>
     */
    public function getUsages(): array
    {
        return [];
    }

    /**
     * Komut yardım metnini formatlar
     */
    public function getProcessedHelp(): string
    {
        $help = $this->help;

        // Placeholder'ları değiştir
        $help = str_replace('%command.name%', $this->name, $help);
        $help = str_replace('%command.full_name%', 'php frame ' . $this->name, $help);

        return $help;
    }
}
