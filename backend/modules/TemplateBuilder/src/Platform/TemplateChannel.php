<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * ADR-004 §7 registration contract — permitted output channels.
 * Mirrors the channels list: HTML, PDF, print, email, portal,
 * authenticated form, public link.
 */
enum TemplateChannel: string
{
    case Html = 'html';
    case Pdf = 'pdf';
    case Print = 'print';
    case Email = 'email';
    case Portal = 'portal';
    case AuthenticatedForm = 'authenticated_form';
    case PublicLink = 'public_link';
    case Sms = 'sms';
    case Letter = 'letter';

    /** Channels that execute outside an authenticated tenant session. */
    public function isPublic(): bool
    {
        return $this === self::PublicLink;
    }

    /** Channels that require a signed token / respondent policy (ADR-004 §8.2). */
    public function requiresSignedToken(): bool
    {
        return $this === self::PublicLink;
    }
}
