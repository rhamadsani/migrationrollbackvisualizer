<?php

namespace LaravelIndonesia\Migrationrollbackvisualizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravelindonesia\Migrationrollbackvisualizer\Services\MigrationActionExtractor;

class VisualizeMigrateCommand extends Command
{
    protected $signature = 'visualize:migrate';
    protected $description = 'Preview pending Laravel migrations and what will change in the database.';


    public function __construct(protected MigrationActionExtractor $extractor)
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('🔍 Scanning for pending migrations...');

        $migrated = DB::table('migrations')->pluck('migration')->toArray();
        $migrationFiles = collect(glob(database_path('migrations/*.php')))
            ->map(fn($path) => basename($path, '.php'));

        $pendingMigrations = $migrationFiles->diff($migrated);

        if ($pendingMigrations->isEmpty()) {
            $this->info('✅ No pending migrations found.');
            return;
        }

        $this->info("⚠️ Pending Migrations (" . $pendingMigrations->count() . "):\n");
        foreach ($pendingMigrations as $migration) {
            $this->line("<fg=blue>•</> <options=bold>{$migration}</>");

            $actions = $this->extractor->extract($migration);
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
            if(count($actions) == 0){
                $this->error("   no schema found!!!");
            }
            $this->newLine();
        }

        $this->comment("\nThis is a preview of what will happen if you run `php artisan migrate`.");
    }
}
