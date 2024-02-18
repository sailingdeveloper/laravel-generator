<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestTypeEnum;
use App\Utility\EnumLib;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpNamespace;
use ReflectionEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class EnumPropertyDefinition extends PropertyDefinition
{
    /**
     * @param array<int, string> $rules
     */
    public function __construct(
        string $name,
        ModelDefinition $modelDefinition,
        string $label,
        bool $isRequired,
        bool $isComputed,
        bool $isAppendedInResource,
        array $rules,
        RequestDefinition $requestDefinition,
        NovaDefinition $novaPropertyDefinition,
        string $index,
        public ?EnumChoiceCollection $choices = null,
        public ?string $enumName = null,
        bool $isInherited = false,
    ) {
        parent::__construct(
            $name,
            $modelDefinition,
            PropertyTypeEnum::ENUM,
            $label,
            $isRequired,
            $isComputed,
            $isAppendedInResource,
            $rules,
            $requestDefinition,
            $novaPropertyDefinition,
            $index,
            $isInherited,
        );
    }

    public function generateEnumName(): string
    {
        if ($this->enumName) {
            return class_basename($this->enumName);
        } else {
            return Str::of($this->modelDefinition->name)
                ->append(Str::of($this->name)->studly() . 'Enum');
        }
    }

    public function generateEnumNamespace(): PhpNamespace
    {
        if ($this->enumName) {
            return new PhpNamespace(
                Str::of($this->enumName)
                    ->explode('\\')
                    ->slice(0, -1)
                    ->join('\\')
            );
        } else {
            return new PhpNamespace(
                Str::of($this->modelDefinition->namespace->getName())
                    ->explode('\\')
                    ->add('Enum')
                    ->add('Generated')
                    ->join('\\')
            );
        }
    }

    public function toPhpDocType(): string
    {
        return $this->generateFullEnumName();
    }

    public function generateFullEnumName(): string
    {
        return $this->generateEnumNamespace()->getName() . '\\' . $this->generateEnumName();
    }

    public function toCastType(): string
    {
        return $this->generateFullEnumName();
    }

    public function toColumnType(): string
    {
        if ($this->isIndexed()) {
            return 'tinyInteger';
        } else {
            return 'string';
        }
    }

    public function isIndexed(): bool
    {
        if ($this->enumName) {
            $reflectionEnum = new ReflectionEnum($this->enumName);

            return match ($reflectionEnum->getBackingType()->getName()) {
                'int' => true,
                'string' => false,
            };
        } else {
            return $this->choices->first()?->index !== null;
        }
    }

    public function generateEnumDefinition(): EnumDefinition
    {
        if ($this->choices) {
            return new EnumDefinition(
                name: $this->generateEnumName(),
                namespace: $this->generateEnumNamespace(),
                choices: $this->choices,
            );
        } else {
            throw new Exception('Enum doesn\'t have any choices.');
        }
    }

    public function getChoices(): EnumChoiceCollection
    {
        if ($this->choices) {
            return $this->choices;
        } elseif ($this->enumName) {
            // @phpstan-ignore-next-line
            $options = EnumLib::determineOptionsByEnum($this->enumName);

            return new EnumChoiceCollection(
                Arr::map($options, fn (string $value, string $name) => new EnumChoiceDefinition(
                    name: $name,
                    value: $value,
                    index: null,
                    color: null,
                )),
            );
        } else {
            throw new Exception('Enum doesn\'t have any choices.');
        }
    }

    /**
     * @return array<int, string>
     */
    public function getRules(RequestTypeEnum $requestType): array
    {
        return [
            ...$this->determineRulesRequired($requestType),
            'in:' . $this->getChoices()
                ->map(fn (EnumChoiceDefinition $choice) => $choice->name)
                ->concat($this->getChoices()->map(fn (EnumChoiceDefinition $choice) => $choice->index ?? $choice->value))
                ->join(','),
            ...$this->rules,
        ];
    }
}
