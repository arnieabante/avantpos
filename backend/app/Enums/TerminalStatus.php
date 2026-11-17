<?php

namespace App\Enums;

enum TerminalStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Retired = 'retired';
}
