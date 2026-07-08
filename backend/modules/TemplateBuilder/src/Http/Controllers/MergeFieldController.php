<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Http\Resources\MergeFieldResource;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

class MergeFieldController extends Controller
{
    public function __construct(private readonly DataSourceRegistry $registry) {}

    public function catalog(string $documentType): JsonResponse
    {
        if (! $this->registry->has($documentType)) {
            return response()->json(['message' => "Unsupported document type '{$documentType}'."], 404);
        }

        $catalog = $this->registry->fieldCatalogFor($documentType);

        return response()->json([
            'document_type' => $documentType,
            'data' => MergeFieldResource::collection(collect($catalog)),
        ]);
    }

    public function sample(string $documentType): JsonResponse
    {
        if (! $this->registry->has($documentType)) {
            return response()->json(['message' => "Unsupported document type '{$documentType}'."], 404);
        }

        return response()->json([
            'document_type' => $documentType,
            'data' => $this->registry->sampleFor($documentType),
        ]);
    }
}
