<?php

namespace Modules\IoT\Enums;

enum ConnectionType: string
{
    case CLOUD = 'cloud';
    case LAN = 'lan';
    case USB = 'usb';
    case AGENT = 'agent';
    case BLUETOOTH = 'bluetooth';
}
