<?php

namespace Modules\EventManagement\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * EventManagement module contract (ADC §5 — Module Contract adoption).
 */
class EventManagementModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'event_management';
    }

    public function getName(): string
    {
        return 'Event Management';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Event Management — bookings, tickets, venues, artists, fraud detection, gamification, and analytics';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'events.view', 'events.create', 'events.manage',
            'bookings.view', 'bookings.create', 'tickets.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['event.created', 'event.status_changed', 'booking.confirmed', 'ticket.issued'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['venue_setup'],
            'default_settings' => [],
            'recommended_roles' => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => [],
            'settings' => [],
            'min_tier' => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data' => false,
            'preserves_entities' => [],
            'cleanup_jobs' => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\EventManagement\Models\Event::class,
            \Modules\EventManagement\Models\Booking::class,
            \Modules\EventManagement\Models\Ticket::class,
            \Modules\EventManagement\Models\Artist::class,
            \Modules\EventManagement\Models\EventTicketType::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions' => false,
            'ai_automation' => false,
            'ai_data_access' => [],
        ];
    }
}
