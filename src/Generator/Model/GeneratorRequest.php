<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MediaPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\Request\Rule\SuperfluousFieldRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230510
 */
class GeneratorRequest extends Generator
{
    public function __construct(private readonly ModelDefinition $definition)
    {
    }

    public function generateCreateRequestBaseIfNeeded(): void
    {
        if ($this->definition->requestDefinition->createStatus !== RequestStatusEnum::EXCLUDE) {
            $this->generateRequestBase(RequestTypeEnum::CREATE, $this->definition->properties->getAllInCreateRequest());
        }
    }

    public function generateCreateRequestIfNeeded(): void
    {
        if ($this->definition->requestDefinition->createStatus !== RequestStatusEnum::EXCLUDE) {
            $this->generateRequest(RequestTypeEnum::CREATE, $this->definition->properties->getAllInCreateRequest());
        }
    }

    public function generateUpdateRequestBaseIfNeeded(): void
    {
        if ($this->definition->requestDefinition->updateStatus !== RequestStatusEnum::EXCLUDE) {
            $this->generateRequestBase(RequestTypeEnum::UPDATE, $this->definition->properties->getAllInUpdateRequest());
        }
    }

    public function generateUpdateRequestIfNeeded(): void
    {
        if ($this->definition->requestDefinition->updateStatus !== RequestStatusEnum::EXCLUDE) {
            $this->generateRequest(RequestTypeEnum::UPDATE, $this->definition->properties->getAllInUpdateRequest());
        }
    }

    private function generateRequest(RequestTypeEnum $type, PropertyCollection $properties): void
    {
        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Request')
            ->add("{$this->definition->name}{$type->value}Request.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Request')
                ->join('\\')
        );
        $class = $namespace->addClass($this->definition->name . "{$type->value}Request");
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . "{$type->value}RequestBase");
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . "{$type->value}RequestBase");
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function generateRequestBase(RequestTypeEnum $type, PropertyCollection $properties): void
    {
        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Request')
                ->add('Generated')
                ->join('\\')
        );
        $class = $namespace->addClass($this->definition->name . "{$type->value}RequestBase");

        $namespace->addUse(FormRequest::class);
        $class->setExtends(FormRequest::class);
        $this->addClassHeaderGenerator($class);

        $method = $class->addMethod('authorize')
            ->setReturnType('bool')
            ->setBody('return true;');

        $namespace->addUse(Request::class);
        $method->addParameter('request')
            ->setType(Request::class);

        $this->generateRules($namespace, $class, $type, $properties);

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Request')
            ->add('Generated')
            ->add("{$this->definition->name}{$type->value}RequestBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function generateRules(PhpNamespace $namespace, ClassType $class, RequestTypeEnum $type, PropertyCollection $properties): void
    {
        $body = 'return [' . PHP_EOL;

        $namespace->addUse(SuperfluousFieldRule::class);
        $body .= $this->indent(1) . "'*' => [new " . $namespace->simplifyType(SuperfluousFieldRule::class) . '()],' . PHP_EOL;

        /** @var PropertyDefinition $property */
        foreach ($properties as $property) {
            $propertyName = $property->name;

            if ($property instanceof MediaPropertyDefinition && $property->asynchronousUpload) {
                $propertyName .= '_file_temporary_id';
            }

            $body .= $this->indent(1) . "'" . $propertyName . "' => [";

            foreach ($property->getRules($type) as $rule) {
                if (str_starts_with($rule, 'regex') === false && str_contains($rule, '\\')) {
                    $namespace->addUse($rule);
                    $body .= ' app(' . $namespace->simplifyType($rule) . '::class),';
                } else {
                    $body .= " '" . $rule . "',";
                }
            }

            $body .= ' ],' . PHP_EOL;
        }

        $body .= '];' . PHP_EOL;

        $namespace->addUse(ValidationRule::class);
        $class->addMethod('rules')
            ->setReturnType('array')
            ->addComment('@return array<string, array<int, string|ValidationRule>>')
            ->setBody($body);
    }
}
