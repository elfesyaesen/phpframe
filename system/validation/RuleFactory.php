<?php

declare(strict_types=1);

namespace System\Validation;

use Psr\Container\ContainerInterface;
use System\Engine\ContainerAwareInterface;
use System\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

final class RuleFactory
{
    private array $rules = [];


    public function __construct(private readonly ?ContainerInterface $container = null)
    {
        $this->rules = [
            'required'  => Rules\Required::class,
            'nullable'  => Rules\Nullable::class,
            'email'     => Rules\Email::class,
            'url'       => Rules\Url::class,
            'string'    => Rules\StringRule::class,
            'integer'   => Rules\Integer::class,
            'numeric'   => Rules\Numeric::class,
            'boolean'   => Rules\Boolean::class,
            'array'     => Rules\ArrayRule::class,
            'min'       => Rules\Min::class,
            'max'       => Rules\Max::class,
            'between'   => Rules\Between::class,
            'in'        => Rules\In::class,
            'not_in'    => Rules\NotIn::class,
            'regex'     => Rules\Regex::class,
            'confirmed' => Rules\Confirmed::class,
            'date'      => Rules\Date::class,
            'alpha'     => Rules\Alpha::class,
            'alpha_num' => Rules\AlphaNum::class,
            'unique'        => Rules\Unique::class,
            'unique_active' => Rules\UniqueActive::class,
            'password'  => Rules\Password::class,
            'uuid'      => Rules\Uuid::class,
            'ip'        => Rules\Ip::class,
            'file'      => Rules\File::class,
        ];
    }


    public function register(string $name, string $ruleClass): self
    {
        if (!is_subclass_of($ruleClass, RuleInterface::class)) {
            throw new InvalidArgumentException($ruleClass." RuleInterface’i implemente etmelidir.");
        }

        $this->rules[$name] = $ruleClass;

        return $this;
    }


    public function make(string $name): RuleInterface
    {
        if (!isset($this->rules[$name])) {
            throw new InvalidArgumentException($name . ": Belirtilen doğrulama kuralı tanımlı değil.");
        }

        $rule = new $this->rules[$name]();

        // Container'a ihtiyaç duyan kurallara (Unique/UniqueActive) enjekte et.
        if ($rule instanceof ContainerAwareInterface && $this->container !== null) {
            $rule->setContainer($this->container);
        }

        return $rule;
    }
}
