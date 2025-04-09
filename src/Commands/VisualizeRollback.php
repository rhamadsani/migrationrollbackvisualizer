<?php

namespace LaravelIndonesia\MigrationRollbackVisualizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VisualizeRollback extends Command
{
    protected $signature = 'visualize:rollback';
    protected $description = 'Visualize what would happen during a Laravel migration rollback';

    public function handle()
    {
        $this->info('Analyzing migrations...');

        $migrations = DB::table('migrations')->orderBy('batch', 'desc')->get();

        if ($migrations->isEmpty()) {
            $this->warn('No migrations have been run yet.');
            return;
        }

        $latestBatch = $migrations->first()->batch;

        $latestMigrations = $migrations->where('batch', $latestBatch);

        $this->info("Latest Batch: $latestBatch");
        $this->table(['Migration File'], $latestMigrations->map(fn($m) => [$m->migration]));

        $this->comment("⚠️ These migrations will be rolled back if you run `php artisan migrate:rollback`");

        // Optional: Read each migration file and try to extract actions
    }
}
