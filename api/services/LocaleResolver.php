<?php

namespace Api\Services;

use System\Http\Contract\LocaleProviderInterface;
use System\Http\Request;

class LocaleResolver implements LocaleProviderInterface
{
    public const SUPPORTED      = ['tr', 'en', 'az'];
    public const DEFAULT_LOCALE = 'tr';

    public function __construct(private readonly Request $request) {}

    /**
     * Her cagrida Accept-Language'i yeniden parse eder (cache yok).
     * RFC 7231: en-US,tr;q=0.9 -> primary tag + q-weight sort.
     */
    public function locale(): string
    {
        $header = $this->request->header('Accept-Language') ?? '';
        if ($header === '') {
            return self::DEFAULT_LOCALE;
        }

        $candidates = [];
        foreach (explode(',', $header) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $q   = 1.0;
            $tag = $entry;
            if (str_contains($entry, ';')) {
                [$tag, $params] = array_map('trim', explode(';', $entry, 2));
                if (preg_match('/q\s*=\s*([0-9.]+)/i', $params, $m)) {
                    $q = (float) $m[1];
                }
            }
            // primary tag: "en-US" -> "en"
            $primary = strtolower(explode('-', $tag, 2)[0]);
            if ($primary === '' || $primary === '*') {
                continue;
            }
            $candidates[] = ['locale' => $primary, 'q' => $q];
        }

        // q desc, stable order
        usort($candidates, fn($a, $b) => $b['q'] <=> $a['q']);

        foreach ($candidates as $c) {
            if (in_array($c['locale'], self::SUPPORTED, true)) {
                return $c['locale'];
            }
        }

        return self::DEFAULT_LOCALE;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }
}
