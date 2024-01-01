<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230511
 */
class JsonPropertyDefinition extends PropertyDefinition
{
    /**
     * @param array<int, string> $rules
     */
    public function __construct(
        string $name,
        ModelDefinition $modelDefinition,
        PropertyTypeEnum $type,
        string $label,
        bool $isRequired,
        bool $isComputed,
        array $rules,
        RequestDefinition $requestDefinition,
        NovaDefinition $novaPropertyDefinition,
        ?string $index,
        public string $initial,
        bool $isInherited = false,
    ) {
        parent::__construct(
            $name,
            $modelDefinition,
            $type,
            $label,
            $isRequired,
            $isComputed,
            $rules,
            $requestDefinition,
            $novaPropertyDefinition,
            $index,
            $isInherited,
        );
    }
}
