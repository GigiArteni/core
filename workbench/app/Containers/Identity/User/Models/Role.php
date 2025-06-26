<?php

namespace Workbench\App\Containers\Identity\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name'];

    public function permissions(): BelongsToMany
    {
        // Adjust the related model/pivot table as needed
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id');
    }
}
