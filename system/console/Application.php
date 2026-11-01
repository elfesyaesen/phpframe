<?php

declare(strict_types=1);

namespace System\Console;

use System\Console\Attributes\Command as CommandAttribute;
use System\Console\Exception\CommandNotFoundException;
use System\Console\Exception\InvalidArgumentException;
use System\Console\Exception\InvalidOptionException;
use System\Console\Input\Input;
use System\Console\Input\InputDefinition;
use System\Console\Input\InputOption;
use System\Console\Loader\CommandLoader;
use System\Console\Output\Output;
use System\Console\Output\Verbosity;
use ReflectionClass;

final class Application
{
    private const VERSION = '1.0.0';

    /** @var array<string, Command> */
    private array $commands = [];

    private ?CommandLoader $loader = null;
    private ?string $defaultCommand = null;
    private bool $autoExit = true;
    private bool $catchExceptions = true;

    public function __construct(
        private readonly string $name = 'Frame CLI',
        private readonly string $version = self::VERSION,
    ) {}

    public function run(?Input $input = null, ?Output $output = null): int
    {
        if ($input === null) {
            $input = new Input();
            $input->parse($_SERVER['argv'] ?? []);
        }

        $output ??= new Output(
            Verbosity::fromOptions($input->getOptions())
        );

        try {
            $exitCode = $this->doRun($input, $output);
        } catch (CommandNotFoundException $e) {
            $output->error($e->getMessage());

            if (!empty($e->getAlternatives())) {
                $output->newLine();
                $output->writeln('Benzer komutlar:');
                foreach ($e->getAlternatives() as $alternative) {
                    $output->writeln('  ' . $output->green($alternative));
                }
            }

            $exitCode = ExitCode::FAILURE->value;
        } catch (InvalidArgumentException | InvalidOptionException $e) {
            $output->error($e->getMessage());
            $output->writeln($output->gray(
                '  Kullanım için: php frame ' . ($input->getCommand() ?: 'list') . ' --help'
            ));

            $exitCode = ExitCode::INVALID->value;
        } catch (\Throwable $e) {
            if (!$this->catchExceptions) {
                throw $e;
            }

            $output->error($e->getMessage());

            if ($output->isVerbose()) {
                $output->newLine();
                $output->writeln($output->gray($e->getTraceAsString()));
            }

            $exitCode = ExitCode::FAILURE->value;
        }

        if ($this->autoExit) {
            exit($exitCode);
        }

        return $exitCode;
    }

    private function doRun(Input $input, Output $output): int
    {
        $commandName = $input->getCommand();

        if ($input->hasOption('version') || $input->hasOption('V')) {
            $this->renderVersion($output);
            return ExitCode::SUCCESS->value;
        }

        if ($input->hasOption('help') || $input->hasOption('h')) {
            if ($commandName !== '') {
                return $this->runHelp($commandName, $output);
            }
            $this->renderHelp($output);
            return ExitCode::SUCCESS->value;
        }

        if ($commandName === 'help') {
            foreach ($input->getTokens() as $token) {
                if (!str_starts_with($token, '-')) {
                    return $this->runHelp($token, $output);
                }
            }

            $this->renderHelp($output);
            return ExitCode::SUCCESS->value;
        }

        if ($commandName === '' || $commandName === 'list') {
            $this->renderHelp($output);
            return ExitCode::SUCCESS->value;
        }

        $command = $this->find($commandName);

        return $command->run($input, $output)->value;
    }

