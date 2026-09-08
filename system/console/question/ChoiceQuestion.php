<?php

declare(strict_types=1);

namespace System\Console\Question;

use System\Console\Exception\InvalidArgumentException;

/**
 * Seçenekli soru
 */
final class ChoiceQuestion extends Question
{
    /** @var array<int|string, string> */
    private array $choices;

    private bool $multiselect = false;
    private string $errorMessage = 'Geçersiz seçim: "%s"';
    private string $prompt = 'Seçiminiz';

    /**
     * @param array<int|string, string> $choices
     */
    public function __construct(
        string $question,
        array $choices,
        mixed $default = null,
    ) {
        parent::__construct($question, $default);
        $this->choices = $choices;
    }

    /**
     * @return array<int|string, string>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    public function isMultiselect(): bool
    {
        return $this->multiselect;
    }

    public function setMultiselect(bool $multiselect): self
    {
        $this->multiselect = $multiselect;
        return $this;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $message): self
    {
        $this->errorMessage = $message;
        return $this;
    }

    public function getSelectionPrompt(): string
    {
        return $this->prompt;
    }

    public function setSelectionPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Verilen cevabı seçeneklerden birine dönüştür
     *
     * @return string|array<string>
     */
    public function normalizeAnswer(string $answer): string|array
    {
        if ($answer === '' && $this->default !== null) {
            return $this->default;
        }

        $choices = $this->choices;

        // Multiselect için virgülle ayrılmış değerler
        if ($this->multiselect) {
            $selectedValues = explode(',', $answer);
            $selectedValues = array_map('trim', $selectedValues);

            $result = [];
            foreach ($selectedValues as $value) {
                $result[] = $this->resolveChoice($value, $choices);
            }

            return $result;
        }

        return $this->resolveChoice($answer, $choices);
    }

    /**
     * @param array<int|string, string> $choices
     */
    private function resolveChoice(string $value, array $choices): string
    {
        // Numara ile seçim
        if (is_numeric($value)) {
            $index = (int) $value;
            $indexed = array_values($choices);

            if (isset($indexed[$index])) {
                return $indexed[$index];
            }

            throw new InvalidArgumentException(sprintf($this->errorMessage, $value));
        }

        // Değer ile seçim
        if (in_array($value, $choices, true)) {
            return $value;
        }

        // Key ile seçim
        if (isset($choices[$value])) {
            return $choices[$value];
        }

        throw new InvalidArgumentException(sprintf($this->errorMessage, $value));
    }

    public function getPrompt(): string
    {
        $text = $this->question . PHP_EOL;

        $indexed = array_values($this->choices);
        foreach ($indexed as $i => $choice) {
            $text .= sprintf("  [%d] %s\n", $i, $choice);
        }

        $defaultText = '';
        if ($this->default !== null) {
            $defaultIndex = array_search($this->default, $indexed, true);
            if ($defaultIndex !== false) {
                $defaultText = " [{$defaultIndex}]";
            }
        }

        $text .= $this->prompt . $defaultText . ': ';

        return $text;
    }
}
