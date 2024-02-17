<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\BelongsTo as NovaBelongsTo;
use Laravel\Nova\Fields\HasMany as NovaHasMany;
use Laravel\Nova\Fields\MorphTo as NovaMorphTo;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
abstract class RelationDefinition extends Definition
{
    public function __construct(
        string $name,
        public string $propertyName,
        public ModelDefinition $modelDefinition,
        public RelationTypeEnum $type,
        public bool $shouldCreateCounterRelationDefinition,
        public bool $shouldEagerLoad,
        public bool $isEvent,
        public bool $isRequired,
        public bool $isComputed,
        public string $index,
        public RequestDefinition $requestDefinition,
        public NovaDefinition $novaPropertyDefinition,
    ) {
        parent::__construct($name);
    }

    public function toNovaType(): string
    {
        return match ($this->type) {
            RelationTypeEnum::BELONGS_TO => NovaBelongsTo::class,
            RelationTypeEnum::HAS_MANY => NovaHasMany::class,
            RelationTypeEnum::POLYMORPHIC => NovaMorphTo::class,
        };
    }

    public function toRelationType(): string
    {
        return match ($this->type) {
            RelationTypeEnum::BELONGS_TO => BelongsTo::class,
            RelationTypeEnum::HAS_MANY => HasMany::class,
            RelationTypeEnum::POLYMORPHIC => MorphTo::class,
        };
    }

    public function generateAllForeignKeyProperty(): PropertyCollection
    {
        return match ($this->type) {
            RelationTypeEnum::BELONGS_TO => new PropertyCollection(
                [
                    new PropertyDefinition(
                        name: $this->name . '_id',
                        modelDefinition: $this->modelDefinition,
                        type: PropertyTypeEnum::ID,
                        label: Str::of($this->name)->title()->append(' ID'),
                        isRequired: $this->isRequired,
                        isComputed: false,
                        rules: [],
                        requestDefinition: new RequestDefinition(
                            name: $this->name . '_id',
                            isRequired: $this->isRequired,
                            getStatus: RequestStatusEnum::EXCLUDE,
                            createStatus: $this->requestDefinition->createStatus,
                            updateStatus: $this->requestDefinition->updateStatus,
                        ),
                        novaPropertyDefinition: new NovaDefinition(
                            name: $this->name . '_id',
                            shouldShowOnIndex: false,
                        ),
                        index: $this->index,
                        isInherited: false,
                        fromRelation: $this,
                    ),
                ],
            ),
            RelationTypeEnum::HAS_MANY => new PropertyCollection(),
            RelationTypeEnum::POLYMORPHIC => new PropertyCollection(
                [
                    new PropertyDefinition(
                        name: $this->name . '_type',
                        modelDefinition: $this->modelDefinition,
                        type: PropertyTypeEnum::STRING,
                        label: Str::of($this->name)->title()->append(' Type'),
                        isRequired: $this->isRequired,
                        isComputed: false,
                        rules: [],
                        requestDefinition: new RequestDefinition(
                            name: $this->name . '_type',
                            isRequired: $this->isRequired,
                            getStatus: RequestStatusEnum::EXCLUDE,
                            createStatus: $this->requestDefinition->createStatus,
                            updateStatus: $this->requestDefinition->updateStatus,
                        ),
                        novaPropertyDefinition: new NovaDefinition(
                            name: $this->name . '_type',
                            shouldShowOnIndex: false,
                        ),
                        index: $this->index,
                        isInherited: false,
                        fromRelation: $this,
                    ),
                    new PropertyDefinition(
                        name: $this->name . '_id',
                        modelDefinition: $this->modelDefinition,
                        type: PropertyTypeEnum::ID,
                        label: Str::of($this->name)->title()->append(' ID'),
                        isRequired: $this->isRequired,
                        isComputed: false,
                        rules: [],
                        requestDefinition: new RequestDefinition(
                            name: $this->name . '_id',
                            isRequired: $this->isRequired,
                            getStatus: RequestStatusEnum::EXCLUDE,
                            createStatus: $this->requestDefinition->createStatus,
                            updateStatus: $this->requestDefinition->updateStatus,
                        ),
                        novaPropertyDefinition: new NovaDefinition(
                            name: $this->name . '_id',
                            shouldShowOnIndex: false,
                        ),
                        index: $this->index,
                        isInherited: false,
                        fromRelation: $this,
                    ),
                ]
            ),
        };
    }

    public function generateCounterRelationOrNull(): ?RelationDefinition
    {
        if ($this->shouldCreateCounterRelationDefinition) {
            switch ($this->type) {
                case RelationTypeEnum::BELONGS_TO:
                    $name = Str::of($this->modelDefinition->name)
                        ->replace($this->counterModelDefinition->name, '')
                        ->snake()
                        ->plural()
                        ->toString();

                    return new RelationMonomorphicDefinition(
                        name: $name,
                        propertyName: Str::of($this->name)->snake()->append('_id')->toString(),
                        modelDefinition: $this->counterModelDefinition,
                        type: RelationTypeEnum::HAS_MANY,
                        shouldCreateCounterRelationDefinition: false,
                        shouldEagerLoad: false,
                        isEvent: false,
                        isRequired: true,
                        isComputed: false,
                        index: $name,
                        requestDefinition: new RequestDefinition(
                            name: $name,
                            isRequired: false,
                            getStatus: RequestStatusEnum::EXCLUDE,
                            createStatus: RequestStatusEnum::EXCLUDE,
                            updateStatus: RequestStatusEnum::EXCLUDE,
                        ),
                        novaPropertyDefinition: new NovaDefinition(
                            name: $name,
                            shouldShowOnIndex: false,
                            shouldShowOnDetail: false,
                            shouldShowWhenCreating: false,
                            shouldShowWhenUpdating: false,
                        ),
                        counterModelDefinition: $this->modelDefinition,
                    );
                case RelationTypeEnum::HAS_MANY:
                case RelationTypeEnum::POLYMORPHIC:
                    return null;
                default:
                    throw new Exception(sprintf('Unknown relation type "%s"', $this->type->value));
            }
        } else {
            return null;
        }
    }
}
