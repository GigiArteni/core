<?php

namespace Apiato\Repository\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Repository Service Provider for Apiato v.13
 * Registers all repository services and commands
 */
class RepositoryServiceProvider extends ServiceProvider
{
    protected bool $defer = false;

    /**
     * Boot the application services
     */
    public function boot()
    {
        // Robust config path resolution for local/dev and testbench/vendor environments
        // $rootConfig = dirname(__DIR__, 3) . '/config/repository.php';
        // $packageConfig = __DIR__ . '/../config/repository.php';
        // $configPath = file_exists($rootConfig) ? $rootConfig : $packageConfig;

        // $this->publishes([
        //     $configPath => config_path('repository.php'),
        // ], 'repository');

        // $this->mergeConfigFrom($configPath, 'repository');

        // if ($this->app->runningInConsole()) {
        //     $this->commands([
        //         \Apiato\Repository\Generators\Commands\MakeRepositoryCommand::class,
        //         \Apiato\Repository\Generators\Commands\MakeCriteriaCommand::class,
        //     ]);
        // }
        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/repository.php',
            'repository',
        );
    }

    /**
     * Register the application services
     */
    public function register()
    {
        // Register core services
        $this->registerRepositoryServices();

        // Register validators
        $this->registerValidators();
    }

    /**
     * Register repository services
     */
    protected function registerRepositoryServices()
    {
        // Register default validator
        $this->app->bind(
            \Apiato\Repository\Contracts\ValidatorInterface::class,
            \Apiato\Repository\Validators\LaravelValidator::class
        );

        // Register cache manager
        $this->app->singleton('repository.cache', function ($app) {
            return $app['cache'];
        });
    }

    /**
     * Register validators
     */
    protected function registerValidators()
    {
        $this->app->bind('validator.repository', function ($app) {
            return new \Apiato\Repository\Validators\LaravelValidator();
        });
    }

    /**
     * Get the services provided by the provider
     */
    public function provides()
    {
        return [
            'repository.cache',
            'validator.repository',
        ];
    }
}
