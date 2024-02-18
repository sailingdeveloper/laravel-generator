<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use App\Database\Model\Model;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationPolymorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\Media\Resource\MediaResource;
use App\Resource\Resource;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230511
 */
class GeneratorResource extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateResourceIfNeeded(): void
    {
        if ($this->definition->requestDefinition->getStatus === RequestStatusEnum::EXCLUDE) {
            return;
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Resource')
            ->add("{$this->definition->name}Resource.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Resource')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'Resource');
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . 'ResourceBase');
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . 'ResourceBase');
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateResourceBase(): void
    {
        if ($this->definition->requestDefinition->getStatus === RequestStatusEnum::EXCLUDE) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Resource')
                ->add('Generated')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'ResourceBase')
            ->setAbstract();

        $namespace->addUse(Resource::class);
        $class->setExtends(Resource::class);
        $this->addClassHeaderGenerator($class);

        /** @var PropertyDefinition $propertyDefinition */
        foreach ($this->definition->properties->getAllAppendedInResource() as $propertyDefinition) {
            $property = $class->addProperty($propertyDefinition->name);
            $property->setType($propertyDefinition->toPhpDocType());
            $property->setProtected();

            if ($propertyDefinition->isRequired) {
                // Not nullable.
            } else {
                $property->setInitialized(true);
                $property->setNullable();
            }

            $class->addMethod('with' . Str::studly($propertyDefinition->name))
                ->setReturnType('static')
                ->setBody(<<<PHP
\$this->{$propertyDefinition->name} = \$value;

return \$this;
PHP)
                ->addParameter('value')
                ->setType($propertyDefinition->toPhpDocType());
        }

        $method = $class->addMethod('toArray')
            ->setReturnType('array')
            ->addComment('@return array<string, mixed>');

        $namespace->addUse(Request::class);
        $parameter = $method->addParameter('request');
        $parameter->setType(Request::class);

        $namespace->addUse($this->definition->getFullClassName());
        $body = "/** @var {$this->definition->name}Model \$resource */" . PHP_EOL;
        $body .= '$resource = $this->resource;' . PHP_EOL . PHP_EOL;
        $body .= 'return [' . PHP_EOL;
        $body .= $this->indent(1) . "'\$type' => '{$this->definition->name}'," . PHP_EOL;

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getAllInGetRequestWithoutConditional() as $property) {
            $body .= $this->indent(1) . "'{$property->requestDefinition->name}' => {$this->generateGetter($namespace, $property)}," . PHP_EOL;
        }

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getAllInGetRequestConditional() as $property) {
            $body .= $this->indent(1) . "'{$property->requestDefinition->name}' => "
                . '$this->when($this->shouldInclude' . Str::studly($property->name) . "(\$request, \$resource), fn () => {$this->generateGetter($namespace, $property)})," . PHP_EOL;
        }

        /** @var RelationDefinition $relation */
        foreach ($this->definition->relations->getAllInGetRequest() as $relation) {
            if ($relation instanceof RelationMonomorphicDefinition) {
                $namespace->addUse($relation->counterModelDefinition->getResourceFullClassName());
                $body .= $this->indent(1) . "'{$relation->name}' => ";
                $body .= $namespace->simplifyType($relation->counterModelDefinition->getResourceFullClassName());

                $body .= match ($relation->type) {
                    RelationTypeEnum::BELONGS_TO => '::make',
                    RelationTypeEnum::HAS_MANY => '::collection',
                };

                $body .= "(\$this->whenLoaded('" . $relation->name . "'))," . PHP_EOL;
            } elseif ($relation instanceof RelationPolymorphicDefinition) {
                $namespace->addUse(Model::class);
                $body .= $this->indent(1) . sprintf(
                    "'%1\$s' => \$this->whenLoaded('%1\$s', fn () => \$this->determineResourceByModel(\$resource->%1\$s)::make(\$resource->%1\$s)),",
                    $relation->name,
                ) . PHP_EOL;
            } else {
                throw new Exception(sprintf('Unknown relation type "%s".', get_class($relation)));
            }
        }

        $body .= '];';

        $method->setBody($body);

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getAllInGetRequestConditional() as $property) {
            $method = $class->addMethod('shouldInclude' . Str::studly($property->name))
                ->setReturnType('bool')
                ->setAbstract();

            $method->addParameter('request')
                ->setType(Request::class);

            $method->addParameter(Str::camel($this->definition->name))
                ->setType($this->definition->getFullClassName());
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Resource')
            ->add('Generated')
            ->add("{$this->definition->name}ResourceBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function generateGetter(PhpNamespace $namespace, PropertyDefinition $property): string
    {
        switch ($property->type) {
            case PropertyTypeEnum::FILE:
            case PropertyTypeEnum::IMAGE:
            case PropertyTypeEnum::VIDEO:
                $namespace->addUse(MediaResource::class);

                return 'MediaResource::make($resource->' . $property->name . ')';
            default:
                if ($property->isAppendedInResource) {
                    return $property->generateGetter('$this');
                } else {
                    return $property->generateGetter('$resource');
                }
        }
    }
}
