<?php

namespace App\Providers;

use App\Models\BillingAccount;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Billing\Gateway\CashierStripeGateway;
use App\Services\Billing\Gateway\CashierStripeInspector;
use App\Services\Billing\Gateway\CashierStripeTestClocks;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Billing\Gateway\StripeTestClocks;
use App\Services\Selection\SelectionConfig;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentHousehold::class);
        $this->app->singleton(AppSettings::class);
        $this->app->bind(SelectionConfig::class, fn () => SelectionConfig::fromConfig());
        $this->app->bind(StripeGateway::class, CashierStripeGateway::class);
        $this->app->bind(StripeInspector::class, CashierStripeInspector::class);
        $this->app->bind(StripeTestClocks::class, CashierStripeTestClocks::class);

        // The webhook route is registered by the application (routes/web.php) with an inbox in front of Cashier.
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Platform administration is a separate role from household ownership; granted only by the deploy command.
        Gate::define('platform-admin', fn (User $user): bool => $user->isPlatformAdmin());

        // The Stripe customer is the household's billing account, never a person.
        Cashier::useCustomerModel(BillingAccount::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
