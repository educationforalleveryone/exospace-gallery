<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'survey_type',
        'score',
        'feedback',
        'triggered_at',
        'responded_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function npsCategory(): string
    {
        if ($this->score <= 6) return 'detractor';
        if ($this->score <= 8) return 'passive';
        return 'promoter';
    }
}
