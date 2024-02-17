<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use App\Event\Enum\Generated\EventActionEnum;
use App\Event\Lib\EventLib;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\MixinTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\JsonPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MixinDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelFileDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationPolymorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\Geolocation\Interface\ModelGeolocationInterface;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpNamespace;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class GeneratorModel extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateModelIfNeeded(): void
    {
        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Model')
            ->add("{$this->definition->name}Model.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace($this->definition->namespace->getName() . '\\Model');
        $class = $namespace->addClass($this->definition->name . 'Model');
        $namespace->addUse($this->definition->getFullBaseClassName());
        $class->setExtends($this->definition->getFullBaseClassName());
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateModelBase(): void
    {
        $namespace = new PhpNamespace($this->definition->namespace->getName() . '\Model\Generated');
        $class = $namespace->addClass($this->definition->name . 'ModelBase')
            ->setAbstract();

        $namespace->addUse(config('laravel-generator.base_model_class'));
        $namespace->addUse($this->definition->getFullClassName());

        $namespace->addUse($this->definition->getQueryFullClassName());
        $this->addClassHeaderGenerator($class);
        $class->addComment('@method static ' . $this->simplifyPhpDocType($namespace, $this->definition->getQueryFullClassName()) . ' query()');

        if ($this->definition->table->shouldLogActivity) {
            $namespace->addUse(LogsActivity::class);
            $namespace->addUse(LogOptions::class);

            $class->addTrait(LogsActivity::class);

            $class->addMethod('getActivitylogOptions')
                ->setReturnType(LogOptions::class)
                ->setBody('return LogOptions::defaults()->logAll();');
        }

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties as $property) {
            $class->addConstant(Str::of($property->name)->snake()->prepend('PROPERTY_')->upper(), $property->name);

            if ($property instanceof EnumPropertyDefinition) {
                $namespace->addUse($property->generateFullEnumName());
            }

            if ($property->isComputed) {
                $this->generatePropertyComputed($namespace, $class, $property);
            }

            if ($property->isMedia()) {
                $this->generatePropertyMedia($namespace, $class, $property);
            }
        }

        /** @var RelationDefinition $relation */
        foreach ($this->definition->relations as $relation) {
            $class->addConstant(Str::of($relation->name)->snake()->prepend('RELATION_')->upper(), $relation->name);

            if ($relation instanceof RelationMonomorphicDefinition) {
                $namespace->addUse($relation->counterModelDefinition->getFullClassName());
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                /** @var ModelDefinition $counterModelDefinition */
                foreach ($relation->allCounterModelDefinition as $counterModelDefinition) {
                    $namespace->addUse($counterModelDefinition->getFullClassName());
                }
            }
        }

        $class->setExtends(config('laravel-generator.base_model_class'));
        $this->addAllInterface($namespace, $class);
        $this->addAllTrait($namespace, $class);

        $this->generateRelationAnnotations($namespace, $class);
        $this->generatePropertyAnnotations($namespace, $class);

        $class->addProperty('table', $this->definition->table->name)
            ->setProtected();

        $class->addProperty('with', $this->definition->relations->getEagerLoadable()->pluck('name')->toArray())
            ->setProtected();

        $this->addCastsProperty($namespace, $class);

        $class->addMethod('getForeignKey')
            ->setReturnType('string')
            ->setBody(sprintf("return '%s_id';", Str::snake($this->definition->name)));

        $class->addMethod('getModelName')
            ->setReturnType('string')
            ->setBody("return '{$this->definition->name}';");

        $this->generateNewMethod($namespace, $class);

        $this->generateRelations($namespace, $class);

        if ($eventRelation = $this->definition->relations->getWithEventOrNull()) {
            $namespace->addUse(EventActionEnum::class);
            $namespace->addUse($this->definition->getEventFullClassName());

            $method = $class->addMethod('getEvent');
            $method->setReturnType($this->definition->getEventFullClassName());
            $method->addParameter('action')
                ->setType(EventActionEnum::class);

            $body = 'return new ' . $this->definition->getEventClassName() . '(' . PHP_EOL;
            $body .= $this->indent(1) . 'model: $this,' . PHP_EOL;
            $body .= $this->indent(1) . 'action: $action,' . PHP_EOL;
            $body .= $this->indent(1) . "owner: \$this->{$eventRelation->name}," . PHP_EOL;
            $body .= ');';

            $method->setBody($body);
        }

        $this->addBootMethod($namespace, $class);

        $class->addMethod('newEloquentBuilder')
            ->setReturnType($this->definition->getQueryFullClassName())
            ->setBody(sprintf('return new %sQuery($query);', $this->definition->name))
            ->addParameter('query');

        if ($this->definition->properties->getMedia()->isNotEmpty()) {
            $namespace->addUse(HasMedia::class);
            $namespace->addUse(InteractsWithMedia::class);
            $namespace->addUse(Manipulations::class);

            $class->addImplement(HasMedia::class);
            $class->addTrait(InteractsWithMedia::class);

            $body = '';

            /** @var ModelFileDefinition $file */
            foreach ($this->definition->properties->getMedia() as $file) {
                $body .= PHP_EOL . sprintf("\$this->addMediaCollection('%s')", $file->name);

                $body .= match ($file->type) {
                    PropertyTypeEnum::FILE,
                    PropertyTypeEnum::IMAGE,
                    PropertyTypeEnum::VIDEO => PHP_EOL . $this->indent(1) . '->singleFile()',
                    PropertyTypeEnum::FILE_COLLECTION,
                    PropertyTypeEnum::IMAGE_COLLECTION,
                    PropertyTypeEnum::VIDEO_COLLECTION => null,
                };

                $body .= ';' . PHP_EOL;
            }

            $class->addMethod('registerMediaCollections')
                ->setReturnType('void')
                ->setBody($body);

            $body = "\$this->addMediaConversion('thumbnail')" . PHP_EOL;
            $body .= $this->indent(1) . '->fit(Manipulations::FIT_CROP, 100, 100);' . PHP_EOL;
            $body .= PHP_EOL;
            $body .= "\$this->addMediaConversion('preview')" . PHP_EOL;
            $body .= $this->indent(1) . '->fit(Manipulations::FIT_MAX, 1024, 1024);' . PHP_EOL;

            $method = $class->addMethod('registerMediaConversions');
            $method->setReturnType('void');
            $method->addParameter('media')
                ->setType(Media::class)
                ->setDefaultValue(null);
            $method->setBody($body);
        }

        if (config('laravel-generator.ulid_prefix')) {
            $namespace->addUse(Str::class);
            $class->addMethod('determineUlidPrefix')
                ->setStatic()
                ->setReturnType('string')
                ->setBody(sprintf("return '%s_';", $this->definition->ulidPrefix));
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Model')
            ->add('Generated')
            ->add("{$this->definition->name}ModelBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function generatePropertyAnnotations(PhpNamespace $namespace, ClassType $class): void
    {
        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getNonInherited() as $property) {
            if (str_contains($property->toPhpDocType(), '\\')) {
                $namespace->addUse($property->toPhpDocType());
            }

            $class->addComment("@property {$this->generatePhpDocTypeIncludingNullableIfNeeded($namespace, $property)} \${$property->name}");
        }
    }

    private function generateRelationAnnotations(PhpNamespace $namespace, ClassType $class): void
    {
        /** @var RelationDefinition $relation */
        foreach ($this->definition->relations as $relation) {
            if ($relation->type === RelationTypeEnum::BELONGS_TO) {
                $namespace->addUse(Collection::class);
            }

            $class->addComment("@property {$relation->toPhpDocType()} \${$relation->name}");
        }
    }

    private function addCastsProperty(PhpNamespace $namespace, ClassType $class): void
    {
        $body = '[' . PHP_EOL;

        foreach ($this->definition->properties->getNonMedia() as $property) {
            $body .= $this->indent(1) . "'{$property->name}' => {$this->simplifyType($namespace, $property->toCastType())}," . PHP_EOL;
        }

        $body .= ']';

        $class->addProperty('casts')
            ->setProtected()
            ->setValue(new Literal($body));
    }

    private function generateNewMethod(PhpNamespace $namespace, ClassType $class): void
    {
        $allRelationRequired = $this->definition->relations
            ->getRequired()
            ->whereIn('type', [RelationTypeEnum::BELONGS_TO, RelationTypeEnum::POLYMORPHIC]);
        $allRelationNonRequired = $this->definition->relations
            ->getNonRequired()
            ->whereIn('type', [RelationTypeEnum::BELONGS_TO, RelationTypeEnum::POLYMORPHIC]);

        $allPropertyRequired = $this->definition->properties
            ->getNonInherited()
            ->getNonComputed()
            ->getNonRelation()
            ->getNonMedia()
            ->getRequired();
        $allPropertyNonRequired = $this->definition->properties
            ->getNonInherited()
            ->getNonComputed()
            ->getNonRelation()
            ->getNonMedia()
            ->getNonRequired();

        $allRequired = $allRelationRequired->merge($allPropertyRequired);

        if ($allRequired->isEmpty()) {
            return;
        }

        $method = $class->addMethod('create');
        $method->setStatic();
        $method->setReturnType($this->definition->getFullClassName());

        /** @var RelationDefinition $relation */
        foreach ($allRelationRequired as $relation) {
            if ($relation instanceof RelationMonomorphicDefinition) {
                $method->addParameter($relation->name)
                    ->setType($relation->counterModelDefinition->getFullClassName());
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                $method->addParameter($relation->name)->setType($relation->toParameterType());
            } else {
                throw new Exception(sprintf('Unknown relation type "%s".', $relation->type));
            }
        }

        /** @var PropertyDefinition $property */
        foreach ($allPropertyRequired as $property) {
            $method->addParameter($property->name)
                ->setType($property->toPhpDocType());
        }

        /** @var RelationDefinition $relation */
        foreach ($allRelationNonRequired as $relation) {
            if ($relation instanceof RelationMonomorphicDefinition) {
                $method->addParameter($relation->name)
                    ->setType($relation->counterModelDefinition->getFullClassName())
                    ->setDefaultValue(null)
                    ->setNullable();
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                $method->addParameter($relation->name)->setType($relation->toParameterType())
                    ->setDefaultValue(null)
                    ->setNullable();
            } else {
                throw new Exception(sprintf('Unknown relation type "%s".', $relation->type));
            }
        }

        /** @var PropertyDefinition $property */
        foreach ($allPropertyNonRequired as $property) {
            $method->addParameter($property->name)
                ->setType($property->toPhpDocType())
                ->setDefaultValue(null)
                ->setNullable();
        }

        $modelVariable = Str::camel($this->definition->name);
        $body = "\${$modelVariable} = static::query()->create([" . PHP_EOL;

        /** @var RelationDefinition $relation */
        foreach ($allRelationRequired as $relation) {
            if ($relation instanceof RelationMonomorphicDefinition) {
                $body .= $this->indent(1) . "'{$relation->propertyName}' => \${$relation->name}->id," . PHP_EOL;
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                $body .= $this->indent(1) . "'{$relation->name}_type' => \${$relation->name}->getMorphClass()," . PHP_EOL;
                $body .= $this->indent(1) . "'{$relation->name}_id' => \${$relation->name}->id," . PHP_EOL;
            } else {
                throw new Exception(sprintf('Unknown relation type "%s".', $relation->type));
            }
        }

        /** @var PropertyDefinition $property */
        foreach ($allPropertyRequired as $property) {
            $body .= $this->indent(1) . "'{$property->name}' => \${$property->name}," . PHP_EOL;
        }

        /** @var RelationDefinition $relation */
        foreach ($allRelationNonRequired as $relation) {
            if ($relation instanceof RelationMonomorphicDefinition) {
                $body .= $this->indent(1) . "'{$relation->propertyName}' => \${$relation->name}?->id," . PHP_EOL;
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                $body .= $this->indent(1) . "'{$relation->name}_type' => \${$relation->name}?->getMorphClass()," . PHP_EOL;
                $body .= $this->indent(1) . "'{$relation->name}_id' => \${$relation->name}?->id," . PHP_EOL;
            } else {
                throw new Exception(sprintf('Unknown relation type "%s".', $relation->type));
            }
        }

        /** @var PropertyDefinition $property */
        foreach ($allPropertyNonRequired as $property) {
            $body .= $this->indent(1) . "'{$property->name}' => \${$property->name}," . PHP_EOL;
        }

        $body .= ']);' . PHP_EOL . PHP_EOL;

        if ($this->definition->relations->getEagerLoadable()->isEmpty()) {
            $body .= "return \${$modelVariable};" . PHP_EOL;
        } else {
            $body .= "return \${$modelVariable}->load(\${$modelVariable}->with);" . PHP_EOL;
        }

        $method->setBody($body);
    }

    private function generateRelations(PhpNamespace $namespace, ClassType $class): void
    {
        /** @var RelationDefinition $relation */
        foreach ($this->definition->relations as $relation) {
            $namespace->addUse($relation->toRelationType());

            $method = $class->addMethod($relation->name)
                ->setReturnType($relation->toRelationType());

            if ($relation->isComputed) {
                $method->setAbstract();
            }

            switch ($relation->type) {
                case RelationTypeEnum::BELONGS_TO:
                    $method
                        ->setBody("// @phpstan-ignore-next-line\n"
                            . "return \$this->belongsTo({$namespace->simplifyType($relation->counterModelDefinition->getFullClassName())}::class);")
                        ->addComment("@return {$namespace->simplifyType($relation->toRelationType())}<" .
                            "{$namespace->simplifyType($relation->counterModelDefinition->getFullClassName())}, " .
                            "{$namespace->simplifyType($this->definition->getFullClassName())}" .
                            '>');
                    break;

                case RelationTypeEnum::HAS_MANY:
                    $method
                        ->setBody("return \$this->hasMany({$namespace->simplifyType($relation->counterModelDefinition->getFullClassName())}::class, '{$relation->propertyName}');")
                        ->addComment("@return {$namespace->simplifyType($relation->toRelationType())}<{$namespace->simplifyType($relation->counterModelDefinition->getFullClassName())}>");
                    break;

                case RelationTypeEnum::POLYMORPHIC:
                    $method
                        ->setBody('return $this->morphTo();')
                        ->addComment("@return {$namespace->simplifyType($relation->toRelationType())}");
                    break;

                default:
                    throw new Exception(sprintf('Unknown relation type "%s".', $relation->type->value));
            }
        }
    }

    private function generatePropertyComputed(PhpNamespace $namespace, ClassType $class, PropertyDefinition $property): void
    {
        $namespace->addUse(Attribute::class);

        $class->addMethod(Str::camel($property->name))
            ->setReturnType(Attribute::class)
            ->setComment(sprintf(
                '@return %s<%s, never>',
                $namespace->simplifyType(Attribute::class),
                $this->generatePhpDocTypeIncludingNullableIfNeeded($namespace, $property),
            ))
            ->setAbstract();
    }

    private function generatePropertyMedia(PhpNamespace $namespace, ClassType $class, PropertyDefinition $property): void
    {
        $namespace->addUse(Attribute::class);
        $namespace->addUse($property->toPhpDocType());

        if ($property->isMediaCollection()) {
            $body = 'return Attribute::get(function (): ' . $namespace->simplifyType($property->toPhpDocType()) . ' {' . PHP_EOL;
            $body .= $this->indent(1) . "return \$this->getMedia('{$property->name}');" . PHP_EOL;
        } else {
            $body = 'return Attribute::get(function (): ' . $this->generatePhpDocTypeIncludingNullableIfNeeded($namespace, $property) . ' {' . PHP_EOL;
            $body .= $this->indent(1) . "return \$this->getFirstMedia('{$property->name}');" . PHP_EOL;
        }

        $body .= '});';

        $class->addMethod(Str::camel($property->name))
            ->setReturnType(Attribute::class)
            ->setBody($body);
    }

    private function generatePhpDocTypeIncludingNullableIfNeeded(PhpNamespace $namespace, PropertyDefinition $property): string
    {
        if ($property->isRequired) {
            return $namespace->simplifyType($property->toPhpDocType());
        } else {
            return $namespace->simplifyType($property->toPhpDocType()) . '|null';
        }
    }

    private function addAllInterface(PhpNamespace $namespace, ClassType $class): void
    {
        /** @var MixinDefinition $mixin */
        foreach ($this->definition->mixins as $mixin) {
            switch ($mixin->type) {
                case MixinTypeEnum::GEOLOCATION:
                    $namespace->addUse(ModelGeolocationInterface::class);
                    $class->addImplement(ModelGeolocationInterface::class);
                    break;
                case MixinTypeEnum::REVIEW:
                case MixinTypeEnum::SOFT_DELETE:
                    break;
                default:
                    throw new Exception(sprintf('Unknown mixin type "%s".', $mixin->type->value));
            }
        }
    }

    private function addAllTrait(PhpNamespace $namespace, ClassType $class): void
    {
        /** @var MixinDefinition $mixin */
        foreach ($this->definition->mixins as $mixin) {
            switch ($mixin->type) {
                case MixinTypeEnum::SOFT_DELETE:
                    $namespace->addUse(SoftDeletes::class);
                    $class->addTrait(SoftDeletes::class);
                    break;
                case MixinTypeEnum::GEOLOCATION:
                case MixinTypeEnum::REVIEW:
                    break;
                default:
                    throw new Exception(sprintf('Unknown mixin type "%s".', $mixin->type->value));
            }
        }
    }

    private function addBootMethod(PhpNamespace $namespace, ClassType $class): void
    {
        $bootBody = '';

        $jsonProperties = $this->definition->properties->getAllJson();

        if ($jsonProperties->count() > 0) {
            $bootBody .= PHP_EOL . PHP_EOL . 'static::creating(function (self $model) {' . PHP_EOL;

            /** @var JsonPropertyDefinition $property */
            foreach ($jsonProperties as $property) {
                $bootBody .= $this->indent(1) . sprintf(
                    "\$model->{$property->name} ??= %s;",
                    var_export(json_decode($property->initial), true)
                ) . PHP_EOL;
            }

            $bootBody .= '});';
        }

        if ($this->definition->hasObserver) {
            $namespace->addUse($this->definition->getObserverFullClassName());
            $bootBody .= PHP_EOL . PHP_EOL . "static::observe({$this->simplifyType($namespace, $this->definition->getObserverFullClassName())});";
        }

        if ($relationWithEvent = $this->definition->relations->getWithEventOrNull()) {
            $namespace->addUse(EventLib::class);
            $namespace->addUse(EventActionEnum::class);

            $bootBody .= PHP_EOL . PHP_EOL . 'static::created(function (self $model) {' . PHP_EOL;
            $bootBody .= $this->indent(1) . "EventLib::createEventIfNeeded(\$model, EventActionEnum::CREATE, \$model->{$relationWithEvent->name});" . PHP_EOL;
            $bootBody .= '});';

            $bootBody .= PHP_EOL . PHP_EOL . 'static::updated(function (self $model) {' . PHP_EOL;
            $bootBody .= $this->indent(1) . "EventLib::createEventIfNeeded(\$model, EventActionEnum::UPDATE, \$model->{$relationWithEvent->name});" . PHP_EOL;
            $bootBody .= '});';
        }

        if ($bootBody) {
            $bootBody = 'parent::boot();' . $bootBody;

            $class->addMethod('boot')
                ->setProtected()
                ->setReturnType('void')
                ->setStatic()
                ->setBody($bootBody);
        }
    }
}
