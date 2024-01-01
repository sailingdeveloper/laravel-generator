<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\EnumColorEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class EnumChoiceDefinition extends Definition
{
    public function __construct(
        string $name,
        public ?string $value,
        public ?int $index,
        public ?EnumColorEnum $color,
    ) {
        parent::__construct($name);
    }
}
