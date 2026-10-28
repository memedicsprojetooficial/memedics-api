<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Gestão das instâncias de WhatsApp (Evolution Go) é restrita a administradores.
        Gate::define('manage-whatsapp', fn (User $user) => (bool) $user->admin);

        // Tela "Envios de confirmação": equipe da clínica, nunca médicos.
        Gate::define('view-confirmation-dispatches', fn (User $user) => $user->type !== 'doctor');
    }
}
