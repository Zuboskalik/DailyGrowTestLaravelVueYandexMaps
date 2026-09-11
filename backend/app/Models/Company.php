<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'url',
        'normalized_url',
        'yandex_id',
        'name',
        'rating',
        'reviews_count',
        'ratings_count',
        'parse_status',
        'last_parsed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'reviews_count' => 'integer',
            'ratings_count' => 'integer',
            'last_parsed_at' => 'datetime',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function parsingLogs(): HasMany
    {
        return $this->hasMany(ParsingLog::class);
    }
}
