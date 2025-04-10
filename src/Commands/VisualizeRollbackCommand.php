<?php

namespace LaravelIndonesia\Migrationrollbackvisualizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravelindonesia\Migrationrollbackvisualizer\Services\MigrationActionExtractor;

class VisualizeRollbackCommand extends Command
{
    protected $signature = 'visualize:rollback {--mode=} {--format=}';
    protected $description = 'Visualize what would happen during a Laravel migration rollback';

    public function __construct(protected MigrationActionExtractor $extractor)
    {
        parent::__construct();
    }

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
                    'actions' => $this->extractor->extract($migration->migration),
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

                $actions = $this->extractor->extract($migration->migration);
                foreach ($actions as $action) {
                    // Indent and color based on type
                    if (str_starts_with($action, 'create_table')) {
                        $this->line("  <fg=green>↳</> <fg=green>{$action}</>");
                    } elseif (str_starts_with($action, '  ↳ add column')) {
                        $this->line("     <fg=gray>{$action}</>");
                    } else {
                        $this->line("     <fg=yellow>{$action}</>");
                    }
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

            $actions = $this->extractor->extract($migration->migration);
            foreach ($actions as $action) {
                // Indent and color based on type
                if (str_starts_with($action, 'create_table')) {
                    $this->line("  <fg=green>↳</> <fg=green>{$action}</>");
                } elseif (str_starts_with($action, '  ↳ add column')) {
                    $this->line("     <fg=gray>{$action}</>");
                } else {
                    $this->line("     <fg=yellow>{$action}</>");
                }
            }
            if (count($actions) == 0) {
                $this->error("   no schema found!!!");
            }
        }
    }
}
