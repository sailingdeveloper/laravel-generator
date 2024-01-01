<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use Exception;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class ModelDefinition extends Definition
{
    public PropertyCollection $properties;

    public RelationCollection $relations;

    public function __construct(
        string $name,
        public PhpNamespace $namespace,
        public TableDefinition $table,
        public bool $hasObserver,
        public array $titleAttributes,
        public MixinCollection $mixins,
        public RequestDefinition $requestDefinition,
        public ?string $ulidPrefix,
        public array $originalDefinition,
    ) {
        parent::__construct($name);

        $this->relations = new RelationCollection();
    }

    public function setProperties(PropertyCollection $properties): void
    {
        $this->properties = $properties;
    }

    public function addProperty(PropertyDefinition $property): void
    {
        $this->properties->add($property);
    }

    public function addPropertyAfter(string $after, PropertyDefinition $property): void
    {
        $index = $this->properties->search(fn (PropertyDefinition $item) => $item->name === $after);

        if ($index === false) {
            $this->properties->add($property);
        } else {
            $this->properties = $this->properties->slice(0, $index + 1)
                ->merge([$property])
                ->merge($this->properties->slice($index + 1));
        }
    }

    public function addRelationWithOverride(RelationDefinition $relation): void
    {
        $this->relations[$relation->name] = $relation;
    }

    public function addRelation(RelationDefinition $relation): void
    {
        if ($this->relations->has($relation->name)) {
            throw new Exception(sprintf('[%s] Relation already exists: %s', $this->name, $relation->name));
        } else {
            $this->relations[$relation->name] = $relation;
        }
    }

    public function addRelationIfNotExists(RelationDefinition $relation): void
    {
        if ($this->relations->has($relation->name)) {
            // Relation already defined.
        } else {
            $this->relations[$relation->name] = $relation;
        }
    }

    public function getClassName(): string
    {
        return $this->name . 'Model';
    }

    public function getFullClassName(): string
    {
        return $this->namespace->getName() . '\\Model\\' . $this->name . 'Model';
    }

    public function getFullBaseClassName(): string
    {
        return $this->namespace->getName() . '\\Model\\Generated\\' . $this->name . 'ModelBase';
    }

    public function getNovaFullClassName(): string
    {
        return Str::of($this->namespace->getName())
            ->explode('\\')
            ->slice(0)
            ->add('Nova')
            ->add($this->name . 'Nova')
            ->join('\\');
    }

    public function getObserverFullClassName(): string
    {
        return Str::of($this->namespace->getName())
            ->explode('\\')
            ->slice(0)
            ->add('Observer')
            ->add($this->name . 'Observer')
            ->join('\\');
    }

    public function getResourceFullClassName(): string
    {
        return Str::of($this->namespace->getName())
            ->explode('\\')
            ->slice(0)
            ->add('Resource')
            ->add($this->name . 'Resource')
            ->join('\\');
    }

    public function getQueryFullClassName(): string
    {
        return Str::of($this->namespace->getName())
            ->explode('\\')
            ->slice(0)
            ->add('Query')
            ->add($this->name . 'Query')
            ->join('\\');
    }

    public function getEventClassName(): string
    {
        return $this->name . 'Event';
    }

    public function getEventFullClassName(): string
    {
        return Str::of($this->namespace->getName())
            ->explode('\\')
            ->slice(0)
            ->add('Event')
            ->add($this->getEventClassName())
            ->join('\\');
    }

    public function hasEvent(): bool
    {
        return $this->relations->firstWhere('isEvent', true);
    }
}
