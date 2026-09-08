<?php

declare(strict_types=1);

namespace System\Console\Question;

use System\Console\Exception\InvalidArgumentException;
use System\Console\Input\Input;
use System\Console\Output\Output;

/**
 * Question helper - soruları işler ve cevapları alır
 */
final class QuestionHelper
{
    /**
     * Soru sor ve cevabı al
     */
    public function ask(Input $input, Output $output, Question $question): mixed
    {
        // Interactive değilse default döndür
        if (!$input->isInteractive()) {
            return $question->getDefault();
        }

        // Hidden input (şifre gibi)
        if ($question->isHidden()) {
            return $this->askHidden($output, $question);
        }

        // Choice question
        if ($question instanceof ChoiceQuestion) {
            return $this->askChoice($input, $output, $question);
        }

        // Confirmation question
        if ($question instanceof ConfirmationQuestion) {
            return $this->askConfirmation($output, $question);
        }

        // Normal question
        return $this->askQuestion($output, $question);
    }

    private function askQuestion(Output $output, Question $question): mixed
    {
        $attempts = $question->getMaxAttempts();
        $attemptCount = 0;

        do {
            $output->write($this->formatPrompt($output, $question));
            $answer = $this->readInput();

            // Boş cevap - default kullan
            if ($answer === '' && $question->getDefault() !== null) {
                $answer = $question->getDefault();
            }

            // Normalizer uygula
            if ($question->getNormalizer() !== null) {
                $answer = ($question->getNormalizer())($answer);
            }

            // Validator uygula
            if ($question->getValidator() !== null) {
                try {
                    $answer = ($question->getValidator())($answer);
                    return $answer;
                } catch (\Throwable $e) {
                    $output->error($e->getMessage());
                    $attemptCount++;

                    if ($attempts > 0 && $attemptCount >= $attempts) {
                        throw $e;
                    }
                    continue;
                }
            }

            return $answer;
        } while ($attempts === 0 || $attemptCount < $attempts);

        return $question->getDefault();
    }

    private function askHidden(Output $output, Question $question): string
    {
        $output->write($this->formatPrompt($output, $question));

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows
            return $this->readHiddenInputWindows();
        }

        // Unix
        return $this->readHiddenInputUnix();
    }

    private function askConfirmation(Output $output, ConfirmationQuestion $question): bool
    {
        $output->write($output->cyan($question->getPrompt()));

        $answer = $this->readInput();

        return $question->normalizeAnswer($answer);
    }

    private function askChoice(Input $input, Output $output, ChoiceQuestion $question): mixed
    {
        $choices = $question->getChoices();
        $attempts = $question->getMaxAttempts();
        $attemptCount = 0;

        // Seçenekleri göster
        $output->writeln($output->cyan($question->getQuestion()));

        $indexed = array_values($choices);
        foreach ($indexed as $i => $choice) {
            $output->writeln(sprintf(
                '  [%s] %s',
                $output->yellow((string) $i),
                $choice
            ));
        }

        $defaultText = '';
        if ($question->getDefault() !== null) {
            $defaultIndex = array_search($question->getDefault(), $indexed, true);
            if ($defaultIndex !== false) {
                $defaultText = " [{$defaultIndex}]";
            }
        }

        do {
            $output->write($output->gray($question->getSelectionPrompt()) . $defaultText . ': ');

            $answer = $this->readInput();

            try {
                return $question->normalizeAnswer($answer);
            } catch (InvalidArgumentException $e) {
                $output->error($e->getMessage());
                $attemptCount++;

                if ($attempts > 0 && $attemptCount >= $attempts) {
                    throw $e;
                }
            }
        } while ($attempts === 0 || $attemptCount < $attempts);

        return $question->getDefault();
    }

    private function formatPrompt(Output $output, Question $question): string
    {
        $prompt = $output->cyan($question->getQuestion());

        $default = $question->getDefault();
        if ($default !== null && $default !== '' && !$question->isHidden()) {
            $defaultStr = is_bool($default) ? ($default ? 'yes' : 'no') : (string) $default;
            $prompt .= $output->gray(" [{$defaultStr}]");
        }

        return $prompt . ': ';
    }

    private function readInput(): string
    {
        if (!defined('STDIN')) {
            return '';
        }

        $input = fgets(STDIN);

        return $input === false ? '' : trim($input);
    }

    private function readHiddenInputUnix(): string
    {
        if (!function_exists('shell_exec')) {
            return $this->readInput();
        }

        $sttyMode = shell_exec('stty -g');
        shell_exec('stty -echo');

        $value = $this->readInput();

        shell_exec(sprintf('stty %s', $sttyMode));

        echo PHP_EOL;

        return $value;
    }

    private function readHiddenInputWindows(): string
    {
        // PowerShell ile hidden input
        $command = 'powershell -Command "$password = Read-Host -AsSecureString; ' .
            '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
            '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($password))"';

        $value = shell_exec($command);

        if ($value !== null) {
            return trim($value);
        }

        // Fallback - normal input
        return $this->readInput();
    }
}
