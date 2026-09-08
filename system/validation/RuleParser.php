<?php

declare(strict_types=1);

namespace System\Validation;

class RuleParser
{
    public function parse(string $ruleString): array
    {
        $rules = [];

        foreach (explode('|', $ruleString) as $rule) {
            $rule = trim($rule);
            if ($rule !== '') {
                $rules[] = $this->parseRule($rule);
            }
        }

        return $rules;
    }


    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return ['name' => $rule, 'params' => []];
        }

        [$name, $paramStr] = explode(':', $rule, 2);

        return [
            'name'   => $name,
            'params' => str_contains($paramStr, ',') ? explode(',', $paramStr) : [$paramStr],
        ];
    }
}
