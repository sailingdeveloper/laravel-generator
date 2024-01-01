<?php

namespace SailingDeveloper\LaravelGenerator;

use Illuminate\Support\ServiceProvider;
use SailingDeveloper\LaravelGenerator\Generator\Command\GeneratorCommand;

class LaravelGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-generator.php', 'laravel-generator');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands(
                [
                    GeneratorCommand::class,
                ],
            );
        }
    }
}
