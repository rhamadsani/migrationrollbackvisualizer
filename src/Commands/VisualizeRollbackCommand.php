<?php

namespace LaravelIndonesia\MigrationRollbackVisualizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisualizeRollbackCommand extends Command
{
    protected $signature = 'visualize:rollback {--mode=} {--format=}';
    protected $description = 'Visualize what would happen during a Laravel migration rollback';

    public function handle()
    {
        $this->info('🔍 Analyzing migrations...');

        $mode = $this->option('mode');
        if (!$mode) {
            $this->line('<fg=yellow>Using default rollback visualization mode...</>');
        }

        $migrations = DB::table('migrations')->orderBy('batch', 'desc')->get();

        if ($migrations->isEmpty()) {
            $this->warn('No migrations have been run yet.');
            return;
        }

        // JSON Format
        if ($this->option('format') === 'json') {
            $json = [];
            foreach ($migrations as $migration) {
                $json[] = [
                    'migration' => $migration->migration,
                    'batch' => $migration->batch,
                    'actions' => $this->extractActions($migration->migration),
                ];
            }

            $this->line(json_encode($json, JSON_PRETTY_PRINT));
            return;
        }

        // Clean Mode: Show all rollback plans
        if ($mode === 'clean') {
            $this->line("<info>Rollback Plan (Clean):</info>");
            foreach ($migrations as $m) {
                $this->line("rollback: batch [{$m->batch}] - file [{$m->migration}]");
            }
            return;
        }

        // Latest Mode: Show only the latest batch
        if ($mode === 'latest') {
            $this->latestAnalyze($migrations);
            return;
        }

        // Default Mode: Pretty table + full rollback plan
        $this->table(
            ['#', 'Migration', 'Batch', 'Timestamp'],
            $migrations->map(function ($m, $i) {
                return [
                    $i + 1,
                    $m->migration,
                    $m->batch,
                    Str::afterLast($m->migration, '_'),
                ];
            })->toArray()
        );

        // Group by batch and display in tree style
        $grouped = $migrations->sortByDesc('batch')->groupBy('batch');
        $this->line("<info>Migration Rollback Plan (Newest to Oldest)</info>\n");

        foreach ($grouped as $batch => $items) {
            $this->line("<fg=cyan>Batch {$batch}</>");

            foreach ($items as $i => $migration) {
                $prefix = $i === count($items) - 1 ? ' └──' : ' ├──';
                $this->line("<fg=green>{$prefix}</> <options=bold>{$migration->migration}</>");

                foreach ($this->extractActions($migration->migration) as $action) {
                    $this->line("     <fg=yellow>↳</> {$action}");
                }
            }

            $this->newLine();
        }

        $this->comment("⚠️  These migrations will be rolled back if you run `php artisan migrate:rollback`");
    }

    private function latestAnalyze($migrations)
    {
        $latestBatch = $migrations->first()->batch;
        $latestMigrations = $migrations->where('batch', $latestBatch);

        $this->info("Latest Batch: $latestBatch");
        $this->table(['Migration File'], $latestMigrations->map(fn($m) => [$m->migration]));

        foreach ($latestMigrations as $migration) {
            $this->line(" ├── <options=bold>{$migration->migration}</>");

            foreach ($this->extractActions($migration->migration) as $action) {
                $this->line("     <fg=yellow>↳</> {$action}");
            }
        }
    }

    public function extractActions(string $migrationName): array
    {
        $path = database_path("migrations/{$migrationName}.php");

        if (!file_exists($path)) {
            return ['<error>File not found</error>'];
        }

        $code = file_get_contents($path);

        // Cek hasil match untuk down()
        preg_match('/function\s+down\s*\(\)\s*(?::\s*\w+)?\s*\{([\s\S]*?)\}/', $code, $downMatch);
        $downCode = $downMatch[1] ?? '';

        // $this->line("<fg=gray>DEBUG DOWN CODE:</>\n" . $downCode);

        return $this->parseSchemaActions($downCode);
    }


    private function parseSchemaActions(string $code): array
    {
        $actions = [];

        // Drop table
        preg_match_all("/Schema::dropIfExists\(['\"](.*?)['\"]\)/", $code, $matches);
        foreach ($matches[1] as $table) {
            $actions[] = "drop_table_if_exists: {$table}";
        }

        preg_match_all("/Schema::drop\(['\"](.*?)['\"]\)/", $code, $matches);
        foreach ($matches[1] as $table) {
            $actions[] = "drop_table: {$table}";
        }

        // Schema::table with multiline closure
        preg_match_all("/Schema::table\(['\"](.*?)['\"],\s*function\s*\(.*?\)\s*\{([\s\S]*?)\}\);/", $code, $matches, PREG_SET_ORDER);

        var_dump($matches, $code);
        foreach ($matches as $match) {
            $table = $match[1];
            $tableBody = $match[2];

            $actions[] = "modify_table: {$table}";

            foreach ($this->parseTableModifications($tableBody) as $columnAction) {
                $actions[] = "  ↳ $columnAction";
            }
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

        // Modify column (change)
        preg_match_all("/->(string|text|integer|boolean|timestamp|date|json)\(['\"](.*?)['\"]\).*?->change\(\)/", $code, $changes, PREG_SET_ORDER);
        foreach ($changes as $change) {
            $actions[] = "modify_column: {$change[2]} ({$change[1]})";
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
}
