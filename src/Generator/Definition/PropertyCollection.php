<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use Illuminate\Support\Collection;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 *
 * @extends Collection<string, PropertyDefinition>
 */
class PropertyCollection extends Collection
{
    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getInherited(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isInherited);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonInherited(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isInherited === false);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getRelation(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->fromRelation instanceof RelationDefinition);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonRelation(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->fromRelation === null);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonComputed(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isComputed === false);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonAppendedInResource(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isAppendedInResource === false);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonMedia(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isMedia() === false);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getMedia(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isMedia());
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getRequired(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isRequired);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getNonRequired(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isRequired === false);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllInGetRequest(): PropertyCollection
    {
        return $this
            ->filter(fn (PropertyDefinition $property) => in_array(
                $property->modelDefinition->requestDefinition->getStatus,
                [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
            ))
            ->filter(fn (PropertyDefinition $property) => in_array(
                $property->requestDefinition->getStatus,
                [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
            ));
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllInGetRequestWithoutConditional(): PropertyCollection
    {
        return $this
            ->filter(fn (PropertyDefinition $property) => $property->modelDefinition->requestDefinition->getStatus === RequestStatusEnum::INCLUDE)
            ->filter(fn (PropertyDefinition $property) => $property->requestDefinition->getStatus === RequestStatusEnum::INCLUDE);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllInGetRequestConditional(): PropertyCollection
    {
        return $this
            ->filter(fn (PropertyDefinition $property) => $property->requestDefinition->getStatus === RequestStatusEnum::INCLUDE_CONDITIONALLY);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllInCreateRequest(): PropertyCollection
    {
        return $this
            ->filter(fn (PropertyDefinition $property) => in_array(
                $property->modelDefinition->requestDefinition->createStatus,
                [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
            ))
            ->filter(fn (PropertyDefinition $property) => $property->isComputed === false)
            ->filter(fn (PropertyDefinition $property) => $property->isAppendedInResource === false)
            ->filter(
                fn (PropertyDefinition $property) => in_array(
                    $property->requestDefinition->createStatus,
                    [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
                ),
            );
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllInUpdateRequest(): PropertyCollection
    {
        return $this
            ->filter(fn (PropertyDefinition $property) => in_array(
                $property->modelDefinition->requestDefinition->updateStatus,
                [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
            ))
            ->filter(fn (PropertyDefinition $property) => $property->isComputed === false)
            ->filter(fn (PropertyDefinition $property) => $property->isAppendedInResource === false)
            ->filter(
                fn (PropertyDefinition $property) => in_array(
                    $property->requestDefinition->updateStatus,
                    [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
                ),
            );
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getSearchable(): PropertyCollection
    {
        return $this
            ->getNonRelation()
            ->getNonComputed()
            ->filter(
                fn (PropertyDefinition $property): bool => match ($property->type) {
                    PropertyTypeEnum::ID,
                    PropertyTypeEnum::ULID,
                    PropertyTypeEnum::STRING,
                    PropertyTypeEnum::ENUM => true,

                    PropertyTypeEnum::TEXT,
                    PropertyTypeEnum::TIMESTAMP,
                    PropertyTypeEnum::INTEGER,
                    PropertyTypeEnum::JSON_OBJECT,
                    PropertyTypeEnum::JSON_ARRAY,
                    PropertyTypeEnum::GEOLOCATION,
                    PropertyTypeEnum::POINT,
                    PropertyTypeEnum::FILE,
                    PropertyTypeEnum::FILE_COLLECTION,
                    PropertyTypeEnum::IMAGE,
                    PropertyTypeEnum::IMAGE_COLLECTION,
                    PropertyTypeEnum::VIDEO,
                    PropertyTypeEnum::VIDEO_COLLECTION,
                    PropertyTypeEnum::ADDRESS,
                    PropertyTypeEnum::MONEY_AMOUNT, => false,
                },
            )
            ->filter(fn (PropertyDefinition $property): bool => $property->index !== null);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getAllAppendedInResource(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => $property->isAppendedInResource);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getIndexed(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property): bool => $property->index !== null);
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getEnums(): Collection
    {
        return $this->filter(
            fn (PropertyDefinition $propertyDefinition) => $propertyDefinition instanceof EnumPropertyDefinition,
        );
    }

    /**
     * @return Collection<string, EnumDefinition>
     */
    public function generateEnumDefinitions(): Collection
    {
        return $this->filter(
            fn (PropertyDefinition $propertyDefinition,
            ) => $propertyDefinition instanceof EnumPropertyDefinition && $propertyDefinition->choices,
        )
            ->toBase()
            // @phpstan-ignore-next-line
            ->map(fn (EnumPropertyDefinition $enumPropertyDefinition): EnumDefinition => $enumPropertyDefinition->generateEnumDefinition());
    }

    /**
     * @return PropertyCollection<JsonPropertyDefinition>
     */
    public function getAllJson(): PropertyCollection
    {
        // @phpstan-ignore-next-line
        return $this->filter(
            fn (PropertyDefinition $property) => in_array($property->type, [PropertyTypeEnum::JSON_OBJECT, PropertyTypeEnum::JSON_ARRAY]),
        );
    }

    /**
     * @return PropertyCollection<PropertyDefinition>
     */
    public function getIdentifiers(): PropertyCollection
    {
        return $this->filter(fn (PropertyDefinition $property) => in_array($property->name, ['id', 'ulid']));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function determineIndexPairs(): array
    {
        return $this->reduce(
            function (array $carry, PropertyDefinition $propertyDefinition): array {
                if ($propertyDefinition->index) {
                    $carry[$propertyDefinition->index] = [
                        ...($carry[$propertyDefinition->index] ?? []),
                        $propertyDefinition->name,
                    ];
                }

                return $carry;
            },
            initial: [],
        );
    }

    public function order(): self
    {
        return $this->sortBy(
            function (PropertyDefinition $propertyDefinition): int {
                if ($propertyDefinition->isInherited) {
                    return 0;
                } elseif ($propertyDefinition->fromRelation instanceof RelationDefinition) {
                    return 1;
                } else {
                    return 2;
                }
            },
        );
    }

    public function get($key, $default = null)
    {
        return $this->firstWhere('name', $key) ?: parent::get($key, $default);
    }

    public function exists(mixed $name): bool
    {
        return $this->firstWhere('name', $name) !== null;
    }
}
