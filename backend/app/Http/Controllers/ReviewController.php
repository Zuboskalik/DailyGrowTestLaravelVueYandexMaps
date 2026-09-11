<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function index(Company $company): AnonymousResourceCollection
    {
        $reviews = $company->reviews()
            ->orderByDesc('review_created_at')
            ->paginate(50);

        return ReviewResource::collection($reviews);
    }
}
