<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use Exception;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class RelationMonomorphicDefinition extends RelationDefinition
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
        public ModelDefinition $counterModelDefinition,
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
        switch ($this->type) {
            case RelationTypeEnum::BELONGS_TO:
                if ($this->isRequired) {
                    return $this->counterModelDefinition->getClassName();
                } else {
                    return $this->counterModelDefinition->getClassName() . '|null';
                }

            case RelationTypeEnum::HAS_MANY:
                return 'Collection<int, ' . $this->counterModelDefinition->name . 'Model>';

            default:
                throw new Exception(sprintf('Unknown relation type "%s"', $this->type->value));
        }
    }
}
