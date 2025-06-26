<?php

namespace Workbench\App\Containers\Identity\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notifiable;
use Workbench\App\Containers\MySection\Book\Models\Book;
use Workbench\App\Containers\SocialInteraction\Comment\Models\Comment;
use Workbench\App\Ship\Parents\Models\UserModel as ParentUserModel;

class User extends ParentUserModel
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'parent_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'author_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function profile(): HasOne
    {
        // Adjust the related model/foreign key as needed
        return $this->hasOne(Profile::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        // Adjust the related model/pivot table as needed
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }
}
