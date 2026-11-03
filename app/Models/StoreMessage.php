<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled' => 'boolean'];
    }
}
