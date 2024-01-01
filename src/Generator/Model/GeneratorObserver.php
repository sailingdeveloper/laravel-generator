<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\MixinTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\GeolocationMixinDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MixinDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\Geolocation\Job\AddressLookupJob;
use Exception;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230917
 */
class GeneratorObserver extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateObserverIfNeeded(): void
    {
        if ($this->definition->hasObserver === false) {
            return;
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Observer')
            ->add("{$this->definition->name}Observer.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Observer')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'Observer');
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . 'ObserverBase');
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . 'ObserverBase');
        $this->addClassHeader($class);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateObserverBaseIfNeeded(): void
    {
        if ($this->definition->hasObserver === false) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Observer')
                ->add('Generated')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'ObserverBase');
        $this->addClassHeaderGenerator($class);

        $namespace->addUse($this->definition->getFullClassName());

        $this->generateAllEventMethod($namespace, $class);
        $this->generateAllEnumUpdatedToMethod($namespace, $class);

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Observer')
            ->add('Generated')
            ->add("{$this->definition->name}ObserverBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function generateAllEventMethod(PhpNamespace $namespace, ClassType $class): void
    {
        $allEvent = [
            'retrieved',
            'creating',
            'created',
            'updating',
            'updated',
            'saving',
            'saved',
            'deleting',
            'deleted',
            'trashed',
            'forceDeleting',
            'forceDeleted',
            'restoring',
            'restored',
            'replicating',
        ];

        foreach ($allEvent as $event) {
            $method = $class->addMethod($event)
                ->setPublic()
                ->setReturnType('void');

            $method->addParameter(Str::camel($this->definition->name))
                ->setType($this->definition->getFullClassName());

            $body = '';

            if ($event === 'created') {
                $body .= $this->addMixinCreated($namespace, $class);
            } elseif ($event === 'updated') {
                $body .= $this->addMixinUpdated($namespace, $class);
            }

            $method->setBody($body);
        }
    }

    private function generateAllEnumUpdatedToMethod(PhpNamespace $namespace, ClassType $class): void
    {
        $allPropertyEnum = $this->definition->properties->getEnums();

        /** @var EnumPropertyDefinition $property */
        foreach ($allPropertyEnum as $property) {
            $namespace->addUse($property->generateFullEnumName());

            $variableModel = Str::camel($this->definition->name);
            $variableProperty = Str::camel($property->name);

            $method = $class->addMethod('is' . Str::studly($property->name) . 'UpdatedTo')
                ->setProtected()
                ->setReturnType('bool');

            $method->addParameter($variableModel)
                ->setType($this->definition->getFullClassName());

            $method->addParameter($variableProperty)
                ->setType($property->generateFullEnumName());

            $body = "return \${$variableModel}->wasChanged('{$property->name}')" . PHP_EOL;
            $body .= $this->indent(1) . "&& \${$variableModel}->{$property->name} === \${$variableProperty};";

            $method->setBody($body);
        }
    }

    private function addMixinCreated(PhpNamespace $namespace, ClassType $class): string
    {
        $body = '';

        /** @var MixinDefinition $mixin */
        foreach ($this->definition->mixins as $mixin) {
            switch ($mixin->type) {
                case MixinTypeEnum::GEOLOCATION:
                    $body .= $this->addCreatedAddressLookupJobIfNeeded($namespace, $class, $mixin);
                    break;
                case MixinTypeEnum::REVIEW:
                case MixinTypeEnum::SOFT_DELETE:
                    break;
                default:
                    throw new Exception(sprintf('Unknown mixin type "%s".', $mixin->type->value));
            }
        }

        return $body;
    }

    private function addMixinUpdated(PhpNamespace $namespace, ClassType $class): string
    {
        $body = '';

        /** @var MixinDefinition $mixin */
        foreach ($this->definition->mixins as $mixin) {
            switch ($mixin->type) {
                case MixinTypeEnum::GEOLOCATION:
                    $body .= $this->addUpdatedAddressLookupJobIfNeeded($namespace, $class, $mixin);
                    break;
                case MixinTypeEnum::REVIEW:
                case MixinTypeEnum::SOFT_DELETE:
                    break;
                default:
                    throw new Exception(sprintf('Unknown mixin type "%s".', $mixin->type->value));
            }
        }

        return $body;
    }

    private function addCreatedAddressLookupJobIfNeeded(PhpNamespace $namespace, ClassType $class, GeolocationMixinDefinition $mixinDefinition): string
    {
        $body = '';
        $variableModel = Str::camel($this->definition->name);

        if ($mixinDefinition->shouldIncludeAddress) {
            $namespace->addUse(AddressLookupJob::class);

            $body .= "if (\${$variableModel}->geolocation) {" . PHP_EOL;
            $body .= $this->indent(1) . "dispatch(new AddressLookupJob(\${$variableModel}));" . PHP_EOL;
            $body .= '}' . PHP_EOL;
        }

        return $body;
    }

    private function addUpdatedAddressLookupJobIfNeeded(PhpNamespace $namespace, ClassType $class, GeolocationMixinDefinition $mixinDefinition): string
    {
        $body = '';
        $variableModel = Str::camel($this->definition->name);

        if ($mixinDefinition->shouldIncludeAddress) {
            $namespace->addUse(AddressLookupJob::class);

            $body .= "if (\${$variableModel}->wasChanged('geolocation')) {" . PHP_EOL;
            $body .= $this->indent(1) . "dispatch(new AddressLookupJob(\${$variableModel}));" . PHP_EOL;
            $body .= '}' . PHP_EOL;
        }

        return $body;
    }
}
