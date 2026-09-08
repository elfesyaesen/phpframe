<?php

declare(strict_types=1);

namespace System\Validation;

use System\Translation\Contract\TranslatorInterface;
use System\Exceptions\ValidationException;
use System\Validation\Contracts\RuleInterface;
use System\Validation\Contracts\ParameterizedRuleInterface;
use System\Validation\Contracts\DataAwareRuleInterface;

final class Validator
{
    private array $errors = [];
    private array $validated = [];

    /**
     * `TranslatorInterface` — somut `Api\Services\Translator` DEĞİL.
     *
     * Eskiden burada `use Api\Services\Translator;` vardı: framework
     * çekirdeği bir UYGULAMA sınıfına bağlıydı. Bu, `ApiProvider`'ın kendi
     * docblock'unda yazılı olan "`System\` asla `Api\`'yi bilmez" kuralının
     * doğrudan ihlaliydi ve `api/`'yi ayrı bir modül olarak çıkarmayı
     * imkânsız kılıyordu.
     *
     * Kullanılan yüzey zaten yalnızca `trans()` — arayüz tam karşılıyor.
     */
    public function __construct(
        private readonly DataAccessor $accessor,
        private readonly RuleParser $parser,
        private readonly RuleFactory $factory,
        private readonly ?TranslatorInterface $translator = null
    ) {}

    public function validate(array $data, array $rules): array
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($rules as $field => $ruleString) {
            if (str_contains($field, '*')) {
                $this->validateWildcard($data, $field, $ruleString);
            } else {
                $this->validateField($data, $field, $ruleString);
            }
        }

        if (!empty($this->errors)) {
            $title = $this->translator !== null
                ? $this->translator->trans('validation.failed')
                : 'Doğrulama işlemi başarısız';
            throw new ValidationException($this->errors, $title);
        }

        return $this->validated;
    }

    public function extend(string $name, string $ruleClass): self
    {
        $this->factory->register($name, $ruleClass);
        return $this;
    }

    private function validateWildcard(array $data, string $field, string $ruleString): void
    {
        foreach ($this->resolveWildcardPaths($data, $field) as $resolvedField) {
            $this->validateField($data, $resolvedField, $ruleString);
        }
    }

    /**
     * Wildcard field path'ini gerçek indekslerle genişletir.
     *
     * Örn: "records.*.timestamp" → ["records.0.timestamp", "records.1.timestamp", ...]
     * Nested wildcard destekler: "data.*.items.*.id"
     *
     * @return array<string>
     */
    private function resolveWildcardPaths(array $data, string $pattern): array
    {
        $segments = explode('.', $pattern);

        return $this->expandSegments($data, $segments, 0, '');
    }

    /**
     * @return array<string>
     */
    private function expandSegments(array $data, array $segments, int $index, string $prefix): array
    {
        if ($index >= count($segments)) {
            return [ltrim($prefix, '.')];
        }

        $segment = $segments[$index];

        if ($segment !== '*') {
            return $this->expandSegments($data, $segments, $index + 1, $prefix . '.' . $segment);
        }

        $parent = $prefix !== '' ? $this->accessor->get($data, ltrim($prefix, '.')) : $data;

        if (!is_array($parent)) {
            return [];
        }

        $paths = [];
        foreach (array_keys($parent) as $key) {
            $paths = [...$paths, ...$this->expandSegments($data, $segments, $index + 1, $prefix . '.' . $key)];
        }

        return $paths;
    }

    private function validateField(array $data, string $field, string $ruleString): void
    {
        $value = $this->accessor->get($data, $field);
        $parsedRules = $this->parser->parse($ruleString);

        if ($this->shouldSkip($parsedRules, $value)) {
            return;
        }

        foreach ($parsedRules as $ruleData) {
            if ($ruleData['name'] === 'nullable') {
                continue;
            }

            $rule = $this->factory->make($ruleData['name']);
            $this->configureRule($rule, $ruleData['params'], $field, $data);

            if (!$rule->passes($value)) {
                $this->errors[$field][] = $this->renderMessage($rule, $field);
                break;
            }
        }

        if (!isset($this->errors[$field])) {
            $this->validated = $this->accessor->set($this->validated, $field, $value);
        }
    }

    private function shouldSkip(array $rules, mixed $value): bool
    {
        foreach ($rules as $rule) {
            if ($rule['name'] === 'nullable') {
                return $value === null || $value === '' || $value === [];
            }
        }
        return false;
    }

    private function configureRule(RuleInterface $rule, array $params, string $field, array $data): void
    {
        if ($rule instanceof ParameterizedRuleInterface) {
            $rule->setParameters($params);
        }
        if ($rule instanceof DataAwareRuleInterface) {
            $rule->setData($data)->setField($field);
        }
    }

    /**
     * Translator varsa messageKey/messageParams ile cevirir.
     * Yoksa legacy message() metoduna duser (BC).
     */
    private function renderMessage(RuleInterface $rule, string $field): string
    {
        if ($this->translator !== null && method_exists($rule, 'messageKey')) {
            $key = $rule->messageKey();
            $params = method_exists($rule, 'messageParams') ? $rule->messageParams() : [];
            $params['field'] = $field;
            return $this->translator->trans($key, $params);
        }
        return str_replace(':field', $field, $rule->message());
    }
}
