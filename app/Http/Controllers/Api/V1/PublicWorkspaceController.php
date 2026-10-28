<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PublicWorkspaceResource;
use App\Services\PublicWorkspaceDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, read-only workspace directory for the Link Space website and app.
 * All eligibility decisions live in PublicWorkspaceDirectory (published +
 * readiness re-checked on every request); this controller only validates
 * input and shapes the response.
 */
class PublicWorkspaceController extends Controller
{
    public function __construct(private PublicWorkspaceDirectory $directory) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.per_page_max', 50)],
        ], ...$this->validationText());

        $perPage = (int) ($data['per_page'] ?? config('api.per_page_default', 12));
        $query = array_filter(['per_page' => $data['per_page'] ?? null, 'lang' => $request->query('lang')]);

        return PublicWorkspaceResource::collection(
            $this->directory->paginate($perPage, (int) ($data['page'] ?? 1), $query)
        );
    }

    /**
     * Messages + attribute names for the current API locale (app.api.validation).
     * Only Arabic defines them; English falls through to Laravel's defaults unchanged.
     *
     * @return array{0: array, 1: array}
     */
    private function validationText(): array
    {
        $messages = __('app.api.validation.messages');
        $attributes = __('app.api.validation.attributes');

        return [is_array($messages) ? $messages : [], is_array($attributes) ? $attributes : []];
    }

    public function show(string $slug): PublicWorkspaceResource|JsonResponse
    {
        // Same response for malformed, unknown and non-public slugs — never say why.
        $workspace = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) && strlen($slug) <= 140
            ? $this->directory->findBySlug($slug)
            : null;

        if (! $workspace) {
            return response()->json(['message' => __('app.api.not_found')], 404);
        }

        return (new PublicWorkspaceResource($workspace))->detailed();
    }
}
