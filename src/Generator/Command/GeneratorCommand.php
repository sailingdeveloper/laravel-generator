<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Command;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\EnumColorEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\MixinTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RelationTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumChoiceCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumChoiceDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\EnumPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\GeolocationMixinDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\JsonPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MediaPropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MixinCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\MixinDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\NovaDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationCollection;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationMonomorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationPolymorphicDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RequestDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\TableDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Enum\GeneratorEnum;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorEvent;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorMigration;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorModel;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorNova;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorObserver;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorQuery;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorRequest;
use SailingDeveloper\LaravelGenerator\Generator\Model\GeneratorResource;
use SailingDeveloper\LaravelGenerator\Lib\JsonLib;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpNamespace;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class GeneratorCommand extends Command
{
    protected $signature = 'generate {only?} {--migration=}';

    /**
     * @var array<string, ModelDefinition>
     */
    protected array $allModel = [];

    public function handle(): int
    {
        $definitionFileNames = glob(app_path() . '/*/Definition/*.json');

        if (empty($definitionFileNames)) {
            $this->error('No definition files found.');

            return 1;
        }

        foreach ($definitionFileNames as $definitionFileName) {
            $this->comment(sprintf('Parsing definition file: %s', str_replace(base_path() . '/', '', $definitionFileName)));
            $name = pathinfo($definitionFileName, PATHINFO_FILENAME);

            $definitionObject = JsonLib::decodeFileToObject($definitionFileName);
            $definition = JsonLib::decodeFileToArray($definitionFileName);
            $schema = JsonLib::decodeFileToObject(__DIR__ . '/../Schema/Model.json');

            $validator = new Validator();
            $validation = $validator->validate(
                $definitionObject,
                $schema,
            );

            if ($validation->isValid()) {
                // Validation succeeded.
            } else {
                $this->error(
                    sprintf(
                        '[%s] Validation failed: %s',
                        $name,
                        // @phpstan-ignore-next-line
                        json_encode((new ErrorFormatter())->format($validation->error()), JSON_PRETTY_PRINT),
                    ),
                );

                return 1;
            }

            $model = new ModelDefinition(
                name: $name,
                namespace: new PhpNamespace(
                    Str::of($definitionFileName)
                        ->explode(DIRECTORY_SEPARATOR)
                        ->filter()
                        ->reverse()
                        ->values()
                        ->slice(2, 2)
                        ->reverse()
                        ->map(fn ($segment) => $segment === 'app' ? 'App' : $segment)
                        ->join('\\'),
                ),
                table: new TableDefinition(
                    name: $definition['table']['name'] ?? Str::of($name)->snake()->plural()->toString(),
                    allUniqueIndexName: $definition['table']['unique'] ?? [],
                    shouldLogActivity: $definition['table']['log'] ?? true,
                ),
                hasObserver: $definition['observer'] ?? false,
                titleAttributes: $definition['title'],
                mixins: $this->parseMixins($definition),
                requestDefinition: $this->parseRequest($name, $definition, default: RequestStatusEnum::INCLUDE),
                ulidPrefix: $definition['ulidPrefix'] ?? $this->generateUlidPrefix($name),
                originalDefinition: $definition,
            );

            $properties = new PropertyCollection(
                [
                    ...$this->generateDefaultProperties($model),
                    ...Arr::map($definition['properties'], fn () => $this->generateProperty($model, ...func_get_args())),
                ],
            );

            $model->setProperties($properties);

            $model->hasObserver = $this->determineHasObserver($model);
            $this->allModel[$name] = $model;
        }

        foreach ($this->allModel as $model) {
            $definition = $model->originalDefinition;

            /** @var RelationCollection|RelationDefinition[] $relations */
            $relations = new RelationCollection(Arr::map($definition['relations'] ?? [], fn () => $this->generateRelation($model, ...func_get_args())));

            foreach ($relations as $relation) {
                $relationWithEvent = $relations->getWithEventOrNull();

                if ($relation->isEvent && $relation->name !== $relationWithEvent?->name) {
                    throw new Exception(sprintf('[%s] Multiple event relations found: "%s" and "%s".', $model->name, $relation->name, $relationWithEvent->name));
                }

                $model->addRelationWithOverride($relation);
                $allForeignKeyProperty = $relation->generateAllForeignKeyProperty();

                foreach ($allForeignKeyProperty as $property) {
                    $model->addProperty($property);
                }

                $counterRelation = $relation->generateCounterRelationOrNull();

                if ($counterRelation === null) {
                    // No counter relation to add.
                } else {
                    $relation->counterModelDefinition->addRelationIfNotExists($counterRelation);
                }
            }
        }

        if (($only = $this->argument('only')) && is_string($only)) {
            if (empty($this->allModel[$only])) {
                $this->error(sprintf('Model %s not found.', $only));

                return 1;
            }

            $allModel = [$only => $this->allModel[$only]];
        } else {
            $allModel = $this->allModel;
        }

        $this->validateUlidPrefixUniqueIfNeeded();

        foreach ($allModel as $model) {
            $this->addMixins($model);
        }

        foreach ($allModel as $model) {
            $this->info(sprintf('Generating files for model: %s', $model->name));
            $model->setProperties($model->properties->order());
            $this->validateTitleAttributes($model);

            $modelGenerator = new GeneratorModel($model);
            $modelGenerator->generateModelBase();
            $modelGenerator->generateModelIfNeeded();

            foreach ($model->properties->generateEnumDefinitions() as $enum) {
                $enumGenerator = new GeneratorEnum($enum);
                $enumGenerator->generateEnum();
            }

            $queryGenerator = new GeneratorQuery($model);
            $queryGenerator->generateQueryBase();
            $queryGenerator->generateQueryIfNeeded();

            $novaGenerator = new GeneratorNova($model);
            $novaGenerator->generateNovaBase();
            $novaGenerator->generateNovaIfNeeded();

            $observerGenerator = new GeneratorObserver($model);
            $observerGenerator->generateObserverBaseIfNeeded();
            $observerGenerator->generateObserverIfNeeded();

            $resourceGenerator = new GeneratorResource($model);
            $resourceGenerator->generateResourceBase();
            $resourceGenerator->generateResourceIfNeeded();

            $requestGenerator = new GeneratorRequest($model);
            $requestGenerator->generateCreateRequestBaseIfNeeded();
            $requestGenerator->generateCreateRequestIfNeeded();
            $requestGenerator->generateUpdateRequestBaseIfNeeded();
            $requestGenerator->generateUpdateRequestIfNeeded();

            $eventGenerator = new GeneratorEvent($model);
            $eventGenerator->generateEventBaseIfNeeded();
            $eventGenerator->generateEventIfNeeded();

            if ($migration = $this->option('migration')) {
                $migrationGenerator = new GeneratorMigration($model, $this, DB::connection());

                if ($migration === 'create') {
                    $migrationGenerator->generateMigrationCreateIfNeeded();
                } elseif ($migration === 'update') {
                    $migrationGenerator->generateMigrationUpdateIfNeeded();
                } else {
                    $this->error(sprintf('Migration option %s invalid.', json_encode($migration)));

                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * @return array<PropertyDefinition>
     */
    private function generateDefaultProperties(ModelDefinition $model): array
    {
        return [
            new PropertyDefinition(
                name: 'id',
                modelDefinition: $model,
                type: PropertyTypeEnum::ID,
                label: 'ID',
                isRequired: true,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'id',
                    isRequired: false,
                    getStatus: RequestStatusEnum::EXCLUDE,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'id',
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: false,
                ),
                index: 'id',
                isInherited: true,
            ),
            new PropertyDefinition(
                name: 'ulid',
                modelDefinition: $model,
                type: PropertyTypeEnum::ULID,
                label: 'ULID',
                isRequired: true,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'id',
                    isRequired: false,
                    getStatus: RequestStatusEnum::INCLUDE,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'ulid',
                    shouldShowOnIndex: false,
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: false,
                ),
                index: 'ulid',
                isInherited: true,
            ),
            new PropertyDefinition(
                name: 'created_at',
                modelDefinition: $model,
                type: PropertyTypeEnum::TIMESTAMP,
                label: 'Created',
                isRequired: true,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'created_at',
                    isRequired: false,
                    getStatus: RequestStatusEnum::INCLUDE,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'created_at',
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: false,
                ),
                index: 'created_at',
                isInherited: true,
            ),
            new PropertyDefinition(
                name: 'updated_at',
                modelDefinition: $model,
                type: PropertyTypeEnum::TIMESTAMP,
                label: 'Updated',
                isRequired: true,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'updated_at',
                    isRequired: false,
                    getStatus: RequestStatusEnum::INCLUDE,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'updated_at',
                    shouldShowOnIndex: false,
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: false,
                ),
                index: 'updated_at',
                isInherited: true,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $propertyDefinition
     */
    private function generateProperty(ModelDefinition $model, array $propertyDefinition, string $propertyName): PropertyDefinition
    {
        $type = PropertyTypeEnum::from($propertyDefinition['type']);

        return match ($type) {
            PropertyTypeEnum::ENUM => $this->parseEnumProperty($model, $propertyName, $propertyDefinition),
            PropertyTypeEnum::JSON_OBJECT,
            PropertyTypeEnum::JSON_ARRAY => $this->parseJsonProperty($model, $propertyName, $propertyDefinition),
            PropertyTypeEnum::FILE,
            PropertyTypeEnum::FILE_COLLECTION,
            PropertyTypeEnum::IMAGE,
            PropertyTypeEnum::IMAGE_COLLECTION,
            PropertyTypeEnum::VIDEO,
            PropertyTypeEnum::VIDEO_COLLECTION => $this->parseMediaProperty($model, $propertyName, $propertyDefinition),
            default => new PropertyDefinition(
                name: $propertyName,
                modelDefinition: $model,
                type: $type,
                label: $propertyDefinition['label'] ?? Str::of($propertyName)->replace('_', ' ')->title(),
                isRequired: $propertyDefinition['required'],
                isComputed: $propertyDefinition['computed'] ?? false,
                rules: $propertyDefinition['rules'] ?? [],
                requestDefinition: $this->parseRequest($propertyName, $propertyDefinition, default: RequestStatusEnum::INCLUDE),
                novaPropertyDefinition: new NovaDefinition(
                    name: $propertyName,
                    type: $propertyDefinition['nova']['type'] ?? null,
                    shouldShowOnIndex: $propertyDefinition['nova']['shouldShowOnIndex'] ?? true,
                    shouldShowOnDetail: $propertyDefinition['nova']['shouldShowOnDetail'] ?? true,
                    shouldShowWhenCreating: $propertyDefinition['nova']['shouldShowWhenCreating'] ?? empty($propertyDefinition['computed']),
                    shouldShowWhenUpdating: $propertyDefinition['nova']['shouldShowWhenUpdating'] ?? empty($propertyDefinition['computed']),
                ),
                index: $propertyDefinition['index'] ?? null,
            )
        };
    }

    /**
     * @param array<string, mixed> $relationDefinition
     */
    private function generateRelation(ModelDefinition $model, array $relationDefinition, string $relationName): RelationDefinition
    {
        $type = RelationTypeEnum::from($relationDefinition['type']);

        switch ($type) {
            case RelationTypeEnum::BELONGS_TO:
            case RelationTypeEnum::HAS_MANY:
                $counterModelDefinition = $this->allModel[$relationDefinition['with']]
                    ?? throw new Exception(sprintf('[%s] Model %s does not exist.', $model->name, $relationDefinition['with']));

                return new RelationMonomorphicDefinition(
                    name: $relationName,
                    propertyName: $relationDefinition['foreignKey'] ?? $this->generateRelationPropertyName($relationName, $type, $model),
                    modelDefinition: $model,
                    type: $type,
                    shouldCreateCounterRelationDefinition: $relationDefinition['createCounterRelation'] ?? true,
                    shouldEagerLoad: $relationDefinition['eager'] ?? false,
                    isEvent: $relationDefinition['event'] ?? false,
                    isRequired: $relationDefinition['required'] ?? false,
                    isComputed: $relationDefinition['computed'] ?? false,
                    index: $relationDefinition['index'] ?? $relationName,
                    requestDefinition: $this->parseRequest($relationName, $relationDefinition, default: RequestStatusEnum::EXCLUDE),
                    novaPropertyDefinition: new NovaDefinition(
                        name: $relationName,
                        help: $relationDefinition['nova']['help'] ?? null,
                        shouldShowOnIndex: $relationDefinition['nova']['shouldShowOnIndex'] ?? true,
                        shouldShowOnDetail: $relationDefinition['nova']['shouldShowOnDetail'] ?? true,
                        shouldShowWhenCreating: $relationDefinition['nova']['shouldShowWhenCreating'] ?? true,
                        shouldShowWhenUpdating: $relationDefinition['nova']['shouldShowWhenUpdating'] ?? false,
                    ),
                    counterModelDefinition: $counterModelDefinition,
                );

            case RelationTypeEnum::POLYMORPHIC:
                return new RelationPolymorphicDefinition(
                    name: $relationName,
                    propertyName: $relationDefinition['foreignKey'] ?? $this->generateRelationPropertyName($relationName, $type, $model),
                    modelDefinition: $model,
                    type: $type,
                    shouldCreateCounterRelationDefinition: $relationDefinition['createCounterRelation'] ?? true,
                    shouldEagerLoad: $relationDefinition['eager'] ?? false,
                    isEvent: $relationDefinition['event'] ?? false,
                    isRequired: $relationDefinition['required'] ?? false,
                    isComputed: $relationDefinition['computed'] ?? false,
                    index: $relationDefinition['index'] ?? $relationName,
                    requestDefinition: $this->parseRequest($relationName, $relationDefinition, default: RequestStatusEnum::EXCLUDE),
                    novaPropertyDefinition: new NovaDefinition(
                        name: $relationName,
                        help: $relationDefinition['nova']['help'] ?? null,
                        shouldShowOnIndex: $relationDefinition['nova']['shouldShowOnIndex'] ?? true,
                        shouldShowOnDetail: $relationDefinition['nova']['shouldShowOnDetail'] ?? true,
                        shouldShowWhenCreating: $relationDefinition['nova']['shouldShowWhenCreating'] ?? true,
                        shouldShowWhenUpdating: $relationDefinition['nova']['shouldShowWhenUpdating'] ?? false,
                    ),
                    allCounterModelDefinition: collect($relationDefinition['with'])->map(
                        fn (string $counterModelName) => $this->allModel[$counterModelName]
                            ?? throw new Exception(sprintf('[%s] Model %s does not exist.', $model->name, $counterModelName)),
                    ),
                );

            default:
                throw new Exception(sprintf('[%s] Unknown relation type "%s".', $model->name, $type->value));
        }
    }

    /**
     * @param array<string, mixed> $propertyDefinition
     */
    private function parseEnumProperty(ModelDefinition $model, string $propertyName, array $propertyDefinition): EnumPropertyDefinition
    {
        if (isset($propertyDefinition['choices'])) {
            return new EnumPropertyDefinition(
                name: $propertyName,
                modelDefinition: $model,
                label: $propertyDefinition['label'] ?? Str::of($propertyName)->replace('_', ' ')->title(),
                isRequired: $propertyDefinition['required'],
                isComputed: $propertyDefinition['computed'] ?? false,
                rules: $propertyDefinition['rules'] ?? [],
                requestDefinition: $this->parseRequest($propertyName, $propertyDefinition, default: RequestStatusEnum::INCLUDE),
                novaPropertyDefinition: new NovaDefinition(
                    name: $propertyName,
                    type: $propertyDefinition['nova']['type'] ?? null,
                    help: $relationDefinition['nova']['help'] ?? null,
                    shouldShowOnIndex: $propertyDefinition['nova']['shouldShowOnIndex'] ?? true,
                    shouldShowOnDetail: $propertyDefinition['nova']['shouldShowOnDetail'] ?? true,
                    shouldShowWhenCreating: $propertyDefinition['nova']['shouldShowWhenCreating'] ?? empty($propertyDefinition['computed']),
                    shouldShowWhenUpdating: $propertyDefinition['nova']['shouldShowWhenUpdating'] ?? empty($propertyDefinition['computed']),
                ),
                index: $propertyDefinition['index'] ?? $propertyName,
                choices: new EnumChoiceCollection(
                    array_map(
                        fn (array $choiceDefinition) => new EnumChoiceDefinition(
                            $choiceDefinition['name'],
                            $choiceDefinition['value'] ?? null,
                            $choiceDefinition['index'] ?? null,
                            isset($choiceDefinition['color']) ? EnumColorEnum::from($choiceDefinition['color']) : null,
                        ),
                        $propertyDefinition['choices'],
                    ),
                ),
            );
        } elseif (isset($propertyDefinition['enum'])) {
            return new EnumPropertyDefinition(
                name: $propertyName,
                modelDefinition: $model,
                label: $propertyDefinition['label'] ?? Str::of($propertyName)->replace('_', ' ')->title(),
                isRequired: $propertyDefinition['required'],
                isComputed: $propertyDefinition['computed'] ?? false,
                rules: $propertyDefinition['rules'] ?? [],
                requestDefinition: $this->parseRequest($propertyName, $propertyDefinition, default: RequestStatusEnum::INCLUDE),
                novaPropertyDefinition: new NovaDefinition(
                    name: $propertyName,
                    type: $propertyDefinition['nova']['type'] ?? null,
                    help: $relationDefinition['nova']['help'] ?? null,
                    shouldShowOnIndex: $propertyDefinition['nova']['shouldShowOnIndex'] ?? true,
                    shouldShowOnDetail: $propertyDefinition['nova']['shouldShowOnDetail'] ?? true,
                    shouldShowWhenCreating: $propertyDefinition['nova']['shouldShowWhenCreating'] ?? empty($propertyDefinition['computed']),
                    shouldShowWhenUpdating: $propertyDefinition['nova']['shouldShowWhenUpdating'] ?? empty($propertyDefinition['computed']),
                ),
                index: $propertyDefinition['index'] ?? $propertyName,
                enumName: $propertyDefinition['enum'],
            );
        } else {
            throw new Exception(sprintf('[%s] Unknown enum type for property "%s".', $model->name, $propertyName));
        }
    }

    /**
     * @param array<string, mixed> $propertyDefinition
     */
    private function parseJsonProperty(ModelDefinition $model, string $propertyName, array $propertyDefinition): JsonPropertyDefinition
    {
        return new JsonPropertyDefinition(
            name: $propertyName,
            modelDefinition: $model,
            type: PropertyTypeEnum::from($propertyDefinition['type']),
            label: $propertyDefinition['label'] ?? Str::of($propertyName)->replace('_', ' ')->title(),
            isRequired: $propertyDefinition['required'],
            isComputed: $propertyDefinition['computed'] ?? false,
            rules: $propertyDefinition['rules'] ?? [],
            requestDefinition: $this->parseRequest($propertyName, $propertyDefinition, default: RequestStatusEnum::INCLUDE),
            novaPropertyDefinition: new NovaDefinition(...['name' => $propertyName, ...$propertyDefinition['nova'] ?? []]),
            index: $propertyDefinition['index'] ?? null,
            initial: $propertyDefinition['initial'] ?? '',
        );
    }

    private function parseMediaProperty(ModelDefinition $model, string $propertyName, array $propertyDefinition): MediaPropertyDefinition
    {
        return new MediaPropertyDefinition(
            name: $propertyName,
            modelDefinition: $model,
            type: PropertyTypeEnum::from($propertyDefinition['type']),
            label: $propertyDefinition['label'] ?? Str::of($propertyName)->replace('_', ' ')->title(),
            isRequired: $propertyDefinition['required'],
            isComputed: $propertyDefinition['computed'] ?? false,
            rules: $propertyDefinition['rules'] ?? [],
            requestDefinition: $this->parseRequest($propertyName, $propertyDefinition, default: RequestStatusEnum::INCLUDE),
            novaPropertyDefinition: new NovaDefinition(...['name' => $propertyName, ...$propertyDefinition['nova'] ?? []]),
            index: $propertyDefinition['index'] ?? null,
            asynchronousUpload: $propertyDefinition['asynchronousUpload'] ?? false,
        );
    }

    /**
     * @param array<string, mixed> $propertyDefinition
     */
    private function parseRequest(string $propertyName, array $propertyDefinition, RequestStatusEnum $default): RequestDefinition
    {
        return new RequestDefinition(
            name: $propertyName,
            isRequired: $propertyDefinition['request']['required'] ?? $propertyDefinition['required'] ?? false,
            getStatus: $this->parseRequestStatus($propertyDefinition['request']['get'] ?? null, $default),
            createStatus: $this->parseRequestStatus($propertyDefinition['request']['create'] ?? null, $default),
            updateStatus: $this->parseRequestStatus($propertyDefinition['request']['update'] ?? null, $default),
        );
    }

    private function parseRequestStatus(bool|string|null $status, RequestStatusEnum $default): RequestStatusEnum
    {
        return match ($status) {
            true => RequestStatusEnum::INCLUDE,
            false => RequestStatusEnum::EXCLUDE,
            'conditional' => RequestStatusEnum::INCLUDE_CONDITIONALLY,
            default => $default,
        };
    }

    private function validateTitleAttributes(ModelDefinition $model): void
    {
        foreach ($model->titleAttributes as $attribute) {
            $property = $model->properties->getNonComputed()->get($attribute);

            if ($property === null) {
                // Property is computed.
            } elseif ($property->index === null) {
                throw new Exception(
                    sprintf('[%s] Property "%s" does not have an index, but is used as title attribute.', $model->name, $attribute),
                );
            } else {
                // Property has an index.
            }
        }
    }

    private function parseMixins(array $definition): MixinCollection
    {
        $allMixin = collect($definition['mixins'] ?? [])
            ->map(function (array $mixinDefinition) {
                $type = MixinTypeEnum::from($mixinDefinition['name']);

                return match ($type) {
                    MixinTypeEnum::GEOLOCATION => new GeolocationMixinDefinition(
                        name: $mixinDefinition['name'],
                        type: $type,
                        shouldIncludeAddress: $mixinDefinition['address'] ?? false,
                    ),
                    default => new MixinDefinition(
                        name: $mixinDefinition['name'],
                        type: $type,
                    ),
                };
            });

        return new MixinCollection($allMixin);
    }

    private function addMixins(ModelDefinition $model): void
    {
        /** @var MixinDefinition $mixin */
        foreach ($model->mixins as $mixin) {
            switch ($mixin->type) {
                case MixinTypeEnum::REVIEW:
                    $this->addReviewRelations($model);
                    $this->addReviewProperties($model);
                    break;
                case MixinTypeEnum::GEOLOCATION:
                    $this->addGeolocationProperties($model, $mixin);
                    break;
                case MixinTypeEnum::SOFT_DELETE:
                    $this->addSoftDeleteProperties($model);
                    break;
                default:
                    throw new Exception(sprintf('[%s] Unknown mixin "%s".', $model->name, $mixin->name));
            }
        }
    }

    private function addReviewRelations(ModelDefinition $model): void
    {
        $relation = new RelationMonomorphicDefinition(
            name: 'user_reviewer',
            propertyName: 'user_reviewer_id',
            modelDefinition: $model,
            type: RelationTypeEnum::BELONGS_TO,
            shouldCreateCounterRelationDefinition: false,
            shouldEagerLoad: false,
            isEvent: false,
            isRequired: false,
            isComputed: false,
            index: 'review',
            requestDefinition: new RequestDefinition(
                name: 'user_reviewer',
                getStatus: RequestStatusEnum::EXCLUDE,
                isRequired: false,
                createStatus: RequestStatusEnum::EXCLUDE,
                updateStatus: RequestStatusEnum::EXCLUDE,
            ),
            novaPropertyDefinition: new NovaDefinition(
                name: 'user_reviewer',
                shouldShowOnIndex: true,
                shouldShowOnDetail: true,
                shouldShowWhenCreating: true,
                shouldShowWhenUpdating: true,
            ),
            counterModelDefinition: $this->allModel['User'] ?? throw new Exception(
                sprintf('[%s] Model User does not exist.', $model->name),
            ),
        );
        $model->addRelation($relation);

        foreach ($relation->generateAllForeignKeyProperty() as $property) {
            $model->addProperty($property);
        }
    }

    private function addGeolocationProperties(ModelDefinition $model, GeolocationMixinDefinition $mixinDefinition): void
    {
        $model->addProperty(
            new PropertyDefinition(
                name: 'geolocation',
                modelDefinition: $model,
                type: PropertyTypeEnum::GEOLOCATION,
                label: 'Geolocation',
                isRequired: false,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'geolocation',
                    isRequired: false,
                    getStatus: RequestStatusEnum::INCLUDE,
                    createStatus: RequestStatusEnum::INCLUDE,
                    updateStatus: RequestStatusEnum::INCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'geolocation',
                    shouldShowOnIndex: false,
                    shouldShowOnDetail: true,
                    shouldShowWhenCreating: true,
                    shouldShowWhenUpdating: true,
                ),
                index: null,
            ),
        );

        if ($mixinDefinition->shouldIncludeAddress) {
            $model->addProperty(
                new PropertyDefinition(
                    name: 'address',
                    modelDefinition: $model,
                    type: PropertyTypeEnum::ADDRESS,
                    label: 'Address',
                    isRequired: false,
                    isComputed: false,
                    rules: [],
                    requestDefinition: new RequestDefinition(
                        name: 'address',
                        isRequired: false,
                        getStatus: RequestStatusEnum::INCLUDE,
                        createStatus: RequestStatusEnum::EXCLUDE,
                        updateStatus: RequestStatusEnum::EXCLUDE,
                    ),
                    novaPropertyDefinition: new NovaDefinition(
                        name: 'address',
                        shouldShowOnIndex: false,
                        shouldShowOnDetail: true,
                        shouldShowWhenCreating: true,
                        shouldShowWhenUpdating: true,
                    ),
                    index: null,
                ),
            );
        }
    }

    private function addReviewProperties(ModelDefinition $model): void
    {
        $propertyStatus = $model->properties->get('status');

        if ($propertyStatus instanceof EnumPropertyDefinition === false) {
            throw new Exception(sprintf('[%s] Review mixin requires a property property called "status" of type "ENUM".', $model->name));
        }

        /** @var Collection $choices */
        $choices = $propertyStatus->getChoices()->map->name;

        if ($choices->contains('IN_REVIEW') === false || $choices->contains('REJECTED') === false) {
            throw new Exception(sprintf('[%s] Review mixin requires the "status" property to have the choices "IN_REVIEW" and "REJECTED".', $model->name));
        }

        $model->addProperty(
            new PropertyDefinition(
                name: 'review_message',
                modelDefinition: $model,
                type: PropertyTypeEnum::STRING,
                label: 'Review Message',
                isRequired: false,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'review_message',
                    isRequired: false,
                    getStatus: RequestStatusEnum::INCLUDE_CONDITIONALLY,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'review_message',
                    shouldShowOnIndex: false,
                    shouldShowOnDetail: true,
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: true,
                ),
                index: null,
            ),
        );
    }

    private function addSoftDeleteProperties(ModelDefinition $model): void
    {
        $model->addPropertyAfter(
            after: 'updated_at',
            property: new PropertyDefinition(
                name: 'deleted_at',
                modelDefinition: $model,
                type: PropertyTypeEnum::TIMESTAMP,
                label: 'Deleted',
                isRequired: false,
                isComputed: false,
                rules: [],
                requestDefinition: new RequestDefinition(
                    name: 'deleted_at',
                    isRequired: false,
                    getStatus: RequestStatusEnum::EXCLUDE,
                    createStatus: RequestStatusEnum::EXCLUDE,
                    updateStatus: RequestStatusEnum::EXCLUDE,
                ),
                novaPropertyDefinition: new NovaDefinition(
                    name: 'deleted_at',
                    shouldShowOnIndex: false,
                    shouldShowOnDetail: true,
                    shouldShowWhenCreating: false,
                    shouldShowWhenUpdating: false,
                ),
                index: 'deleted_at',
            ),
        );
    }

    private function generateRelationPropertyName(string $relationName, RelationTypeEnum $type, ModelDefinition $modelDefinition): string
    {
        return match ($type) {
            RelationTypeEnum::BELONGS_TO => "{$relationName}_id",
            RelationTypeEnum::HAS_MANY => Str::of($modelDefinition->name)->snake()->append('_id')->toString(),
            RelationTypeEnum::POLYMORPHIC => "{$relationName}_id",
        };
    }

    private function determineHasObserver(ModelDefinition $model): bool
    {
        return $model->hasObserver
            || $model->relations->getWithEventOrNull()
            || $model->mixins->firstWhere('type', MixinTypeEnum::GEOLOCATION);
    }

    private function generateUlidPrefix(string $name): string
    {
        $prefix = str_replace('Model', '', class_basename($name));
        preg_match_all('/[A-Z]/', $prefix, $match);
        $prefixCapitals = $match[0];

        if (count($prefixCapitals) >= 3) {
            return strtolower(implode(array_slice($prefixCapitals, 0, 3)));
        } elseif (count($prefixCapitals) == 2) {
            return strtolower(implode($prefixCapitals) . $prefix[strpos($prefix, $prefixCapitals[count($prefixCapitals) - 1]) + 1]);
        } else {
            $suffix = substr(preg_replace('/[aeiou]/i', '', implode('', array_slice(mb_str_split($prefix), 1))), 0, 2);

            if (strlen($suffix) <= 1) {
                return strtolower(substr($prefix, 0, 3));
            } else {
                return strtolower($prefix[0] . $suffix);
            }
        }

        return $name;
    }

    private function validateUlidPrefixUniqueIfNeeded(): void
    {
        if (config('laravel-generator.ulid_prefix') === false) {
            return;
        }

        $ulidPrefixes = collect($this->allModel)->pluck('ulidPrefix', 'name')->sort();

        if ($ulidPrefixes->unique()->count() !== count($this->allModel)) {
            $this->error(sprintf('Ulid prefixes are not unique. If there are conflicts in the auto-generated prefixes, manually specify it in the "ulidPrefix" property in the definition. %s', json_encode($ulidPrefixes->toArray(), JSON_PRETTY_PRINT)));

            exit(1);
        }
    }
}
