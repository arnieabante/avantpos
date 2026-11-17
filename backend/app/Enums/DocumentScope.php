<?php

namespace App\Enums;

enum DocumentScope: string
{
    case Tenant = 'tenant';
    case Branch = 'branch';
    case Terminal = 'terminal';
}
