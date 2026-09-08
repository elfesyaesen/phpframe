<?php

declare(strict_types=1);

namespace System\Console\Question;

/**
 * Evet/Hayır onay sorusu
 */
final class ConfirmationQuestion extends Question
{
    private string $trueAnswerRegex;

    public function __construct(
        string $question,
        bool $default = true,
        string $trueAnswerRegex = '/^(y|yes|evet|e|1|true)$/i',
    ) {
        parent::__construct($question, $default);
        $this->trueAnswerRegex = $trueAnswerRegex;
    }

    /**
     * @return bool
     */
    public function getDefault(): mixed
    {
        return (bool) $this->default;
    }

    public function getTrueAnswerRegex(): string
    {
        return $this->trueAnswerRegex;
    }

    public function setTrueAnswerRegex(string $regex): self
    {
        $this->trueAnswerRegex = $regex;
        return $this;
    }

    /**
     * Cevabı normalize et
     */
    public function normalizeAnswer(string $answer): bool
    {
        if ($answer === '') {
            return $this->getDefault();
        }

        return (bool) preg_match($this->trueAnswerRegex, $answer);
    }

    public function getPrompt(): string
    {
        $default = $this->getDefault() ? 'evet' : 'hayır';

        return sprintf('%s (evet/hayır) [%s]: ', $this->question, $default);
    }
}
