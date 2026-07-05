<?php

namespace Modules\Contact\src\Events;

class ContactCreated extends ContactEvent
{
    protected string $eventName = 'contact.created';
}
