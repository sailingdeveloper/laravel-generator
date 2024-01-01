<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use SailingDeveloper\LaravelGenerator\Generator\Command\GeneratorCommand;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\PropertyDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230512
 */
class GeneratorMigration extends Generator
{
    public function __construct(
        private ModelDefinition $definition,
        private GeneratorCommand $command,
        private Connection $connection,
    ) {
    }

    public function generateMigrationCreateIfNeeded(): void
    {
        $tableName = $this->definition->table->name;

        $body = $this->generateMigrationHeader();
        $body .= $this->indent(2) . 'Schema::create(\'' . $tableName . '\', function (Blueprint $table) {' . PHP_EOL;

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties as $property) {
            if ($property->isComputed) {
                continue;
            }

            if ($property->isMedia()) {
                continue;
            }

            $body .= $this->indent(3)
                . '$table->'
                . $property->toColumnType()
                . "('" . $property->name . "'"
                . ($property->type === PropertyTypeEnum::TIMESTAMP ? ', precision: 6' : '')
                . ($property->type === PropertyTypeEnum::ULID ? ', length: 30' : '')
                . ')'
                . ($property->isRequired ? '' : '->nullable()')
                . ';' . PHP_EOL;
        }

        $indexPairs = $this->definition->properties->determineIndexPairs();

        if (count($indexPairs)) {
            $body .= PHP_EOL;
        }

        foreach ($indexPairs as $indexName => $columns) {
            if ($columns === ['id']) {
                continue;
            }

            $body .= $this->indent(3);

            if (in_array($indexName, $this->definition->table->allUniqueIndexName)) {
                $body .= "\$table->unique(['";
            } else {
                $body .= "\$table->index(['";
            }

            $body .= implode("', '", $columns);
            $body .= "'], '" . $indexName . "');" . PHP_EOL;
        }

        $body .= $this->indent(2) . '});' . PHP_EOL;
        $body .= $this->indent(1) . '}' . PHP_EOL . PHP_EOL;

        $body .= $this->indent(1) . 'public function down(): void' . PHP_EOL;
        $body .= $this->indent(1) . '{' . PHP_EOL;
        $body .= $this->indent(2) . 'Schema::dropIfExists(\'' . $tableName . '\');' . PHP_EOL;
        $body .= $this->indent(1) . '}' . PHP_EOL;
        $body .= '};' . PHP_EOL;

