<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use App\Event\Enum\Generated\EventActionEnum;
use App\Event\Lib\EventLib;
use App\Geolocation\Interface\ModelGeolocationInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20240217
 */
class GeneratorTypeScriptType extends Generator
{
    public function __construct(
        private string $domain,
        private Collection $models,
    ) {
    }

    public function generateTypeScriptTypeFile(): void
    {
        $allTypeContent = [];

        /** @var ModelDefinition $model */
        foreach ($this->models as $model) {
            $allTypeContent[] = $this->generateTypeScriptTypeByModel($model);

            foreach ($model->properties->generateEnumDefinitions() as $enumDefinition) {
                $allTypeContent[] = $this->generateTypeScriptTypeByEnumDefinition($enumDefinition);
            }
        }

        $content = implode(PHP_EOL . PHP_EOL, $allTypeContent);

        $fileName = Str::of($this->domain)
            ->explode('\\')
            ->add('Type')
            ->add('Generated')
            ->add('types.ts')
            ->join(DIRECTORY_SEPARATOR);

        $this->writeContentToFile(
            app_path() . DIRECTORY_SEPARATOR . $fileName,
            $content,
        );
    }

    private function generateTypeScriptTypeByModel(ModelDefinition $definition): string
    {
        $content = 'export interface ' . $definition->name . 'Type {' . PHP_EOL;

        /** @var PropertyDefinition $property */
        foreach ($definition->properties->getInherited()->getAllInGetRequest() as $property) {
            $content .= $this->indent(1) . $property->requestDefinition->name . ': ' . $this->determineTypeScriptTypeByProperty(
                    $property,
                ) . ';' . PHP_EOL;
        }

        /** @var RelationDefinition $relation */
        foreach ($definition->relations->getAllInGetRequest() as $relation) {
            $content .= $this->indent(1) . $relation->requestDefinition->name . ': ' . $this->determineTypeScriptTypeByRelation(
                    $relation,
                ) . ';' . PHP_EOL;
        }

        /** @var PropertyDefinition $property */
        foreach ($definition->properties->getNonInherited()->getAllInGetRequest() as $property) {
            $content .= $this->indent(1) . $property->requestDefinition->name . ': ' . $this->determineTypeScriptTypeByProperty(
                    $property,
                ) . ';' . PHP_EOL;
        }

        $content .= '}';

        return $content;
    }

    private function determineTypeScriptTypeByRelation(RelationDefinition $relation): string
    {
        if ($relation instanceof RelationMonomorphicDefinition) {
            $typeName = $relation->counterModelDefinition->name . 'Type';

            switch ($relation->type) {
                case RelationTypeEnum::BELONGS_TO:
                    if ($relation->isRequired && $relation->shouldEagerLoad) {
                        return $typeName;
                    } else {
                        return $typeName . ' | null';
                    }
                case RelationTypeEnum::HAS_MANY:
                    if ($relation->shouldEagerLoad) {
                        return $typeName . '[]';
                    } else {
                        return $typeName . '[] | null';
                    }
                default:
                    throw new Exception(sprintf('Unknown relation type "%s".', $relation->type));
            }
        } else {
            throw new Exception(sprintf('Unknown relation type "%s".', $relation::class));
        }
    }

    private function determineTypeScriptTypeByProperty(PropertyDefinition $property): string
    {
        if ($property instanceof EnumPropertyDefinition) {
            $typeName = $property->generateEnumName();
        } else {
            $typeName = match ($property->type) {
                PropertyTypeEnum::ID,
                PropertyTypeEnum::TEXT,
                PropertyTypeEnum::STRING,
                PropertyTypeEnum::TIMESTAMP,
                PropertyTypeEnum::ULID => 'string',

                PropertyTypeEnum::INTEGER => 'number',
                PropertyTypeEnum::JSON_OBJECT => 'Record<string, string>',
                PropertyTypeEnum::JSON_ARRAY => 'string[]',
                PropertyTypeEnum::GEOLOCATION => 'GeolocationType',
                PropertyTypeEnum::POINT => 'PointType',
                PropertyTypeEnum::FILE => 'FileType',
                PropertyTypeEnum::FILE_COLLECTION => 'FileType[]',
                PropertyTypeEnum::IMAGE => 'ImageType',
                PropertyTypeEnum::IMAGE_COLLECTION => 'ImageType[]',
                PropertyTypeEnum::VIDEO => 'VideoType',
                PropertyTypeEnum::VIDEO_COLLECTION => 'VideoType[]',
                PropertyTypeEnum::ADDRESS => 'AddressType',
                PropertyTypeEnum::MONEY_AMOUNT => 'MoneyAmountType',
                default => throw new Exception(sprintf('Unknown property type "%s".', $property->type)),
            };
        }

        if ($property->isRequired) {
            if ($property->requestDefinition->getStatus === RequestStatusEnum::INCLUDE_CONDITIONALLY) {
                return $typeName . ' | null';
            } else {
                return $typeName;
            }
        } else {
            return $typeName . ' | null';
        }
    }

    private function generateTypeScriptTypeByEnumDefinition(EnumDefinition $definition): string
    {
        $content = 'export enum ' . $definition->name . ' {' . PHP_EOL;

        foreach ($definition->choices as $choice) {
            $content .= $this->indent(1) . $choice->name . ' = "' . ($choice->value ?: $choice->name) . '",' . PHP_EOL;
        }

        $content .= '}';

        return $content;
    }

    public function copyTypeScriptTypeFiles(string $directory): void
    {
        $domain = Str::of($this->domain)
            ->snake('-');

        $this->writeContentToFile(
            "{$directory}/app/{$domain}/types.ts",
            file_get_contents(app_path() . "/{$this->domain}/Type/Generated/types.ts"),
        );
    }
}
