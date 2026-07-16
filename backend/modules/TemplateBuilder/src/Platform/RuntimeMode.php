<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * ADR-001 Runtime Mode — the integration behaviour of a template's domain
 * module. ADR-004 consumes this as a registration-contract field; it is NOT
 * used to classify Document/Form/Survey (that is InteractionType).
 */
enum RuntimeMode: string
{
    /** Tight coupling: the domain module hosts the runtime in-process. */
    case Coupled = 'COUPLED';

    /** Loose coupling: the runtime is owned by the platform and called async/event-driven. */
    case Decoupled = 'DECOUPLED';

    /** External system owns execution; the platform only registers & observes. */
    case ExternalIntegration = 'EXTERNAL_INTEGRATION';
}