        file_put_contents(
            database_path() . '/migrations/' . date('Y_m_d_His') . '_create_' . $tableName . '_table.php',
            $body,
        );
    }

    public function generateMigrationUpdateIfNeeded(): void
    {
        $tableName = $this->definition->table->name;

        [$allPropertyToAdd, $allColumnNameToRemove] = $this->determineColumnsToAddAndRemove($tableName);
        [$allIndexToAdd, $allIndexToChange] = $this->determineIndexesToAddAndChange($tableName);

        if (empty($allPropertyToAdd) && empty($allColumnNameToRemove) && empty($allIndexToAdd) && empty($allIndexToChange)) {
            $this->command->warn('No changes detected to migrate.');

            return;
        }

        $body = $this->generateMigrationHeader();
        $body .= $this->indent(2) . 'Schema::table(\'' . $tableName . '\', function (Blueprint $table) {' . PHP_EOL;

        foreach ($allColumnNameToRemove as $columnName) {
            $body .= $this->indent(3) . '$table->dropColumn(\'' . $columnName . '\');' . PHP_EOL;
        }

        foreach ($allPropertyToAdd as [$property, $propertyPrevious]) {
            $body .= $this->indent(3)
                . '$table->'
                . $property->toColumnType()
                . "('" . $property->name . "'"
                . ($property->type === PropertyTypeEnum::TIMESTAMP ? ', precision: 6' : '')
                . ($property->type === PropertyTypeEnum::ULID ? ', length: 30' : '')
                . ')'
                . ($property->isRequired ? '' : '->nullable()');

            if ($propertyPrevious) {
                $body .= "->after('{$propertyPrevious->name}')";
            }

            $body .= ';' . PHP_EOL;
        }

        foreach ($allIndexToAdd as $indexName => $columns) {
            if ($columns === ['id']) {
                continue;
            }

            $body .= $this->indent(3)
                . "\$table->index(['"
                . implode("', '", $columns)
                . "'], '" . $indexName . "');" . PHP_EOL;
        }

        foreach ($allIndexToChange as $indexName => $columns) {
            $body .= $this->indent(3)
                . "\$table->dropIndex('" . $indexName . "');" . PHP_EOL;

            $body .= $this->indent(3)
                . "\$table->index(['"
                . implode("', '", $columns)
                . "'], '" . $indexName . "');" . PHP_EOL;
        }

        $body .= $this->indent(2) . '});' . PHP_EOL;
        $body .= $this->indent(1) . '}' . PHP_EOL . PHP_EOL;

        $body .= $this->indent(1) . 'public function down(): void' . PHP_EOL;
        $body .= $this->indent(1) . '{' . PHP_EOL;

        $body .= $this->indent(2) . 'Schema::table(\'' . $tableName . '\', function (Blueprint $table) {' . PHP_EOL;

        foreach ($allPropertyToAdd as [$property, $propertyPrevious]) {
            $body .= $this->indent(3) . '$table->dropColumn(\'' . $property->name . '\');' . PHP_EOL;
        }

        foreach ($allColumnNameToRemove as $columnName) {
            $this->command->warn(sprintf('⚠️  Please check that the re-adding of the column "%s" is correct.', $columnName));

            $column = $this->connection->getDoctrineColumn($tableName, $columnName);

            $body .= $this->indent(3)
                . '$table->'
                . $column->getType()->getName()
                . "('" . $columnName . "')"
                . ($column->getNotnull() ? '' : '->nullable()')
                . ';' . PHP_EOL;
        }

        $body .= $this->indent(2) . '});' . PHP_EOL;
        $body .= $this->indent(1) . '}' . PHP_EOL;
        $body .= '};' . PHP_EOL;

        $this->command->warn('⚠️  Update migration generator does not support changing columns. Please check the generated migration.');

        file_put_contents(
            database_path() . '/migrations/' . date('Y_m_d_His') . '_' . $this->generateMigrationFileName(
                $tableName,
                $allPropertyToAdd,
                $allColumnNameToRemove,
            ),
            $body,
        );
    }

    private function generateMigrationHeader(): string
    {
        $body = '<?php' . PHP_EOL . PHP_EOL;
        $body .= 'use Illuminate\Database\Migrations\Migration;' . PHP_EOL;
        $body .= 'use Illuminate\Database\Schema\Blueprint;' . PHP_EOL;
        $body .= 'use Illuminate\Support\Facades\Schema;' . PHP_EOL . PHP_EOL;

        $body .= 'return new class extends Migration' . PHP_EOL;
        $body .= '{' . PHP_EOL;
        $body .= $this->indent(1) . 'public function up(): void' . PHP_EOL;
        $body .= $this->indent(1) . '{' . PHP_EOL;

        return $body;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function determineColumnsToAddAndRemove(string $tableName): array
    {
        $columns = Schema::getColumnListing($tableName);
        $allPropertyToAdd = [];
        $allColumnNameToRemove = [];

        $propertyPrevious = null;

        /** @var PropertyDefinition $property */
        foreach ($this->definition->properties->getNonComputed()->getNonMedia() as $property) {
            if (! in_array($property->name, $columns)) {
                $allPropertyToAdd[] = [$property, $propertyPrevious];
            }

            $propertyPrevious = $property;
        }

        foreach ($columns as $columnName) {
            if (! $this->definition->properties->exists($columnName)) {
                $allColumnNameToRemove[] = $columnName;
            }
        }

        return [$allPropertyToAdd, $allColumnNameToRemove];
    }

    /**
     * @return array<int, array<string, array<int, string>>>
     */
    private function determineIndexesToAddAndChange(string $tableName): array
    {
        $indexesCurrent = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($tableName);
        $indexPairs = $this->definition->properties->determineIndexPairs();
        $allIndexToAdd = [];
        $allIndexToChange = [];

        foreach ($indexPairs as $indexName => $columns) {
            if ($columns === ['id']) {
                $indexName = 'primary';
            }

            if (isset($indexesCurrent[$indexName])) {
                if (
                    array_diff($indexesCurrent[$indexName]->getColumns(), $columns) === []
                    && array_diff($columns, $indexesCurrent[$indexName]->getColumns()) === []
                ) {
                    // Index exists and is same.
                } else {
                    $allIndexToChange[$indexName] = $columns;
                }
            } else {
                $allIndexToAdd[$indexName] = $columns;
            }
        }

        return [$allIndexToAdd, $allIndexToChange];
    }

    /**
     * @param array<int, array<int, array{PropertyDefinition, PropertyDefinition|null}> $allPropertyToAdd
     * @param array<int, string> $allColumnNameToRemove
     */
    private function generateMigrationFileName(string $tableName, array $allPropertyToAdd, array $allColumnNameToRemove): string
    {
        if (count($allPropertyToAdd) === 1) {
            $property = $allPropertyToAdd[0][0];

            return 'add_' . $property->name . '_to_' . $tableName . '_table.php';
        } elseif (count($allColumnNameToRemove) === 1) {
            return 'remove_' . $allColumnNameToRemove[0] . '_from_' . $tableName . '_table.php';
        } else {
            return 'update_' . $tableName . '_table.php';
        }
    }
}
