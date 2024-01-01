<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\MixinTypeEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230924
 */
class MixinDefinition extends Definition
{
    public function __construct(
        string $name,
        public MixinTypeEnum $type
    ) {
        parent::__construct($name);
    }
}
