<?php

namespace Modules\Contact\src\Events;

class ContactDeleted extends ContactEvent
{
    protected string $eventName = 'contact.deleted';
}
