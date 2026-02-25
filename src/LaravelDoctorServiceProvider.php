<?php

namespace Bunce\LaravelDoctor;

use Bunce\LaravelDoctor\Commands\DoctorScanCommand;
use Illuminate\Support\ServiceProvider;

class LaravelDoctorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doctor.php', 'doctor');

        $this->app->singleton('command.doctor.scan', function () {
            return new DoctorScanCommand;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                'command.doctor.scan',
            ]);

            $this->publishes([
                __DIR__.'/../config/doctor.php' => config_path('doctor.php'),
            ], 'doctor-config');
        }
    }
}
