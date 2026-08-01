<?php

namespace App\Providers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Organisation;
use App\Policies\FormPolicy;
use App\Policies\FormSubmissionPolicy;
use App\Policies\OrganisationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Organisation::class, OrganisationPolicy::class);
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(FormSubmission::class, FormSubmissionPolicy::class);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('autosave', function (Request $request) {
            $submission = $request->route('submission');
            $submissionKey = $submission instanceof FormSubmission ? $submission->id : (string) $submission;
            return Limit::perMinute(120)->by(($request->user()?->id ?? $request->ip()).'|'.$submissionKey);
        });
    }
}
