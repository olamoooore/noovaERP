<?php

namespace Modules\Communication\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Communication Facade — Quick access to the CommunicationEngine.
 *
 * Usage:
 *   Communication::notify('invoice', $id, 'invoice.sent', 'Subject', '<p>Body</p>');
 *   Communication::sendDirect('Subject', '<p>Body</p>', recipientUserIds: [1, 2]);
 *   Communication::subscribe('invoice', $id, userId: $userId);
 *   Communication::postComment('invoice', $id, 'Great work!', auth()->id());
 *
 * @method static \Modules\Communication\Models\CommunicationMessage notify(string $model, int $recordId, string $subtype, string $subject, string $body, ...$args)
 * @method static \Modules\Communication\Models\CommunicationMessage sendDirect(string $subject, string $body, ...$args)
 * @method static \Modules\Communication\Models\CommunicationMessage postComment(string $model, int $recordId, string $body, int $authorId, ...$args)
 * @method static \Modules\Communication\Models\CommunicationMessage logSystem(string $model, int $recordId, string $body, ...$args)
 * @method static \Modules\Communication\Models\CommunicationSubscriber subscribe(string $model, int $recordId, ...$args)
 * @method static void unsubscribe(string $model, int $recordId, ...$args)
 *
 * @see \Modules\Communication\Services\CommunicationEngine
 */
class Communication extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'communication';
    }
}
