<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Container\Compilation\CodeWriter;
use System\Container\Definition\Definition;

interface CompilableFactoryInterface
{
    public static function compile(CodeWriter $writer, Definition $definition): string;
}
