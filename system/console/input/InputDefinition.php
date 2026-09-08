<?php

declare(strict_types=1);

namespace System\Console\Input;

use System\Console\Exception\InvalidArgumentException;

final class InputDefinition
{
    /** @var array<string, InputArgument> */
    private array $arguments = [];

    /** @var array<string, InputOption> */
    private array $options = [];

    /** @var array<string, string> Shortcut -> Option name mapping */
    private array $shortcuts = [];

    private int $requiredArgumentCount = 0;
    private bool $hasArrayArgument = false;
    private bool $hasOptionalArgument = false;

    public function addArgument(InputArgument $argument): self
    {
        if ($this->hasArrayArgument) {
            throw new InvalidArgumentException(
                'Array argümandan sonra başka argüman eklenemez.'
            );
        }

        if (isset($this->arguments[$argument->name])) {
            throw new InvalidArgumentException(
                "'{$argument->name}' argümanı zaten tanımlı."
            );
        }

        if ($argument->isRequired() && $this->hasOptionalArgument) {
            throw new InvalidArgumentException(
                'Zorunlu argüman, opsiyonel argümandan sonra eklenemez.'
            );
        }

        if ($argument->isArray()) {
            $this->hasArrayArgument = true;
        }

        if ($argument->isRequired()) {
            $this->requiredArgumentCount++;
        } else {
            $this->hasOptionalArgument = true;
        }

        $this->arguments[$argument->name] = $argument;

        return $this;
    }

    public function addOption(InputOption $option): self
    {
        if (isset($this->options[$option->name])) {
            throw new InvalidArgumentException(
                "'{$option->name}' option'ı zaten tanımlı."
            );
        }

        if ($option->shortcut !== null) {
            if (isset($this->shortcuts[$option->shortcut])) {
                throw new InvalidArgumentException(
                    "'-{$option->shortcut}' shortcut'ı zaten kullanımda."
                );
            }
            $this->shortcuts[$option->shortcut] = $option->name;
        }

        $this->options[$option->name] = $option;

        return $this;
    }

    public function getArgument(string|int $name): InputArgument
    {
        if (is_int($name)) {
            $arguments = array_values($this->arguments);
            if (!isset($arguments[$name])) {
                throw new InvalidArgumentException("Argüman index '{$name}' bulunamadı.");
            }
            return $arguments[$name];
        }

        if (!isset($this->arguments[$name])) {
            throw new InvalidArgumentException("Argüman '{$name}' bulunamadı.");
        }

        return $this->arguments[$name];
    }

    public function getOption(string $name): InputOption
    {
        if (!isset($this->options[$name])) {
            throw new InvalidArgumentException("Option '{$name}' bulunamadı.");
        }

        return $this->options[$name];
    }

    public function hasArgument(string|int $name): bool
    {
        if (is_int($name)) {
            return isset(array_values($this->arguments)[$name]);
        }

        return isset($this->arguments[$name]);
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function hasShortcut(string $shortcut): bool
    {
        return isset($this->shortcuts[$shortcut]);
    }

    public function getOptionByShortcut(string $shortcut): InputOption
    {
        if (!isset($this->shortcuts[$shortcut])) {
            throw new InvalidArgumentException("Shortcut '-{$shortcut}' bulunamadı.");
        }

        return $this->options[$this->shortcuts[$shortcut]];
    }

    public function getOptionForShortcut(string $shortcut): ?InputOption
    {
        return isset($this->shortcuts[$shortcut])
            ? $this->options[$this->shortcuts[$shortcut]]
            : null;
    }

    /**
     * @return array<string, InputArgument>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, InputOption>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getArgumentCount(): int
    {
        return count($this->arguments);
    }

    public function getRequiredArgumentCount(): int
    {
        return $this->requiredArgumentCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArgumentDefaults(): array
    {
        $defaults = [];
        foreach ($this->arguments as $argument) {
            $defaults[$argument->name] = $argument->getDefault();
        }
        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptionDefaults(): array
    {
        $defaults = [];
        foreach ($this->options as $option) {
            $defaults[$option->name] = $option->getDefault();
        }
        return $defaults;
    }

    public function getSynopsis(bool $short = false): string
    {
        $elements = [];

        foreach ($this->options as $option) {
            $shortcut = $option->shortcut ? "-{$option->shortcut}|" : '';
            $value = '';

            if ($option->acceptValue()) {
                $value = $option->isValueRequired()
                    ? ' <' . strtoupper($option->name) . '>'
                    : ' [' . strtoupper($option->name) . ']';
            }

            $elements[] = "[{$shortcut}--{$option->name}{$value}]";
        }

        foreach ($this->arguments as $argument) {
            $element = '<' . $argument->name . '>';

            if ($argument->isArray()) {
                $element .= '...';
            }

            if (!$argument->isRequired()) {
                $element = "[{$element}]";
            }

            $elements[] = $element;
        }

        return implode(' ', $elements);
    }
}
