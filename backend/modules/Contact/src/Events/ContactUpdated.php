<?php

namespace Modules\Contact\src\Events;

class ContactUpdated extends ContactEvent
{
    protected string $eventName = 'contact.updated';
}
