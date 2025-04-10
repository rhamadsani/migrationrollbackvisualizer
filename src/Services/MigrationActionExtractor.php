<?php

namespace LaravelIndonesia\Migrationrollbackvisualizer\Services;

use Illuminate\Support\Str;

class MigrationActionExtractor
{
    public function extract(string $migrationName): array
    {
        $path = database_path("migrations/{$migrationName}.php");

        if (!file_exists($path)) {
            return ['<error>File not found</error>'];
        }

        $code = file_get_contents($path);

        // Extract the content of up() methods
        preg_match('/function\s+up\s*\(\)\s*(?::\s*\w+)?\s*\{([\s\S]*)function\s+(down|rules|messages|__)/', $code, $upMatch);
        $upCode = $upMatch[1] ?? '';

        // Analyze content
        return array_merge(
            $this->parseSchemaActions($upCode)
        );
    }

    private function parseSchemaActions(string $code): array
    {
        $actions = [];

        // Match all Schema::create() blocks
        preg_match_all("/Schema::create\(['\"](.*?)['\"],\s*function\s*\(.*?\)\s*\{([\s\S]*?)\}\);/", $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = $match[1];
            $tableBody = $match[2];

            $actions[] = "create_table: {$table}";

            // Parse column definitions inside the closure
            foreach ($this->parseColumnsFromClosure($tableBody) as $column) {
                $actions[] = "  ↳ add column: {$column}";
            }
        }

        // Handle Schema::table(...)
        preg_match_all("/Schema::table\(['\"](.*?)['\"],\s*function\s*\(.*?\)\s*\{([\s\S]*?)\}\);/", $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $table = $match[1];
            $tableBody = $match[2];
            $actions[] = "modify_table: {$table}";

            foreach ($this->parseColumnsFromClosure($tableBody) as $column) {
                //check is collumn exist or not 
                $actions[] = "  ↳ {$column}";
            }

            foreach ($this->parseTableModifications($tableBody) as $columnAction) {
                //check is collumn exist or not 
                $actions[] = "  ↳ {$columnAction}";
            }
        }

        // Drop table
        preg_match_all("/Schema::dropIfExists\(['\"](.*?)['\"]\)/", $code, $matches);
        foreach ($matches[1] as $table) {
            $actions[] = "drop_table_if_exists: {$table}";
        }

        preg_match_all("/Schema::drop\(['\"](.*?)['\"]\)/", $code, $matches);
        foreach ($matches[1] as $table) {
            $actions[] = "drop_table: {$table}";
        }

        return $actions;
    }

    private function parseTableModifications(string $code): array
    {
        $actions = [];

        // Drop column
        preg_match_all("/->dropColumn\(['\"](.*?)['\"]\)/", $code, $drops, PREG_SET_ORDER);
        foreach ($drops as $drop) {
            $actions[] = "drop_column: {$drop[1]}";
        }

        // Rename column
        preg_match_all("/->renameColumn\(['\"](.*?)['\"],\s*['\"](.*?)['\"]\)/", $code, $renames, PREG_SET_ORDER);
        foreach ($renames as $rename) {
            $actions[] = "rename_column: {$rename[1]} → {$rename[2]}";
        }

        // Drop foreign key
        preg_match_all("/->dropForeign\(['\"](.*?)['\"]\)/", $code, $fks, PREG_SET_ORDER);
        foreach ($fks as $fk) {
            $actions[] = "drop_foreign: {$fk[1]}";
        }

        // Drop index
        preg_match_all("/->dropIndex\(['\"](.*?)['\"]\)/", $code, $indexes, PREG_SET_ORDER);
        foreach ($indexes as $index) {
            $actions[] = "drop_index: {$index[1]}";
        }

        return $actions;
    }

    private function parseColumnsFromClosure(string $code): array
    {
        $columns = [];

        preg_match_all("/->(string|integer|bigInteger|uuid|boolean|text|timestamp|date|json|enum|longText)\(['\"](.*?)['\"]\)(.*?);/", $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $type = $match[1];
            $name = $match[2];
            $rest = $match[3];

            $isModify = Str::contains($rest, '->change()');
            $isNullable = Str::contains($rest, '->nullable(false)') ? 'NOT NULL' : (Str::contains($rest, '->nullable()') ? 'NULLABLE' : '');

            $actionType = $isModify ? 'modify' : 'add';

            $description = "{$actionType} column: {$name} ({$type})";
            if ($isNullable) {
                $description .= " [{$isNullable}]";
            }

            $columns[] = $description;
        }

        // Laravel macros
        if (strpos($code, '->id()') !== false) {
            $columns[] = "add column: id (bigIncrement)";
        }
        if (strpos($code, '->rememberToken()') !== false) {
            $columns[] = "add column: remember_token (string)";
        }
        if (strpos($code, '->timestamps()') !== false) {
            $columns[] = "add columns: created_at & updated_at (timestamps)";
        }

        return $columns;
    }
}
