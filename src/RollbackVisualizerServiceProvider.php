<?php

namespace LaravelIndonesia\MigrationRollbackVisualizer;

use Illuminate\Support\ServiceProvider;
use LaravelIndonesia\MigrationRollbackVisualizer\Commands\VisualizeRollback;

class RollbackVisualizerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            VisualizeRollback::class,
        ]);
    }
}
