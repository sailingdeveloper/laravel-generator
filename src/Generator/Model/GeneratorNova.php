<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumChoiceDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\JsonPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationPolymorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\Nova\Resource;
use App\Utility\EnumLib;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpNamespace;
use RuntimeException;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230505
 */
class GeneratorNova extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateNovaIfNeeded(): void
    {
        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Nova')
            ->add("{$this->definition->name}Nova.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Nova')
                ->join('\\')
        );
        $class = $namespace->addClass($this->definition->name . 'Nova');
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . 'NovaBase');
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . 'NovaBase');
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateNovaBase(): void
    {
        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Nova')
                ->add('Generated')
                ->join('\\')
        );
        $class = $namespace->addClass($this->definition->name . 'NovaBase');

        $namespace->addUse(Resource::class);
        $class->setExtends(Resource::class);
        $this->addClassHeaderGenerator($class);

        $namespace->addUse($this->definition->getFullClassName());
        $class->addProperty('model')
            ->setType('string')
            ->setStatic()
            ->setValue(new Literal($this->definition->name . 'Model::class'));

        $class->addProperty('search')
            ->setStatic()
            ->setComment('@var array<int, string> $search')
            ->setValue($this->definition->properties->getSearchable()->pluck('name')->all());

        $class->addMethod('title')
            ->setReturnType('?string')
            ->setBody(
                'return ' . collect($this->definition->titleAttributes)
                    ->add('id')
                    ->unique()
                    ->map(fn (string $attribute): PropertyDefinition => $this->definition->properties->get($attribute))
                    ->map(fn (PropertyDefinition $property): string => $property->generateGetter('$this->resource'))->join(' ?: ') . ';',
            );

        $class->addMethod('label')
            ->setStatic()
            ->setReturnType('string')
            ->setBody("return '" . Str::of($this->definition->name)->plural()->snake()->replace('_', ' ')->title() . "';");

        $class->addMethod('uriKey')
            ->setStatic()
            ->setReturnType('string')
            ->setBody("return '" . Str::of($this->definition->name)->plural()->snake() . "';");

        $allFieldToGenerate = [
            ...$this->definition->properties->getInherited(),
            ...$this->definition->relations,
            ...$this->definition->properties->getNonRelation()->getNonInherited(),
        ];

        $this->generateFields($namespace, $class, $allFieldToGenerate);
        $this->generateAllFieldMethod($namespace, $class, $allFieldToGenerate);
        $this->generateFilters($namespace, $class);

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Nova')
            ->add('Generated')
            ->add("{$this->definition->name}NovaBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    /**
     * @param array<int, PropertyDefinition|RelationDefinition> $allFieldToGenerate
     */
    private function generateFields(PhpNamespace $namespace, ClassType $class, array $allFieldToGenerate): void
    {
        $body = 'return [' . PHP_EOL;

        foreach ($allFieldToGenerate as $field) {
            $body .= $this->indent(1) . '$this->generateField' . Str::studly($field->name) . '($request),' . PHP_EOL;

            if ($field instanceof EnumPropertyDefinition && $this->shouldShowBadgeForEnum($field)) {
                $body .= $this->indent(1) . '$this->generateField' . Str::studly($field->name) . 'Badge($request),' . PHP_EOL;
            }
        }

        $body .= '];' . PHP_EOL;

        $namespace->addUse(Field::class);
        $method = $class->addMethod('fields')
            ->setReturnType('array')
            ->addComment('@return array<int, Field>')
            ->setBody($body);

        $namespace->addUse(NovaRequest::class);
        $method->addParameter('request')
            ->setType(NovaRequest::class);
    }

    private function generateAllFieldMethod(PhpNamespace $namespace, ClassType $class, array $allFieldToGenerate): void
    {
        foreach ($allFieldToGenerate as $field) {
            if ($field instanceof RelationMonomorphicDefinition) {
                $body = $this->generateFieldMethodRelationMonomorphic($namespace, $field);
            } elseif ($field instanceof RelationPolymorphicDefinition) {
                $body = $this->generateFieldMethodRelationPolymorphic($namespace, $field);
            } elseif ($field instanceof PropertyDefinition) {
                $body = $this->generateFieldMethodProperty($namespace, $field);
            } else {
                throw new RuntimeException('Unknown field type');
            }

            $namespace->addUse($field->toNovaType());

            $method = $class->addMethod('generateField' . Str::studly($field->name))
                ->setProtected()
                ->setReturnType($field->toNovaType())
                ->setBody($body);

            $method->addParameter('request')
                ->setType(NovaRequest::class);

            if ($field instanceof EnumPropertyDefinition && $this->shouldShowBadgeForEnum($field)) {
                $body = $this->generateFieldMethodPropertyBadge($namespace, $field);

                $method = $class->addMethod('generateField' . Str::studly($field->name) . 'Badge')
                    ->setProtected()
                    ->setReturnType(Badge::class)
                    ->setBody($body);

                $method->addParameter('request')
                    ->setType(NovaRequest::class);
            }
        }
    }

    private function generateFieldMethodRelationMonomorphic(PhpNamespace $namespace, RelationMonomorphicDefinition $relation): string
    {
        $namespace->addUse($relation->toNovaType());
        $namespace->addUse($relation->counterModelDefinition->getNovaFullClassName());

        $body = 'return ' . $namespace->simplifyType($relation->toNovaType())
            . "::make(name: '" . Str::of($relation->name)->replace('_', ' ')->title() . "', "
            . "attribute: '" . $relation->name . "', "
            . 'resource: ' . $namespace->simplifyType($relation->counterModelDefinition->getNovaFullClassName()) . '::class)';

        if ($relation->type === RelationTypeEnum::BELONGS_TO) {
            $body .= PHP_EOL . $this->indent(1) . '->default(fn () => $request->get(\'viaResource\') === \'' . Str::of($relation->name)->plural() . '\' ? $request->get(\'viaResourceId\') : null)';
            $body .= PHP_EOL . $this->indent(1) . '->searchable()';

            if ($relation->isRequired) {
                $body .= PHP_EOL . $this->indent(1) . '->required()';
            } else {
                $body .= PHP_EOL . $this->indent(1) . '->nullable()';
            }

            if ($relation->counterModelDefinition->name === 'User' && $relation->isRequired) {
                $body .= PHP_EOL . $this->indent(1) . '->default(fn () => $request->user()->id)';
            }
        }

        if ($relation->novaPropertyDefinition->help) {
            $body .= PHP_EOL . $this->indent(1) . '->help("' . $relation->novaPropertyDefinition->help . '")';
        }

        if ($relation->novaPropertyDefinition->shouldShowOnIndex === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromIndex()';
        }

        if ($relation->novaPropertyDefinition->shouldShowOnDetail === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromDetail()';
        }

        if ($relation->novaPropertyDefinition->shouldShowWhenCreating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenCreating()';
        }

        if ($relation->novaPropertyDefinition->shouldShowWhenUpdating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenUpdating()';
        }

        $body .= ';' . PHP_EOL;

        return $body;
    }

    private function generateFieldMethodRelationPolymorphic(PhpNamespace $namespace, RelationPolymorphicDefinition $relation): string
    {
        $namespace->addUse($relation->toNovaType());

        $counterModels = [];
        foreach ($relation->allCounterModelDefinition as $counterModelDefinition) {
            $namespace->addUse($counterModelDefinition->getNovaFullClassName());
            $counterModels[] = $namespace->simplifyType($counterModelDefinition->getNovaFullClassName()) . '::class';
        }

        $body = 'return MorphTo::make(name: \'' . Str::of($relation->name)->replace('_', ' ')->title() . '\', '
            . "attribute: '" . $relation->name . "')"
            . PHP_EOL . $this->indent(1) . '->types([' . implode(', ', $counterModels) . '])';

        if ($relation->isRequired) {
            $body .= PHP_EOL . $this->indent(1) . '->required()';
        } else {
            $body .= PHP_EOL . $this->indent(1) . '->nullable()';
        }

        if ($relation->novaPropertyDefinition->help) {
            $body .= PHP_EOL . $this->indent(1) . '->help("' . $relation->novaPropertyDefinition->help . '")';
        }

        if ($relation->novaPropertyDefinition->shouldShowOnIndex === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromIndex()';
        }

        if ($relation->novaPropertyDefinition->shouldShowOnDetail === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromDetail()';
        }

        if ($relation->novaPropertyDefinition->shouldShowWhenCreating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenCreating()';
        }

        if ($relation->novaPropertyDefinition->shouldShowWhenUpdating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenUpdating()';
        }

        $body .= ';' . PHP_EOL;

        return $body;
    }

    private function generateFieldMethodProperty(PhpNamespace $namespace, PropertyDefinition $property): string
    {
        $namespace->addUse($property->toNovaType());

        $body = 'return ' . $namespace->simplifyType($property->toNovaType())
            . "::make('" . $property->label . "', '" . $property->name . "')";

        $createRules = $property->getRules(RequestTypeEnum::CREATE);
        $updateRules = $property->getRules(RequestTypeEnum::UPDATE);

        if ($createRules || $updateRules) {
            $body .= PHP_EOL . $this->indent(1)
                . '->rules($request->isCreateOrAttachRequest() ? '
                . $this->generateRules($namespace, $createRules)
                . ' : '
                . $this->generateRules($namespace, $updateRules)
                . ')';
        }

        if ($property->isRequired && $property->isInherited === false) {
            $body .= PHP_EOL . $this->indent(1) . '->required()';
        }

        if ($property->novaPropertyDefinition->help) {
            $body .= PHP_EOL . $this->indent(1) . '->help("' . $property->novaPropertyDefinition->help . '")';
        }

        $body .= PHP_EOL . $this->indent(1) . "->textAlign('left')";

        if ($property instanceof EnumPropertyDefinition) {
            $namespace->addUse($property->generateFullEnumName());
            $body .= PHP_EOL . $this->indent(1) . '->enum(' . $namespace->simplifyType($property->generateFullEnumName()) . '::class)';

            if ($this->shouldShowBadgeForEnum($property)) {
                $body .= PHP_EOL . $this->indent(1) . '->hideFromIndex()';
                $body .= PHP_EOL . $this->indent(1) . '->hideFromDetail()';
            }
        }

        if ($property instanceof JsonPropertyDefinition) {
            $body .= PHP_EOL . $this->indent(1) . '->json()';
        }

        if ($property->novaPropertyDefinition->type === 'URL') {
            $body .= PHP_EOL . $this->indent(1) . '->displayUsing(fn ($value) => $value)';
        }

        if ($property->type === PropertyTypeEnum::TIMESTAMP) {
            $namespace->addUse(Nova::class);
            $body .= PHP_EOL . $this->indent(1) . '->displayUsing(fn ($value) => $value?->timezone(Nova::resolveUserTimezone($request))->format(\'Y-m-d H:i:s (e)\'))';
        }

        if ($property->novaPropertyDefinition->shouldShowOnIndex === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromIndex()';
        }

        if ($property->novaPropertyDefinition->shouldShowOnDetail === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromDetail()';
        }

        if ($property->novaPropertyDefinition->shouldShowWhenCreating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenCreating()';
        }

        if ($property->novaPropertyDefinition->shouldShowWhenUpdating === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideWhenUpdating()';
        }

        if ($property->isMedia()) {
            $body .= PHP_EOL . $this->indent(1) . '->temporary(now()->addMinutes(30))';

            switch ($property->type) {
                case PropertyTypeEnum::IMAGE:
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnPreview(\'thumbnail\')';
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnIndexView(\'thumbnail\')';
                    break;
                case PropertyTypeEnum::VIDEO:
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnPreview(\'thumbnail\')';
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnIndexView(\'thumbnail\')';
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnDetailView(\'thumbnail\')';
                    $body .= PHP_EOL . $this->indent(1) . '->conversionOnForm(\'thumbnail\')';
                    break;
            }
        }

        $body .= ';';

        return $body;
    }

    private function generateFieldMethodPropertyBadge(PhpNamespace $namespace, EnumPropertyDefinition $property): string
    {
        $body = '';

        $namespace->addUse(Badge::class);
        $body .= 'return ' . sprintf("Badge::make('%s', '%s')", $property->label, $property->name) . PHP_EOL;
        $body .= $this->indent(1) . '->map([' . PHP_EOL;

        /** @var EnumChoiceDefinition $choice */
        // @phpstan-ignore-next-line
        foreach ($property->choices as $choice) {
            $body .= $this->indent(2) . sprintf("%s => '%s',", $choice->index ?? $choice->value, $choice->color?->value ?? 'info') . PHP_EOL;
        }

        $body .= $this->indent(1) . '])';
        $body .= PHP_EOL . $this->indent(1) . '->label(fn ($value) => [' . PHP_EOL;

        /** @var EnumChoiceDefinition $choice */
        // @phpstan-ignore-next-line
        foreach ($property->choices as $choice) {
            $body .= $this->indent(3) . sprintf("%s => '%s',", $choice->index ?? $choice->value, Str::of($choice->name)->replace('_', ' ')) . PHP_EOL;
        }

        $body .= $this->indent(1) . '][$value])';
        $body .= PHP_EOL . $this->indent(1) . "->textAlign('left')";

        if (str_ends_with($property->name, 'status')) {
            $body .= PHP_EOL . $this->indent(1) . '->withIcons()';
        }

        if ($property->novaPropertyDefinition->shouldShowOnIndex === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromIndex()';
        }

        if ($property->novaPropertyDefinition->shouldShowOnDetail === false) {
            $body .= PHP_EOL . $this->indent(1) . '->hideFromDetail()';
        }

        $body .= ';';

        return $body;
    }

    private function generateFilters(PhpNamespace $namespace, ClassType $class): void
    {
        $properties = $this->definition->properties->getEnums()->getSearchable();

        if ($properties->isEmpty()) {
            return;
        }

        $namespace->addUse(EnumLib::class);
        $namespace->addUse(Builder::class);

        $body = 'return [' . PHP_EOL;

        /** @var EnumPropertyDefinition $property */
        foreach ($properties as $property) {
            $namespace->addUse($property->generateFullEnumName());

            $body .= $this->indent(1) . 'new class extends Filter {' . PHP_EOL;
            $body .= $this->indent(2) . 'public $name = \'' . $property->label . '\';' . PHP_EOL;
            $body .= PHP_EOL;

            $body .= $this->indent(2) . '/**' . PHP_EOL;
            $body .= $this->indent(2) . ' * @param Builder<' . $this->definition->name . 'Model> $query' . PHP_EOL;
            $body .= $this->indent(2) . ' * @param string|null $value' . PHP_EOL;
            $body .= $this->indent(2) . ' * @return Builder<' . $this->definition->name . 'Model>' . PHP_EOL;
            $body .= $this->indent(2) . ' */' . PHP_EOL;
            $body .= $this->indent(2) . 'public function apply(NovaRequest $request, $query, $value): Builder' . PHP_EOL;
            $body .= $this->indent(2) . '{' . PHP_EOL;
            $body .= $this->indent(3) . 'return $query->when($value, fn ($query) => $query->where(\'' . $property->name . '\', $value));' . PHP_EOL;
            $body .= $this->indent(2) . '}' . PHP_EOL;
            $body .= PHP_EOL;

            $body .= $this->indent(2) . '/**' . PHP_EOL;
            $body .= $this->indent(2) . ' * @return array<string, int|string>' . PHP_EOL;
            $body .= $this->indent(2) . ' */' . PHP_EOL;
            $body .= $this->indent(2) . 'public function options(NovaRequest $request): array' . PHP_EOL;
            $body .= $this->indent(2) . '{' . PHP_EOL;
            $body .= $this->indent(3) . 'return array_flip(EnumLib::determineOptionsByEnum(' . $namespace->simplifyType($property->generateFullEnumName()) . '::class));' . PHP_EOL;
            $body .= $this->indent(2) . '}' . PHP_EOL;
            $body .= $this->indent(1) . '},' . PHP_EOL . PHP_EOL;
        }

        $body .= '];' . PHP_EOL;

        $namespace->addUse(Filter::class);
        $method = $class->addMethod('filters')
            ->setReturnType('array')
            ->addComment('@return array<int, Filter>')
            ->setBody($body);

        $namespace->addUse(NovaRequest::class);
        $method->addParameter('request')
            ->setType(NovaRequest::class);
    }

    private function shouldShowBadgeForEnum(EnumPropertyDefinition $property): bool
    {
        return $property->choices?->first(fn (EnumChoiceDefinition $choice) => $choice->color !== null) !== null;
    }

    private function generateRules(PhpNamespace $namespace, array $createRules): string
    {
        $body = '[';

        foreach ($createRules as $rule) {
            if (str_starts_with($rule, 'regex') === false && str_contains($rule, '\\')) {
                $namespace->addUse($rule);
                $body .= 'app(' . $namespace->simplifyType($rule) . '::class),';
            } else {
                $body .= "'" . $rule . "',";
            }
        }

        $body .= ']';

        return $body;
    }
}
