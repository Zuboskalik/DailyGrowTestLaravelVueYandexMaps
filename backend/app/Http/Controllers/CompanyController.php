<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Jobs\ParseYandexCompanyJob;
use App\Models\Company;
use App\Support\YandexOrganizationUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * MVP scope (plan.md §5): companies are shared across all authenticated
 * users, there is no per-user ownership yet.
 */
class CompanyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CompanyResource::collection(
            Company::orderByDesc('created_at')->get()
        );
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $url = $request->validated()['url'];
        $normalizedUrl = YandexOrganizationUrl::normalize($url);
        $yandexId = YandexOrganizationUrl::extractId($url);

        $existing = Company::where('normalized_url', $normalizedUrl)
            ->when($yandexId, fn ($query) => $query->orWhere('yandex_id', $yandexId))
            ->first();

        if ($existing) {
            return (new CompanyResource($existing))
                ->response()
                ->setStatusCode(200);
        }

        $company = Company::create([
            'url' => $url,
            'normalized_url' => $normalizedUrl,
            'yandex_id' => $yandexId,
            'parse_status' => 'idle',
            'reviews_count' => 0,
        ]);

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
    }

    public function parse(Company $company): JsonResponse
    {
        $hasActiveRun = $company->parsingLogs()
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasActiveRun) {
            return response()->json([
                'message' => 'A parsing run is already in progress for this company.',
            ], 409);
        }

        $log = ParseYandexCompanyJob::dispatchFor($company);

        return response()->json([
            'message' => 'Parsing started.',
            'parsing_log_id' => $log->id,
        ], 202);
    }
}
