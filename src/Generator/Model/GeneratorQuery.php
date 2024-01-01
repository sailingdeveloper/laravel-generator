<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230513
 */
class GeneratorQuery extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateQueryIfNeeded(): void
    {
        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Query')
            ->add("{$this->definition->name}Query.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Query')
                ->join('\\')
        );
        $class = $namespace->addClass($this->definition->name . 'Query');
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . 'QueryBase');
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . 'QueryBase');
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateQueryBase(): void
    {
        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Query')
                ->add('Generated')
                ->join('\\')
        );
        $modelName = $this->definition->getClassName();

        $class = $namespace->addClass($this->definition->name . 'QueryBase');
        $class->setAbstract();
        $namespace->addUse($this->definition->getFullClassName());

        $namespace->addUse(Builder::class);
        $class->setExtends(Builder::class);
        $this->addClassHeaderGenerator($class);

        $class->addComment(sprintf('@extends Builder<%s>', $modelName));

        $namespace->addUse(Collection::class);
        $class->addComment(sprintf('@method Collection<%1$s>|%1$s[] get()', $modelName));
        $class->addComment(sprintf('@method %s|null first()', $modelName));
        $class->addComment(sprintf('@method %s firstOrFail()', $modelName));
        $class->addComment(sprintf('@method %s sole()', $modelName));
        $class->addComment(sprintf('@method %s create(array $attributes)', $modelName));
        $class->addComment(sprintf('@method %s forceCreate(array $attributes)', $modelName));
        $class->addComment(sprintf('@method %s updateOrCreate(array $attributes, array $values)', $modelName));

        foreach ($this->definition->properties->getIdentifiers() as $property) {
            $method = $class->addMethod('getBy' . Str::studly($property->name));
            $method->setReturnType($this->definition->getFullClassName());

            $parameter = $method->addParameter(Str::camel($property->name));

            if ($property->name === 'id') {
                $parameter->setType('int|string');
            } else {
                $parameter->setType($property->toPhpDocType());
            }

            $method->setBody(sprintf('return $this->where(\'%s\', $%s)->sole();', $property->name, Str::camel($property->name)));
        }

        /** @var RelationMonomorphicDefinition $relation */
        foreach ($this->definition->relations->where('type', RelationTypeEnum::BELONGS_TO) as $relation) {
            $namespace->addUse($relation->counterModelDefinition->getFullClassName());
            $namespace->addUse(Arr::class);

            $method = $class->addMethod('where' . Str::studly($relation->name));
            $method->setReturnType('self');

            $parameterVariable = 'all' . Str::studly($relation->name);
            $parameter = $method->addParameter($parameterVariable);
            $parameter->setType('array');
            $method->addComment(sprintf('@var %s[] $%s', $relation->counterModelDefinition->getClassName(), $parameterVariable));

            $method->setBody(sprintf('return $this->whereIn(\'%s\', Arr::pluck($%s, \'id\'));', $relation->propertyName, $parameterVariable));
        }

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getNonRelation()->getNonComputed()->getIndexed() as $property) {
            $namespace->addUse(Arr::class);

            if (str_contains($property->toPhpDocType(), '\\')) {
                $namespace->addUse($property->toPhpDocType());
            }

            if ($property->name === 'id') {
                $type = 'int|string';
            } else {
                $type = $this->simplifyPhpDocType($namespace, $property->toPhpDocType());
            }

            $method = $class->addMethod('where' . Str::studly($property->name));
            $method->setReturnType('self');

            $parameterVariable = 'all' . Str::studly($property->name);
            $parameter = $method->addParameter($parameterVariable);
            $parameter->setType('array');
            $method->addComment(sprintf('@var %s[] $%s', $type, $parameterVariable));

            $method->setBody(sprintf('return $this->whereIn(\'%s\', $%s);', $property->name, $parameterVariable));

            if ($property->isRequired) {
                // No need for whereNull method.
            } else {
                $method = $class->addMethod('where' . Str::studly($property->name) . 'Null');
                $method->setReturnType('self');
                $method->setBody(sprintf('return $this->whereNull(\'%s\');', $property->name));

                $method = $class->addMethod('where' . Str::studly($property->name) . 'NotNull');
                $method->setReturnType('self');
                $method->setBody(sprintf('return $this->whereNotNull(\'%s\');', $property->name));
            }

            // Order methods.
            $method = $class->addMethod('orderBy' . Str::studly($property->name) . 'Asc');
            $method->setReturnType('self');
            $method->setBody(sprintf('return $this->orderBy(\'%s\', \'asc\');', $property->name));

            $method = $class->addMethod('orderBy' . Str::studly($property->name) . 'Desc');
            $method->setReturnType('self');
            $method->setBody(sprintf('return $this->orderBy(\'%s\', \'desc\');', $property->name));

            if (in_array($property->type, [
                PropertyTypeEnum::ID,
                PropertyTypeEnum::INTEGER,
            ])) {
                // Less than methods.
                $method = $class->addMethod('where' . Str::studly($property->name) . 'LessThan');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType($type);
                $method->setBody(sprintf('return $this->where(\'%s\', \'<\', $%s);', $property->name, Str::camel($property->name)));

                $method = $class->addMethod('where' . Str::studly($property->name) . 'LessThanOrEqual');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType($type);
                $method->setBody(sprintf('return $this->where(\'%s\', \'<=\', $%s);', $property->name, Str::camel($property->name)));

                // Greater than methods.
                $method = $class->addMethod('where' . Str::studly($property->name) . 'GreaterThan');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType($type);
                $method->setBody(sprintf('return $this->where(\'%s\', \'>\', $%s);', $property->name, Str::camel($property->name)));

                $method = $class->addMethod('where' . Str::studly($property->name) . 'GreaterThanOrEqual');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType($type);
                $method->setBody(sprintf('return $this->where(\'%s\', \'>=\', $%s);', $property->name, Str::camel($property->name)));
            } elseif ($property->type == PropertyTypeEnum::TIMESTAMP) {
                $namespace->addUse(Carbon::class);

                // Before methods.
                $method = $class->addMethod('where' . Str::studly($property->name) . 'Before');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType(Carbon::class);
                $method->setBody(sprintf('return $this->where(\'%s\', \'<\', $%s);', $property->name, Str::camel($property->name)));

                $method = $class->addMethod('where' . Str::studly($property->name) . 'BeforeOrEqual');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType(Carbon::class);
                $method->setBody(sprintf('return $this->where(\'%s\', \'<=\', $%s);', $property->name, Str::camel($property->name)));

                // After methods.
                $method = $class->addMethod('where' . Str::studly($property->name) . 'After');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType(Carbon::class);
                $method->setBody(sprintf('return $this->where(\'%s\', \'>\', $%s);', $property->name, Str::camel($property->name)));

                $method = $class->addMethod('where' . Str::studly($property->name) . 'AfterOrEqual');
                $method->setReturnType('self');
                $parameter = $method->addParameter(Str::camel($property->name));
                $parameter->setType(Carbon::class);
                $method->setBody(sprintf('return $this->where(\'%s\', \'>=\', $%s);', $property->name, Str::camel($property->name)));
            }
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Query')
            ->add('Generated')
            ->add("{$this->definition->name}QueryBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }
}
