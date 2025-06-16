<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'task_id',
        'type',
        'file_id',
        'file_url',
        'original_name',
        'mime_type'
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
