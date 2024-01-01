<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use Illuminate\Support\Collection;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20231005
 */
class RelationPolymorphicDefinition extends RelationDefinition
{
    public function __construct(
        string $name,
        string $propertyName,
        ModelDefinition $modelDefinition,
        RelationTypeEnum $type,
        bool $shouldCreateCounterRelationDefinition,
        bool $shouldEagerLoad,
        bool $isEvent,
        bool $isRequired,
        bool $isComputed,
        string $index,
        RequestDefinition $requestDefinition,
        NovaDefinition $novaPropertyDefinition,
        public Collection $allCounterModelDefinition,
    ) {
        parent::__construct(
            name: $name,
            propertyName: $propertyName,
            modelDefinition: $modelDefinition,
            type: $type,
            shouldCreateCounterRelationDefinition: $shouldCreateCounterRelationDefinition,
            shouldEagerLoad: $shouldEagerLoad,
            isEvent: $isEvent,
            isRequired: $isRequired,
            isComputed: $isComputed,
            index: $index,
            requestDefinition: $requestDefinition,
            novaPropertyDefinition: $novaPropertyDefinition,
        );
    }

    public function toPhpDocType(): string
    {
        return collect($this->allCounterModelDefinition)
            ->map(fn (ModelDefinition $modelDefinition) => $modelDefinition->getClassName())
            ->unless($this->isRequired, fn (Collection $collection) => $collection->add('null'))
            ->join('|');
    }

    public function toParameterType(): string
    {
        return collect($this->allCounterModelDefinition)
            ->map(fn (ModelDefinition $modelDefinition) => $modelDefinition->getFullClassName())
            ->unless($this->isRequired, fn (Collection $collection) => $collection->add('null'))
            ->join('|');
    }
}
