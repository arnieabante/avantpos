<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Global permission (catalogue seeded from config/avantpos/permissions.php).
 */
class Permission extends SpatiePermission
{
    protected $fillable = ['name', 'guard_name', 'group', 'label'];
}
