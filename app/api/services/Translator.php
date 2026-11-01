<?php

declare(strict_types=1);

namespace App\Api\Services;

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;
use System\Config\AppConfig;
use System\Translation\Contract\TranslatorInterface;

final class Translator implements TranslatorInterface
{
    private const DOMAIN = 'messages+intl-icu';

    private ?SymfonyTranslator $translator = null;

    private ?string $locale = null;

    private readonly string $bundlePath;

    public function __construct(
        private readonly LocaleResolver $resolver,
        AppConfig $config,
    ) {
        $this->bundlePath = $config->langPath();
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator()->trans($key, $params, self::DOMAIN, $this->locale());
    }

    private function locale(): string
    {
        return $this->locale ??= $this->resolver->locale();
    }

    private function translator(): SymfonyTranslator
    {
        if ($this->translator !== null) {
            return $this->translator;
        }

        $translator = new SymfonyTranslator($this->locale());
        $translator->addLoader('php', new PhpFileLoader());

        $translator->setFallbackLocales([LocaleResolver::DEFAULT_LOCALE]);

        foreach (LocaleResolver::SUPPORTED as $locale) {
            $file = $this->bundlePath . '/' . $locale . '.php';

            if (is_file($file)) {
                $translator->addResource('php', $file, $locale, self::DOMAIN);
            }
        }

        return $this->translator = $translator;
    }
}
