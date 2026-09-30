<?php

namespace Modules\AI\Services;

use App\Models\Setting;
use App\SuperAdmin\Application\FeatureGateService;

/**
 * Website AI assistant identity + availability (Settings → General → AI).
 *
 * Tenants that have BOTH the AI module and the storefront (shop/website)
 * enabled get the assistant automatically ("auto" mode). An explicit
 * `ai.assistant.enabled` setting overrides the auto decision ("manual"),
 * so merchants can switch the assistant off without disabling modules.
 *
 * Shared by the settings API (AISettingsController) and the public
 * storefront endpoints (StorefrontAssistantController) so both always
 * agree on whether the bot is live.
 */
class AssistantConfig
{
    public const DEFAULT_NAME = 'NoovaERP AI Assistant';

    public const DEFAULT_WELCOME = '👋 Hi! I\'m the NoovaERP AI Assistant. How can I help you today?';

    /**
     * @return array{
     *     enabled: bool,
     *     mode: string,
     *     auto_eligible: bool,
     *     name: string,
     *     icon: string|null,
     *     welcome: string
     * }
     */
    public function toArray(): array
    {
        $explicit = Setting::getValue('ai.assistant.enabled');
        $autoEligible = $this->autoEligible();

        $enabled = is_bool($explicit) ? $explicit : $autoEligible;

        return [
            'enabled' => $enabled,
            'mode' => is_bool($explicit) ? 'manual' : 'auto',
            'auto_eligible' => $autoEligible,
            'name' => (string) (Setting::getValue('ai.assistant.name') ?: self::DEFAULT_NAME),
            'icon' => Setting::getValue('ai.assistant.icon') ?: null,
            'welcome' => (string) (Setting::getValue('ai.assistant.welcome') ?: self::DEFAULT_WELCOME),
        ];
    }

    /**
     * Auto mode: the assistant is on while the tenant is entitled to both
     * the AI module and the storefront (shop/website) module.
     */
    private function autoEligible(): bool
    {
        try {
            $tenantId = tenant()?->getTenantKey();
            if (! $tenantId) {
                return false;
            }

            $gate = app(FeatureGateService::class);

            return $this->entitled($gate, (string) $tenantId, 'ai')
                && $this->entitled($gate, (string) $tenantId, 'storefront');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function entitled(FeatureGateService $gate, string $tenantId, string $feature): bool
    {
        try {
            return (bool) ($gate->check($tenantId, $feature)['is_entitled'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
