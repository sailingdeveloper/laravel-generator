<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Enum;

use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumChoiceCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumChoiceDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use Exception;
use Illuminate\Support\Str;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class GeneratorEnum extends Generator
{
    public function __construct(private EnumDefinition $definition)
    {
    }

    public function generateEnum(): void
    {
        if ($this->definition->choices[0]?->index !== null) {
            $allError = $this->definition
                ->choices
                ->groupBy('index')
                ->map(
                    // @phpstan-ignore-next-line
                    fn (EnumChoiceCollection $choices, int $index) => $choices->count() > 1 ? sprintf(
                        'Duplicate choices with the same index "%s": "%s".',
                        $index,
                        $choices->implode('name', '", "')
                    ) : ''
                )
                ->filter()
                ->values();
        } else {
            $allError = $this->definition
                ->choices
                ->groupBy('value')
                ->map(
                    // @phpstan-ignore-next-line
                    fn (EnumChoiceCollection $choices, string $value) => $choices->count() > 1 ? sprintf(
                        'Duplicate choices with the same value "%s": "%s".',
                        $value,
                        $choices->implode('name', '", "')
                    ) : ''
                )
                ->filter()
                ->values();
        }

        if ($allError->isNotEmpty()) {
            throw new Exception(sprintf('[%s]: %s', $this->definition->name, $allError->implode(PHP_EOL)));
        }

        $enum = $this->definition->namespace->addEnum($this->definition->name);

        if ($this->definition->choices[0]?->index !== null) {
            $enum->setType('int');
        } else {
            $enum->setType('string');
        }

        /** @var EnumChoiceDefinition $choice */
        foreach ($this->definition->choices as $choice) {
            $enum->addCase($choice->name, $choice->index ?? $choice->value);
        }

        $method = $enum->addMethod('fromName')
            ->setStatic()
            ->setReturnType('self');
        $method->addParameter('name')->setType('string');

        $body = 'return match ($name) {' . PHP_EOL;

        foreach ($this->definition->choices as $choice) {
            $body .= $this->indent(1) . "'{$choice->name}' => self::{$choice->name}," . PHP_EOL;
        }

        $body .= '};';

        $method->setBody($body);

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add("{$this->definition->name}.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile(
            $fileName,
            $this->definition->namespace,
        );
    }
}
