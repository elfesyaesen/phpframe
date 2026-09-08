<?php

declare(strict_types=1);

namespace System\Console\Input;

use System\Console\Exception\InvalidArgumentException;
use System\Console\Exception\InvalidOptionException;

final class Input
{
    /** @var array<string, mixed> */
    private array $arguments = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private string $command = '';

    private bool $interactive = true;

    public function __construct(
        private readonly ?InputDefinition $definition = null,
    ) {}

    public function bind(InputDefinition $definition): void
    {
        // Mevcut index-based argümanları sakla
        $parsedArguments = $this->arguments;
        $parsedOptions = $this->options;

        // Default değerleri yükle
        $this->arguments = $definition->getArgumentDefaults();
        $this->options = array_merge($definition->getOptionDefaults(), $parsedOptions);

        // Index-based argümanları isimli argümanlara dönüştür
        $argumentNames = array_keys($definition->getArguments());
        foreach ($parsedArguments as $index => $value) {
            if (is_int($index) && isset($argumentNames[$index])) {
                $this->arguments[$argumentNames[$index]] = $value;
            } elseif (is_string($index)) {
                $this->arguments[$index] = $value;
            }
        }
    }

    public function parse(array $argv): void
    {
        // Script adını atla
        array_shift($argv);

        // Komut adı (ilk non-option argüman)
        if (!empty($argv) && !str_starts_with($argv[0], '-')) {
            $this->command = array_shift($argv);
        }

        $argumentIndex = 0;
        $parseOptions = true;

        while (null !== $token = array_shift($argv)) {
            // -- işareti options parsing'i durdurur
            if ($token === '--') {
                $parseOptions = false;
                continue;
            }

            if ($parseOptions && str_starts_with($token, '--')) {
                $this->parseLongOption($token);
            } elseif ($parseOptions && str_starts_with($token, '-') && $token !== '-') {
                $this->parseShortOption($token, $argv);
            } else {
                $this->parseArgument($token, $argumentIndex++);
            }
        }
    }

    private function parseLongOption(string $token): void
    {
        $name = substr($token, 2);
        $value = null;

        // --option=value formatı
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        }

        // Negatable option: --no-option
        if (str_starts_with($name, 'no-')) {
            $realName = substr($name, 3);
            if ($this->definition?->hasOption($realName)) {
                $option = $this->definition->getOption($realName);
                if ($option->isNegatable()) {
                    $this->options[$realName] = false;
                    return;
                }
            }
        }

        if ($this->definition !== null && $this->definition->hasOption($name)) {
            $option = $this->definition->getOption($name);

            if ($option->acceptValue()) {
                if ($value === null && $option->isValueRequired()) {
                    throw new InvalidOptionException("Option '--{$name}' değer gerektirir.");
                }
                $this->addOptionValue($name, $value ?? $option->getDefault(), $option->isArray());
            } else {
                if ($value !== null) {
                    throw new InvalidOptionException("Option '--{$name}' değer kabul etmez.");
                }
                $this->options[$name] = true;
            }
        } else {
            // Definition yoksa veya option tanımlı değilse
            $this->options[$name] = $value ?? true;
        }
    }

    private function parseShortOption(string $token, array &$argv): void
    {
        $shortcut = substr($token, 1);

        // Birden fazla short option: -vvv
        if (strlen($shortcut) > 1 && !str_contains($shortcut, '=')) {
            // Tekrarlı TEK harf (örn. -vv, -vvv): tek anahtar olarak kaydet. Aksi halde
            // -v -v -v'ye bölünüp birbirini ezerdi ve verbosity seviyesi hep 1 kalırdı.
            if (strspn($shortcut, $shortcut[0]) === strlen($shortcut)) {
                $this->options[$shortcut] = true;
                return;
            }

            // Farklı harfler (-abc) -> -a -b -c olarak parse et
            foreach (str_split($shortcut) as $char) {
                $this->parseShortOption("-{$char}", $argv);
            }
            return;
        }

        // -o=value formatı
        if (str_contains($shortcut, '=')) {
            [$shortcut, $value] = explode('=', $shortcut, 2);
            $this->setOptionByShortcut($shortcut, $value);
            return;
        }

        $shortcut = $shortcut[0];

        if ($this->definition !== null && $this->definition->hasShortcut($shortcut)) {
            $option = $this->definition->getOptionByShortcut($shortcut);

            if ($option->acceptValue()) {
                // Sonraki argüman value
                if (empty($argv) && $option->isValueRequired()) {
                    throw new InvalidOptionException("Option '-{$shortcut}' değer gerektirir.");
                }

                $value = $option->isValueRequired() && !empty($argv) ? array_shift($argv) : $option->getDefault();
                $this->addOptionValue($option->name, $value, $option->isArray());
            } else {
                $this->options[$option->name] = true;
            }
        } else {
            // Definition yoksa
            $this->options[$shortcut] = true;
        }
    }

    private function parseArgument(string $token, int $index): void
    {
        if ($this->definition !== null && $this->definition->hasArgument($index)) {
            $argument = $this->definition->getArgument($index);

            if ($argument->isArray()) {
                $this->arguments[$argument->name][] = $token;
            } else {
                $this->arguments[$argument->name] = $token;
            }
        } else {
            // Definition yoksa index-based
            $this->arguments[$index] = $token;
        }
    }

    private function addOptionValue(string $name, mixed $value, bool $isArray): void
    {
        if ($isArray) {
            $this->options[$name] = array_merge($this->options[$name] ?? [], [$value]);
        } else {
            $this->options[$name] = $value;
        }
    }

    private function setOptionByShortcut(string $shortcut, mixed $value): void
    {
        if ($this->definition !== null && $this->definition->hasShortcut($shortcut)) {
            $option = $this->definition->getOptionByShortcut($shortcut);
            $this->addOptionValue($option->name, $value, $option->isArray());
        } else {
            $this->options[$shortcut] = $value;
        }
    }

    public function validate(): void
    {
        if ($this->definition === null) {
            return;
        }

        $givenArguments = array_filter(
            $this->arguments,
            fn($value) => $value !== null && $value !== []
        );

        if (count($givenArguments) < $this->definition->getRequiredArgumentCount()) {
            throw new InvalidArgumentException('Yeterli argüman verilmedi.');
        }
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function argument(string|int $name, mixed $default = null): mixed
    {
        if (is_int($name)) {
            return $this->arguments[$name] ?? $default;
        }

        return $this->arguments[$name] ?? $default;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function hasArgument(string|int $name): bool
    {
        return isset($this->arguments[$name]);
    }

    /**
     * @return array<string, mixed>
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
        if ($isArray) {
            $this->options[$name] = array_merge($this->options[$name] ?? [], (array) $value);
        } else {
            $this->options[$name] = $value;
        }
    }

    public function isInteractive(): bool
    {
        if (!$this->interactive) {
            return false;
        }

        if ($this->hasOption('no-interaction') || $this->hasOption('n')) {
            return false;
        }

        return defined('STDIN') && function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    public function setInteractive(bool $interactive): void
    {
        $this->interactive = $interactive;
    }
}
