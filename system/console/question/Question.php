<?php

declare(strict_types=1);

namespace System\Console\Question;

/**
 * Basit soru sınıfı
 */
class Question
{
    protected mixed $default;
    protected bool $hidden = false;
    protected bool $hiddenFallback = true;

    /** @var callable|null */
    protected $validator = null;

    /** @var callable|null */
    protected $normalizer = null;

    protected int $maxAttempts = 0;

    /** @var array<string> */
    protected array $autocompleteValues = [];

    public function __construct(
        protected readonly string $question,
        mixed $default = null,
    ) {
        $this->default = $default;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;
        return $this;
    }

    public function isHiddenFallback(): bool
    {
        return $this->hiddenFallback;
    }

    public function setHiddenFallback(bool $fallback): static
    {
        $this->hiddenFallback = $fallback;
        return $this;
    }

    /**
     * @return callable|null
     */
    public function getValidator(): ?callable
    {
        return $this->validator;
    }

    public function setValidator(?callable $validator): static
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * @return callable|null
     */
    public function getNormalizer(): ?callable
    {
        return $this->normalizer;
    }

    public function setNormalizer(?callable $normalizer): static
    {
        $this->normalizer = $normalizer;
        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getAutocompleteValues(): array
    {
        return $this->autocompleteValues;
    }

    /**
     * @param array<string> $values
     */
    public function setAutocompleteValues(array $values): static
    {
        $this->autocompleteValues = $values;
        return $this;
    }

    /**
     * Prompt oluştur
     */
    public function getPrompt(): string
    {
        $prompt = $this->question;

        if ($this->default !== null && $this->default !== '') {
            $defaultStr = is_bool($this->default)
                ? ($this->default ? 'yes' : 'no')
                : (string) $this->default;
            $prompt .= " [{$defaultStr}]";
        }

        return $prompt . ': ';
    }
}
