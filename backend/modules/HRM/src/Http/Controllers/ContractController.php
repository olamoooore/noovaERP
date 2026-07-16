<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\StoreContractRequest;
use Modules\HRM\Http\Requests\UpdateContractRequest;
use Modules\HRM\Http\Resources\ContractResource;
use Modules\HRM\Models\Contract;

class ContractController extends Controller
{
    public function index(): JsonResponse
    {
        $contracts = Contract::with(['employee', 'timeshift'])
            ->latest()
            ->paginate(20);

        return ContractResource::collection($contracts)->response();
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        $contract = Contract::create($request->validated());
        $contract->load(['employee', 'timeshift']);

        return (new ContractResource($contract))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contract $contract): JsonResponse
    {
        $contract->load(['employee', 'timeshift']);

        return (new ContractResource($contract))->response();
    }

    public function update(UpdateContractRequest $request, Contract $contract): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['status']) && $data['status'] !== $contract->status) {
            $allowed = [
                'draft' => ['onboarding', 'active'],
                'onboarding' => ['active', 'terminated'],
                'active' => ['offboarding'],
                'offboarding' => ['terminated'],
                'terminated' => [],
            ];

            $from = $contract->status;
            $to = $data['status'];

            if (! in_array($to, $allowed[$from] ?? [], true)) {
                return response()->json([
                    'message' => "Invalid status transition: cannot move from '{$from}' to '{$to}'.",
                    'allowed' => $allowed[$from] ?? [],
                ], 422);
            }
        }

        $contract->update($data);
        $contract->load(['employee', 'timeshift']);

        return (new ContractResource($contract))->response();
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $contract->delete();

        return response()->json(null, 204);
    }
}
