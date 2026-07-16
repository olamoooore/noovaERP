<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\TemplateChannel;

/**
 * ADR-004 §5 (#56-57) — Communication Template Bridge.
 *
 * Folds email / SMS / letter / notice templates authored in the Universal
 * Template Platform back into the Communication engine's lookup contract.
 *
 * The Communication module remains the runtime owner of delivery; this
 * bridge is the single direction by which an authored communication
 * template is published into the Communication table — no second authoring
 * studio, no duplicate version engine (ADR-004 §5 "No second template
 * studio").
 *
 * If the Communication module is not installed (no `communication_templates`
 * table) the bridge becomes a no-op so the Universal Template Platform
 * stays standalone.
 */
final class CommunicationTemplateBridge
{
    private const CHANNEL_TO_COMMUNICATION_TYPE = [
        'email' => 'email',
        'sms' => 'sms',
        'letter' => 'email', // letters ride the email HTML body in the current engine.
        'portal' => 'email',
    ];

    /**
     * Publish a universal template that targets a communication channel into
     * the Communication module's `communication_templates` table.
     *
     * Idempotent: keyed by [key, language, tenant_id].
     */
    public function publishToCommunicationEngine(DocumentTemplate $template): ?string
    {
        if (! $this->communicationTableExists()) {
            return null;
        }

        if ($template->interactionType() !== InteractionType::BusinessDocument) {
            return null;
        }

        $registration = $template->registration;
        $channels = $template->layout_config['channels'] ?? ($registration?->channels() ?? []);

        // Only communication-domain templates are bridged.
        $isCommunication = ($registration?->domain_module_code === 'communication')
            || in_array(TemplateChannel::Email->value, $channels, true)
            || in_array(TemplateChannel::Sms->value, $channels, true)
            || in_array(TemplateChannel::Letter->value, $channels, true);

        if (! $isCommunication) {
            return null;
        }

        $key = $template->code ?? $registration?->template_type_code ?? 'utp_'.$template->id;
        $language = $template->default_locale ?? 'en';

        // Pull the authored body. Block templates render to HTML; code templates
        // carry their own HTML. body_plain is derived for SMS.
        $bodyHtml = $template->html ?? '';
        $bodyPlain = $this->toPlainText($bodyHtml);

        DB::table('communication_templates')->updateOrInsert(
            ['key' => $key, 'language' => $language, 'tenant_id' => $this->tenantId()],
            [
                'name' => $template->name,
                'description' => $template->description ?? 'Published from Universal Template Platform',
                'module' => $registration?->domain_module_code ?? 'communication',
                'event' => $template->layout_config['event'] ?? null,
                'subject' => $template->layout_config['subject'] ?? $template->name,
                'body_html' => $bodyHtml,
                'body_plain' => $bodyPlain,
                'channels' => json_encode(array_values(array_unique($channels))) ?: json_encode(['email']),
                'variables' => json_encode($template->layout_config['variables'] ?? []) ?: '[]',
                'language' => $language,
                'is_active' => true,
                'is_system' => $template->is_system_default,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $key;
    }

    private function communicationTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('communication_templates');
        } catch (\Throwable) {
            return false;
        }
    }

    private function tenantId(): ?string
    {
        try {
            return tenant('id');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toPlainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }
}
