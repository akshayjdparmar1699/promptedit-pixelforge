<?php

// Add these to the existing app/Models/User.php (keep everything the starter kit already has).

use Illuminate\Database\Eloquent\Relations\HasMany;

// 1) Inside casts():
//        'balance_nanos' => 'integer',
//        'is_admin' => 'boolean',

// 2) New relation:
public function generations(): HasMany
{
    return $this->hasMany(\App\Models\Generation::class);
}
