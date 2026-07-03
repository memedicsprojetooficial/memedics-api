<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\Doctor;
use App\Models\Employee;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Relation::enforceMorphMap([
            'doctor' => Doctor::class,
            'employee' => Employee::class,
            'blocked-time' => BlockedTime::class,
            'appointment' => Appointment::class,
            'user' => \App\Models\User::class,
        ]);

        Http::macro('onmed', function () {
            return Http::baseUrl(config('import.api_url'))->withHeaders(['origin' => config('import.api_origin')]);
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim(config('app.frontend_url'), '/')."/reset-password?token={$token}&email={$email}";
        });
    }
}
