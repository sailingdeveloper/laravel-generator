<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230827
 */
class MediaPropertyDefinition extends PropertyDefinition
{
    public function __construct(
        string $name,
        ModelDefinition $modelDefinition,
        PropertyTypeEnum $type,
        string $label,
        bool $isRequired,
        bool $isComputed,
        bool $isAppendedInResource,
        array $rules,
        RequestDefinition $requestDefinition,
        NovaDefinition $novaPropertyDefinition,
        ?string $index,
        public bool $asynchronousUpload,
        bool $isInherited = false,
        ?RelationDefinition $fromRelation = null,
    ) {
        parent::__construct(
            $name,
            $modelDefinition,
            $type,
            $label,
            $isRequired,
            $isComputed,
            $isAppendedInResource,
            $rules,
            $requestDefinition,
            $novaPropertyDefinition,
            $index,
            $isInherited,
            $fromRelation,
        );
    }
}
