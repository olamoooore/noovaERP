<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

/**
 * String constants for the ADR-004 §7 channels list, mirroring
 * {@see \Modules\TemplateBuilder\Platform\TemplateChannel} values.
 *
 * Kept as plain strings (not the enum) so registration seed data and JSON
 * contract columns store stable scalar values.
 */
final class TemplateChannelList
{
    public const Html = 'html';

    public const Pdf = 'pdf';

    public const Print = 'print';

    public const Email = 'email';

    public const Portal = 'portal';

    public const AuthenticatedForm = 'authenticated_form';

    public const PublicLink = 'public_link';

    public const Sms = 'sms';

    public const Letter = 'letter';
}
