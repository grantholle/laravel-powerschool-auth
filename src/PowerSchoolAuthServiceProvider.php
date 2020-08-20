<?php

namespace GrantHolle\PowerSchool\Auth;

use Illuminate\Support\ServiceProvider;

class PowerSchoolAuthServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config.php' => config_path('powerschool-auth.php'),
        ]);
    }

    public function register()
    {

    }
}
