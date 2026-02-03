<?php

namespace Modules\IoT\Enums;

enum DeviceStatus: string
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';
    case ERROR = 'error';
    case MAINTENANCE = 'maintenance';
}
