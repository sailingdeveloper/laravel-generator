<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use Illuminate\Support\Collection;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 *
 * @extends Collection<string, RelationDefinition>
 */
class RelationCollection extends Collection
{
    /**
     * @return RelationCollection<RelationDefinition>
     */
    public function getRequired(): RelationCollection
    {
        return $this->filter(fn (RelationDefinition $relation) => $relation->isRequired);
    }

    /**
     * @return RelationCollection<RelationDefinition>
     */
    public function getNonRequired(): RelationCollection
    {
        return $this->filter(fn (RelationDefinition $relation) => $relation->isRequired === false);
    }

    /**
     * @return RelationCollection<RelationDefinition>
     */
    public function getAllInGetRequest(): RelationCollection
    {
        return $this->filter(
            fn (RelationDefinition $relation) => in_array(
                $relation->requestDefinition->getStatus,
                [RequestStatusEnum::INCLUDE, RequestStatusEnum::INCLUDE_CONDITIONALLY],
            ),
        );
    }

    public function getEagerLoadable(): RelationCollection
    {
        return $this->where('shouldEagerLoad');
    }

    public function getWithEventOrNull(): ?RelationDefinition
    {
        return $this->first(fn (RelationDefinition $relation) => $relation->isEvent);
    }
}
