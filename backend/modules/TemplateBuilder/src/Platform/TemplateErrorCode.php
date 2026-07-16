<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * Canonical Universal Template Platform error catalogue (ADR-004 §16).
 *
 * One platform catalogue defines HTTP status, safe message, retryability,
 * audit level and UI action. Do NOT introduce aliases (FEATURE_DISABLED,
 * ACCESS_DENIED, …) when a canonical ADR-001 error already exists.
 *
 * Inherited ADR-001 platform errors are exposed via {@see PlatformError} so
 * callers resolve a single source of truth; this enum owns only the
 * template-specific codes mandated by ADR-004.
 */
enum TemplateErrorCode: string
{
    /** Template type not registered for the requested domain/interaction. */
    case TypeNotRegistered = 'TEMPLATE_TYPE_NOT_REGISTERED';

    /** No published, effective version exists for the resolved template. */
    case NotPublished = 'TEMPLATE_NOT_PUBLISHED';

    /** Resolution found no eligible template and policy denied the fallback. */
    case NotAssigned = 'TEMPLATE_NOT_ASSIGNED';

    /** Two or more eligible assignments tied at the highest priority. */
    case ResolutionAmbiguous = 'TEMPLATE_RESOLUTION_AMBIGUOUS';

    /** A required ADR-000 shared service dependency is unavailable. */
    case DependencyUnavailable = 'TEMPLATE_DEPENDENCY_UNAVAILABLE';

    /** Resolved version is incompatible with the data/component contract. */
    case VersionIncompatible = 'TEMPLATE_VERSION_INCOMPATIBLE';

    /** Template definition failed structural/contract validation. */
    case ValidationFailed = 'TEMPLATE_VALIDATION_FAILED';

    /** Requested channel is not permitted for the template/assignment. */
    case ChannelNotAllowed = 'TEMPLATE_CHANNEL_NOT_ALLOWED';

    /**
     * @return array{http_status: int, safe_message: string, retryable: bool, audit_level: string, ui_action: string}
     */
    public function metadata(): array
    {
        return match ($this) {
            self::TypeNotRegistered => [
                'http_status' => 404,
                'safe_message' => 'The requested template type is not registered for this tenant.',
                'retryable' => false,
                'audit_level' => 'info',
                'ui_action' => 'contact_admin',
            ],
            self::NotPublished => [
                'http_status' => 409,
                'safe_message' => 'No published version is available for this template.',
                'retryable' => true,
                'audit_level' => 'info',
                'ui_action' => 'publish_template',
            ],
            self::NotAssigned => [
                'http_status' => 404,
                'safe_message' => 'No template is assigned for the requested scope.',
                'retryable' => false,
                'audit_level' => 'info',
                'ui_action' => 'configure_assignment',
            ],
            self::ResolutionAmbiguous => [
                'http_status' => 409,
                'safe_message' => 'Template resolution is ambiguous; multiple assignments tie for priority.',
                'retryable' => false,
                'audit_level' => 'warning',
                'ui_action' => 'review_assignments',
            ],
            self::DependencyUnavailable => [
                'http_status' => 503,
                'safe_message' => 'A required platform service is temporarily unavailable.',
                'retryable' => true,
                'audit_level' => 'error',
                'ui_action' => 'retry_later',
            ],
            self::VersionIncompatible => [
                'http_status' => 422,
                'safe_message' => 'The resolved template version is incompatible with the data contract.',
                'retryable' => false,
                'audit_level' => 'warning',
                'ui_action' => 'republish_template',
            ],
            self::ValidationFailed => [
                'http_status' => 422,
                'safe_message' => 'The template definition failed validation.',
                'retryable' => false,
                'audit_level' => 'info',
                'ui_action' => 'fix_definition',
            ],
            self::ChannelNotAllowed => [
                'http_status' => 403,
                'safe_message' => 'The requested channel is not permitted for this template.',
                'retryable' => false,
                'audit_level' => 'warning',
                'ui_action' => 'choose_permitted_channel',
            ],
        };
    }

    public function httpStatus(): int
    {
        return $this->metadata()['http_status'];
    }

    public function safeMessage(): string
    {
        return $this->metadata()['safe_message'];
    }

    public function retryable(): bool
    {
        return $this->metadata()['retryable'];
    }

    public function auditLevel(): string
    {
        return $this->metadata()['audit_level'];
    }

    public function uiAction(): string
    {
        return $this->metadata()['ui_action'];
    }

    /**
     * Inherited ADR-001 platform errors referenced by the ADR-004 evaluation
     * order (§10). Template code MUST surface these codes (not aliases) when
     * the underlying platform guard fails.
     *
     * @return array<string, array{http_status: int, safe_message: string, retryable: bool, audit_level: string, ui_action: string}>
     */
    public static function platformErrors(): array
    {
        return [
            'TENANT_INACTIVE' => ['http_status' => 403, 'safe_message' => 'Tenant is inactive.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'contact_admin'],
            'SUBSCRIPTION_NOT_ENTITLED' => ['http_status' => 403, 'safe_message' => 'Subscription does not entitle this capability.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'upgrade_subscription'],
            'MODULE_NOT_ENABLED' => ['http_status' => 403, 'safe_message' => 'The required module is not enabled.', 'retryable' => false, 'audit_level' => 'info', 'ui_action' => 'enable_module'],
            'FEATURE_NOT_ENABLED' => ['http_status' => 403, 'safe_message' => 'The required feature flag is disabled.', 'retryable' => false, 'audit_level' => 'info', 'ui_action' => 'enable_feature'],
            'FEATURE_NOT_ENTITLED' => ['http_status' => 403, 'safe_message' => 'The feature is not entitled for this plan.', 'retryable' => false, 'audit_level' => 'info', 'ui_action' => 'upgrade_plan'],
            'ENTITLEMENT_EXPIRED' => ['http_status' => 403, 'safe_message' => 'Entitlement has expired.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'renew_entitlement'],
            'RUNTIME_MODE_INVALID' => ['http_status' => 422, 'safe_message' => 'Runtime mode is not valid for this context.', 'retryable' => false, 'audit_level' => 'info', 'ui_action' => 'fix_configuration'],
            'PERMISSION_DENIED' => ['http_status' => 403, 'safe_message' => 'Permission denied.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'request_access'],
            'ENTITY_ACCESS_DENIED' => ['http_status' => 403, 'safe_message' => 'Access to this entity is denied.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'request_entity_access'],
            'RECORD_ACCESS_DENIED' => ['http_status' => 403, 'safe_message' => 'Access to this record is denied.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'request_record_access'],
            'PRIVACY_RESTRICTED' => ['http_status' => 403, 'safe_message' => 'Privacy/consent policy restricts this action.', 'retryable' => false, 'audit_level' => 'warning', 'ui_action' => 'review_consent'],
            'APPROVAL_REQUIRED' => ['http_status' => 422, 'safe_message' => 'A required approval has not been satisfied.', 'retryable' => false, 'audit_level' => 'info', 'ui_action' => 'request_approval'],
        ];
    }
}
