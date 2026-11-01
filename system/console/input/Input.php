<?php

declare(strict_types=1);

namespace System\Console\Input;

use System\Console\Exception\InvalidArgumentException;
use System\Console\Exception\InvalidOptionException;

final class Input
{
    private const GLOBAL_LONG = ['quiet', 'verbose', 'no-interaction', 'help', 'version'];

    private const GLOBAL_SHORT = ['q', 'v', 'vv', 'vvv', 'n', 'h', 'V'];

    /** @var array<string|int, mixed> */
    private array $arguments = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /**
     * @var array<string, true>
     */
    private array $given = [];

    /**
     * @var list<string>
     */
    private array $tokens = [];

    private string $command = '';

    private bool $interactive = true;

    public function __construct(
        private readonly ?InputDefinition $definition = null,
    ) {}

    public function parse(array $argv): void
    {
        array_shift($argv);

        $tokens = array_values(array_map('strval', $argv));
        $this->command = '';

        foreach ($tokens as $index => $token) {
            if ($token === '--') {
                break;
            }

            if ($this->isGlobalFlag($token)) {
                continue;
            }

            if (!str_starts_with($token, '-')) {
                $this->command = $token;
                array_splice($tokens, $index, 1);
            }

            break;
        }

        $this->tokens = $tokens;
        $this->arguments = [];
        $this->options = [];
        $this->given = [];

        if ($this->definition !== null) {
            $this->parseTokens($this->definition);

            return;
        }

        foreach ($tokens as $token) {
            if ($token === '--') {
                break;
            }

            if ($this->isGlobalFlag($token)) {
                $this->setGlobal($token);
            }
        }
    }

