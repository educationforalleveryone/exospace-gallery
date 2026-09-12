<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWebhook extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'message_type',
        'invoice_id',
        'payload',
        'status',
        'processed_at',
        'replay_count',
        'last_replayed_at',
        'updated_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'processed_at'    => 'datetime',
        'last_replayed_at'=> 'datetime',
        'updated_at'      => 'datetime',
    ];
}
