<?php

namespace Mpociot\ApiDoc;

use Illuminate\Support\ServiceProvider;
use Mpociot\ApiDoc\Commands\UpdateDocumentation;
use Mpociot\ApiDoc\Commands\GenerateDocumentation;

class ApiDocGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views/', 'apidoc');

        $this->publishes([
            __DIR__.'/../../resources/views' => app()->resourcePath('views/vendor/apidoc')
        ]);
    }

    /**
     * Register the API doc commands.
     *
     * @return void
     */
    public function register()
    {
        if(! $this->app->runningInConsole())
            return;

        $this->commands([
            GenerateDocumentation::class
        ]);
    }
}