    /**
     * @throws InvalidOptionException
     * @throws InvalidArgumentException
     */
    public function bind(InputDefinition $definition): void
    {
        $this->arguments = [];
        $this->options = [];
        $this->given = [];

        $this->parseTokens($definition);

        $this->arguments = array_merge($definition->getArgumentDefaults(), $this->arguments);

        foreach ($definition->getOptionDefaults() as $name => $default) {
            $this->options[$name] ??= $default;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(InputDefinition $definition): void
    {
        $missing = [];

        foreach ($definition->getArguments() as $name => $argument) {
            $value = $this->arguments[$name] ?? null;

            if ($argument->isRequired() && ($value === null || $value === '' || $value === [])) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException('Eksik argüman: ' . implode(', ', $missing));
        }
    }

    private function parseTokens(InputDefinition $definition): void
    {
        $tokens = $this->tokens;
        $argumentIndex = 0;
        $parseOptions = true;

        while (null !== $token = array_shift($tokens)) {
            if ($parseOptions && $token === '--') {
                $parseOptions = false;
                continue;
            }

            if ($parseOptions && str_starts_with($token, '--')) {
                $this->parseLongOption($definition, $token, $tokens);
            } elseif ($parseOptions && $this->looksLikeShortOption($definition, $token)) {
                $this->parseShortOption($definition, substr($token, 1), $tokens);
            } else {
                $this->parseArgument($definition, $token, $argumentIndex++);
            }
        }
    }

    private function looksLikeShortOption(InputDefinition $definition, string $token): bool
    {
        if (!str_starts_with($token, '-') || $token === '-') {
            return false;
        }

        if (preg_match('/^-\d/', $token) === 1 && !$definition->hasShortcut($token[1])) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $tokens
     */
    private function parseLongOption(InputDefinition $definition, string $token, array &$tokens): void
    {
        $name = substr($token, 2);
        $value = null;

        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        }

        if (!$definition->hasOption($name)) {
            if (str_starts_with($name, 'no-') && $definition->hasOption(substr($name, 3))) {
                $option = $definition->getOption(substr($name, 3));

                if ($option->isNegatable()) {
                    if ($value !== null) {
                        throw new InvalidOptionException("Option '--{$name}' değer kabul etmez.");
                    }

                    $this->setValue($option->name, false, false);

                    return;
                }
            }

            if ($value === null && in_array($name, self::GLOBAL_LONG, true)) {
                $this->setGlobal('--' . $name);

                return;
            }

            throw new InvalidOptionException("Bilinmeyen option: '--{$name}'");
        }

        $option = $definition->getOption($name);

        if (!$option->acceptValue()) {
            if ($value !== null) {
                throw new InvalidOptionException("Option '--{$name}' değer kabul etmez.");
            }

            $this->setValue($option->name, true, false);

            return;
        }

        if ($value === null && $tokens !== []) {
            if ($option->isValueRequired() || !str_starts_with($tokens[0], '-')) {
                $value = array_shift($tokens);
            }
        }

        if ($value === null) {
            if ($option->isValueRequired()) {
                throw new InvalidOptionException("Option '--{$name}' değer gerektirir.");
            }

            $value = $option->getDefault();
        }

        $this->setValue($option->name, $value, $option->isArray());
    }

    /**
     * @param list<string> $tokens
     */
    private function parseShortOption(InputDefinition $definition, string $shortcut, array &$tokens): void
    {
        if (in_array($shortcut, self::GLOBAL_SHORT, true) && !$definition->hasShortcut($shortcut[0])) {
            $this->setGlobal('-' . $shortcut);

            return;
        }

        $length = strlen($shortcut);

        for ($i = 0; $i < $length; $i++) {
            $char = $shortcut[$i];

            if (!$definition->hasShortcut($char)) {
                if (in_array($char, self::GLOBAL_SHORT, true)) {
                    $this->setGlobal('-' . $char);
                    continue;
                }

                throw new InvalidOptionException("Bilinmeyen option: '-{$char}'");
            }

            $option = $definition->getOptionByShortcut($char);

            if (!$option->acceptValue()) {
                $this->setValue($option->name, true, false);
                continue;
            }

            $rest = substr($shortcut, $i + 1);
            $value = $rest !== '' ? ltrim($rest, '=') : null;

            if ($value === null && $tokens !== []) {
                if ($option->isValueRequired() || !str_starts_with($tokens[0], '-')) {
                    $value = array_shift($tokens);
                }
            }

            if ($value === null) {
                if ($option->isValueRequired()) {
                    throw new InvalidOptionException("Option '-{$char}' değer gerektirir.");
                }

                $value = $option->getDefault();
            }

            $this->setValue($option->name, $value, $option->isArray());

            return;
        }
    }

    private function parseArgument(InputDefinition $definition, string $token, int $index): void
    {
        if ($definition->hasArgument($index)) {
            $argument = $definition->getArgument($index);

            if ($argument->isArray()) {
                $this->arguments[$argument->name][] = $token;
            } else {
                $this->arguments[$argument->name] = $token;
            }

            return;
        }

        $arguments = array_values($definition->getArguments());
        $last = end($arguments);

        if ($last instanceof InputArgument && $last->isArray()) {
            $this->arguments[$last->name][] = $token;

            return;
        }

        throw new InvalidArgumentException("Fazla argüman: '{$token}'");
    }

    private function setValue(string $name, mixed $value, bool $isArray): void
    {
        $this->given[$name] = true;

        if ($isArray) {
            $this->options[$name][] = $value;
        } else {
            $this->options[$name] = $value;
        }
    }

    private function isGlobalFlag(string $token): bool
    {
        if (str_starts_with($token, '--')) {
            return in_array(substr($token, 2), self::GLOBAL_LONG, true);
        }

        return str_starts_with($token, '-') && in_array(substr($token, 1), self::GLOBAL_SHORT, true);
    }

    private function setGlobal(string $token): void
    {
        $key = ltrim($token, '-');
        $this->options[$key] = true;
        $this->given[$key] = true;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @return list<string>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function argument(string|int $name, mixed $default = null): mixed
    {
        return $this->arguments[$name] ?? $default;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->given[$name]);
    }

    public function hasArgument(string|int $name): bool
    {
        return isset($this->arguments[$name]);
    }

    /**
     * @return array<string|int, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function setArgument(string $name, mixed $value): void
    {
        $this->arguments[$name] = $value;
    }

    public function setOption(string $name, mixed $value, bool $isArray = false): void
    {
        $this->given[$name] = true;

        if ($isArray) {
            $this->options[$name] = array_merge((array) ($this->options[$name] ?? []), (array) $value);
        } else {
            $this->options[$name] = $value;
        }
    }

    public function isInteractive(): bool
    {
        if (!$this->interactive || $this->hasOption('no-interaction') || $this->hasOption('n')) {
            return false;
        }

        if (!defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    public function setInteractive(bool $interactive): void
    {
        $this->interactive = $interactive;
    }
}