    public function find(string $name): Command
    {
        if (isset($this->commands[$name])) {
            return $this->commands[$name];
        }

        if ($this->loader !== null && $this->loader->has($name)) {
            return $this->loader->get($name);
        }

        $alternatives = $this->findAlternatives($name);

        throw new CommandNotFoundException(
            "Komut bulunamadı: '{$name}'",
            $alternatives
        );
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) ||
            ($this->loader !== null && $this->loader->has($name));
    }

    public function add(Command $command): self
    {
        $name = $this->getCommandName($command);

        if ($name === '') {
            throw new \InvalidArgumentException('Command name cannot be empty.');
        }

        $this->commands[$name] = $command;

        foreach ($this->getCommandAliases($command) as $alias) {
            $this->commands[$alias] = $command;
        }

        return $this;
    }

    /**
     * @param array<Command> $commands
     */
    public function addCommands(array $commands): self
    {
        foreach ($commands as $command) {
            $this->add($command);
        }

        return $this;
    }

    public function setCommandLoader(CommandLoader $loader): self
    {
        $this->loader = $loader;
        return $this;
    }

    public function setDefaultCommand(string $commandName): self
    {
        $this->defaultCommand = $commandName;
        return $this;
    }

    public function setAutoExit(bool $autoExit): self
    {
        $this->autoExit = $autoExit;
        return $this;
    }

    public function setCatchExceptions(bool $catch): self
    {
        $this->catchExceptions = $catch;
        return $this;
    }

    /**
     * @return array<string, Command>
     */
    public function all(): array
    {
        $commands = $this->commands;

        if ($this->loader !== null) {
            foreach ($this->loader->getCommands() as $command) {
                $name = $this->getCommandName($command);
                if (!isset($commands[$name])) {
                    $commands[$name] = $command;
                }
            }
        }

        return $commands;
    }

    /**
     * @return array<string>
     */
    private function findAlternatives(string $name): array
    {
        $alternatives = [];
        $allNames = array_keys($this->all());

        foreach ($allNames as $commandName) {
            $lev = levenshtein($name, $commandName);

            if ($lev <= strlen($name) / 3 || str_contains($commandName, $name)) {
                $alternatives[] = $commandName;
            }
        }

        return $alternatives;
    }

    private function getCommandName(Command $command): string
    {
        $reflection = new ReflectionClass($command);
        $attributes = $reflection->getAttributes(CommandAttribute::class);

        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->name;
        }

        return $command->getName();
    }

    /**
     * @return array<string>
     */
    private function getCommandAliases(Command $command): array
    {
        $reflection = new ReflectionClass($command);
        $attributes = $reflection->getAttributes(CommandAttribute::class);

        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->aliases;
        }

        return $command->getAliases();
    }

    private function getCommandUsage(Command $command): string
    {
        $attributes = (new ReflectionClass($command))->getAttributes(CommandAttribute::class);
        $usage = $attributes !== [] ? ($attributes[0]->newInstance()->usage ?? '') : '';

        return $usage !== '' ? $usage : 'php frame ' . $this->getCommandName($command) . ' [argümanlar] [--seçenekler]';
    }

    private function formatDefault(mixed $default): string
    {
        if ($default === null || $default === false || $default === []) {
            return '';
        }

        $text = match (true) {
            is_array($default) => implode(', ', array_map('strval', $default)),
            is_bool($default) => 'true',
            default => (string) $default,
        };

        return " [default: {$text}]";
    }

    private function getCommandDescription(Command $command): string
    {
        $reflection = new ReflectionClass($command);
        $attributes = $reflection->getAttributes(CommandAttribute::class);

        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->description;
        }

        return $command->getDescription();
    }

    private function renderVersion(Output $output): void
    {
        $output->writeln(sprintf(
            '%s %s',
            $output->green($this->name),
            $output->yellow($this->version)
        ));
    }

    private function renderHelp(Output $output): void
    {
        $this->renderVersion($output);
        $output->newLine();

        $output->writeln($output->yellow('Kullanım:'));
        $output->writeln('  php frame <komut> [argümanlar] [--seçenekler]');
        $output->newLine();

        $output->writeln($output->yellow('Genel Seçenekler:'));
        $output->writeln('  ' . $output->green('-h, --help') . '         Yardım göster');
        $output->writeln('  ' . $output->green('-q, --quiet') . '        Çıktı verme');
        $output->writeln('  ' . $output->green('-v|vv|vvv, --verbose') . ' Detay seviyesi');
        $output->writeln('  ' . $output->green('-V, --version') . '      Versiyon göster');
        $output->writeln('  ' . $output->green('-n, --no-interaction') . ' İnteraktif olmayan mod');
        $output->newLine();

        $commands = $this->all();
        $grouped = $this->groupCommands($commands);

        $output->writeln($output->yellow('Komutlar:'));

        foreach ($grouped as $namespace => $cmds) {
            if ($namespace !== '') {
                $output->writeln(' ' . $output->cyan($namespace));
            }

            foreach ($cmds as $name => $command) {
                if ($command->isHidden()) {
                    continue;
                }

                $description = $this->getCommandDescription($command);
                $aliases = $this->getCommandAliases($command);
                $output->writeln(sprintf(
                    '  %s%s%s%s',
                    $output->green(str_pad($name, 25)),
                    '  ',
                    $description,
                    $aliases !== [] ? $output->gray(' (' . implode(', ', $aliases) . ')') : ''
                ));
            }
        }

        $output->newLine();
    }

    private function runHelp(string $commandName, Output $output): int
    {
        try {
            $command = $this->find($commandName);
        } catch (CommandNotFoundException $e) {
            $output->error($e->getMessage());
            return ExitCode::FAILURE->value;
        }

        $name = $this->getCommandName($command);
        $description = $this->getCommandDescription($command);

        $output->writeln($output->yellow('Kullanım:'));
        $output->writeln('  ' . $this->getCommandUsage($command));
        $output->newLine();

        $aliases = $this->getCommandAliases($command);
        if ($aliases !== []) {
            $output->writeln($output->yellow('Kısa adlar:') . ' ' . implode(', ', $aliases));
            $output->newLine();
        }

        if ($description !== '') {
            $output->writeln($output->yellow('Açıklama:'));
            $output->writeln("  {$description}");
            $output->newLine();
        }

        $definition = $command->getDefinition();
        $arguments = $definition->getArguments();

        if (!empty($arguments)) {
            $output->writeln($output->yellow('Argümanlar:'));
            foreach ($arguments as $arg) {
                $required = $arg->isRequired() ? ' (zorunlu)' : '';
                $default = $this->formatDefault($arg->getDefault());
                $output->writeln(sprintf(
                    '  %s%s%s%s',
                    $output->green(str_pad($arg->name, 20)),
                    $arg->description,
                    $output->gray($required),
                    $output->gray($default)
                ));
            }
            $output->newLine();
        }

        $options = $definition->getOptions();

        if (!empty($options)) {
            $output->writeln($output->yellow('Seçenekler:'));
            foreach ($options as $opt) {
                $shortcut = $opt->shortcut ? "-{$opt->shortcut}, " : '    ';
                $default = $this->formatDefault($opt->getDefault());

                $signature = "--{$opt->name}" . match (true) {
                    $opt->isValueRequired() => '=DEĞER',
                    $opt->isValueOptional() => '[=DEĞER]',
                    default => '',
                } . ($opt->isNegatable() ? "|--no-{$opt->name}" : '');

                $output->writeln(sprintf(
                    '  %s%s  %s%s',
                    $output->green($shortcut),
                    $output->green(str_pad($signature, 24 + strlen($signature) - mb_strlen($signature))),
                    $opt->description,
                    $output->gray($default)
                ));
            }
            $output->newLine();
        }

        $help = $command->getProcessedHelp();
        if ($help !== '') {
            $output->writeln($output->yellow('Yardım:'));
            $output->writeln("  {$help}");
            $output->newLine();
        }

        return ExitCode::SUCCESS->value;
    }

    /**
     * @param array<string, Command> $commands
     * @return array<string, array<string, Command>>
     */
    private function groupCommands(array $commands): array
    {
        $grouped = ['' => []];

        foreach ($commands as $name => $command) {
            if (str_contains($name, ':')) {
                $namespace = substr($name, 0, strpos($name, ':'));
                $grouped[$namespace][$name] = $command;
            } else {
                $grouped[''][$name] = $command;
            }
        }

        ksort($grouped);
        foreach ($grouped as &$cmds) {
            ksort($cmds);
        }

        return $grouped;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}
