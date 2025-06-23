<?php

namespace Apiato\Repository\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Event Service Provider
 * Registers repository events and listeners
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application
     */
    protected $listen = [
        \Apiato\Repository\Events\RepositoryCreated::class => [
            // Add your listeners here
        ],
        \Apiato\Repository\Events\RepositoryUpdated::class => [
            // Add your listeners here
        ],
        \Apiato\Repository\Events\RepositoryDeleted::class => [
            // Add your listeners here
        ],
    ];

    /**
     * Boot the application services
     */
    public function boot()
    {
        parent::boot();

        // Auto-register cache clearing listeners if enabled
        if (config('repository.cache.clean.enabled', true)) {
            $this->registerCacheClearingListeners();
        }
    }

    /**
     * Register cache clearing listeners
     */
    protected function registerCacheClearingListeners()
    {
        // Clear cache on  creation
        if (config('repository.cache.clean.on.create', true)) {
            $this->app['events']->listen(
                \Apiato\Repository\Events\RepositoryCreated::class,
                function ($event) {
                    if (method_exists($event->getRepository(), 'clearCache')) {
                        $event->getRepository()->clearCache();
                    }
                }
            );
        }

        // Clear cache on  update
        if (config('repository.cache.clean.on.update', true)) {
            $this->app['events']->listen(
                \Apiato\Repository\Events\RepositoryUpdated::class,
                function ($event) {
                    if (method_exists($event->getRepository(), 'clearCache')) {
                        $event->getRepository()->clearCache();
                    }
                }
            );
        }

        // Clear cache on  deletion
        if (config('repository.cache.clean.on.delete', true)) {
            $this->app['events']->listen(
                \Apiato\Repository\Events\RepositoryDeleted::class,
                function ($event) {
                    if (method_exists($event->getRepository(), 'clearCache')) {
                        $event->getRepository()->clearCache();
                    }
                }
            );
        }
    }
}
