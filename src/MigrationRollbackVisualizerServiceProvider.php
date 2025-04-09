<?php

namespace Laravelindonesia\Migrationrollbackvisualizer;

use Illuminate\Support\ServiceProvider;
use Laravelindonesia\Migrationrollbackvisualizer\Commands\VisualizeRollbackCommand;

class MigrationRollbackVisualizerServiceProvider extends ServiceProvider
{
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VisualizeRollbackCommand::class,
            ]);
        }
    }
}
