<?php

namespace Workbench\App\Containers\MySection\Book\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Workbench\App\Containers\Identity\User\Models\User;
use Workbench\App\Ship\Parents\Models\Model as ParentModel;

class Book extends ParentModel
{
    use SoftDeletes;

    protected $fillable = [
        'author_id',
        'title',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }
}
