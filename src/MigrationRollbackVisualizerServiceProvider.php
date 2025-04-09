<?php

namespace Laravelindonesia\MigrationRollbackVisualizer;

use Illuminate\Support\ServiceProvider;
use Laravelindonesia\Migrationrollbackvisualizer\Commands\VisualizeRollbackCommand;
use Laravelindonesia\Migrationrollbackvisualizer\Commands\VisualizeMigrateCommand;

class MigrationRollbackVisualizerServiceProvider extends ServiceProvider
{
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VisualizeRollbackCommand::class,
                VisualizeMigrateCommand::class,
            ]);
        }
    }
}
