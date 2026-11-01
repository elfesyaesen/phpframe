<?php

declare(strict_types=1);

namespace System\Console;

use System\Console\Input\Input;
use System\Console\Input\InputArgument;
use System\Console\Input\InputDefinition;
use System\Console\Input\InputOption;
use System\Console\Output\Output;
use System\Console\Output\Verbosity;

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

    abstract protected function configure(): void;

    abstract protected function handle(): ExitCode;

    public function run(Input $input, Output $output): ExitCode
    {
        $this->input = $input;
        $this->output = $output;

        $this->input->bind($this->definition);
        $this->input->validate($this->definition);

        $this->configureVerbosity();

        return $this->handle();
    }

    private function configureVerbosity(): void
    {
        $options = $this->input->getOptions();
        $verbosity = Verbosity::fromOptions($options);
        $this->output->setVerbosity($verbosity);
    }

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

    protected function ask(string $question, ?string $default = null): string
    {
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
        if (!$this->input->isInteractive()) {
            return '';
        }

        $prompt = $this->output->cyan($question) . ': ';
        $this->output->write($prompt);

        if (DIRECTORY_SEPARATOR === '\\') {
            $exe = __DIR__ . '/Resources/hiddeninput.exe';

            if (file_exists($exe)) {
                $value = rtrim((string) shell_exec($exe));
            } else {
                $value = trim((string) fgets(STDIN));
            }
        } else {
            system('stty -echo');
            try {
                $value = trim((string) fgets(STDIN));
            } finally {
                system('stty echo');
            }
        }

        $this->output->writeln('');

        return $value;
    }

    protected function confirm(string $question, bool $default = false): bool
    {
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

        $line = fgets(STDIN);

        if ($line === false) {
            $this->output->writeln('');

            return $default;
        }

        $answer = trim($line);

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

    public function getName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        $attributes = (new \ReflectionClass($this))->getAttributes(Attributes\Command::class);

        return $attributes !== [] ? $attributes[0]->newInstance()->name : '';
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
     * @return array<string>
     */
    public function getUsages(): array
    {
        return [];
    }

    public function getProcessedHelp(): string
    {
        $help = $this->help;

        $help = str_replace('%command.name%', $this->getName(), $help);
        $help = str_replace('%command.full_name%', 'php frame ' . $this->getName(), $help);

        return $help;
    }
}
