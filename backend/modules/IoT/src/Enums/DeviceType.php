<?php

namespace Modules\IoT\Enums;

enum DeviceType: string
{
    case AGENT = 'agent';
    case PRINTER = 'printer';
    case TERMINAL = 'terminal';
    case SCANNER = 'scanner';
    case KIOSK = 'kiosk';
}
