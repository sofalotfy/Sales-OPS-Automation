<?php

namespace App\Providers;

use App\Scoring\FactorRegistry;
use App\WebResearch\Providers\TavilyResearchProvider;
use App\WebResearch\WebResearchProvider;
use Illuminate\Support\ServiceProvider;
use App\Triage\PromptBuilder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PromptBuilder keeps the system prompt fixed; the deployment-specific
        // company-scope statement is the only injected part (config/services.php,
        // env COMPANY_SCOPE). Registered here so auto-wiring resolves it.
        $this->app->bind(PromptBuilder::class, fn ($app) => new PromptBuilder((string) config('services.company_scope')));

        // Factor catalog is code-registered (FR-004): starts empty, factors are
        // added one at a time in development by defining a ScoreFactor service
        // and call add() here (SC-003). Kept as a singleton so the same list is
        // shared by the triage flow and the admin factor-settings API.
        $this->app->singleton(FactorRegistry::class, fn () => new FactorRegistry());

        // Web-research provider plug point: defaults to the real Tavily-backed
        // implementation; point config('web_research.provider') (env
        // WEB_RESEARCH_PROVIDER) at another WebResearchProvider to swap it in.
        $this->app->bind(WebResearchProvider::class, function ($app) {
            $provider = config('web_research.provider');

            $provider = is_string($provider) && class_exists($provider)
                ? $provider
                : TavilyResearchProvider::class;

            return $app->make($provider);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
