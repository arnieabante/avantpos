<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PSGC reference data (global, read-only).
 */
class PhCity extends Model
{
    protected $table = 'ph_cities';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
