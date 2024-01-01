<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\MixinTypeEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230924
 */
class GeolocationMixinDefinition extends MixinDefinition
{
    public function __construct(
        string $name,
        MixinTypeEnum $type,
        public bool $shouldIncludeAddress
    ) {
        parent::__construct($name, $type);
    }
}
