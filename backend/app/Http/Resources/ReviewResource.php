<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Review */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_name' => $this->author_name,
            'rating' => $this->rating,
            'text' => $this->text,
            'has_text' => filled($this->text),
            'review_created_at' => $this->review_created_at?->toIso8601String(),
        ];
    }
}
