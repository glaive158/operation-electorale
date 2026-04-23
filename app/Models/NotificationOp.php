<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationOp extends Model
{
    protected $table = 'notifications_ops';

    protected $fillable = ['user_id','titre','message','type','operation_id','lue'];

    protected function casts(): array
    {
        return ['lue' => 'boolean'];
    }
}
