<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Models\Hostname;
use App\PublicSites\WebsiteHostnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\WebsiteBuilder\Models\Website;

final class WebsiteDomainController extends Controller
{
    public function __construct(private readonly WebsiteHostnameService $service) {}

    public function index(Request $request, int $website): JsonResponse
    {
        $site = Website::findOrFail($website);
        return response()->json(['data' => $this->query($site)->orderByDesc('created_at')->get()->map(fn (Hostname $h) => $this->resource($h))]);
    }

    public function store(Request $request, int $website): JsonResponse
    {
        $site = Website::findOrFail($website);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253'], 'primary_requested' => ['sometimes', 'boolean']]);
        $actor = RequirePermission::resolveCentralUserId($request->user());
        $record = $this->service->create($site, $data['hostname'], (bool) ($data['primary_requested'] ?? false), $actor);

        return response()->json(['data' => $this->resource($record, true)], 202);
    }

    public function show(int $website, int $hostname): JsonResponse
    {
        $site = Website::findOrFail($website);
        $record = $this->query($site)->findOrFail($hostname);
        return response()->json(['data' => $this->resource($record, true)]);
    }

    public function verify(int $website, int $hostname): JsonResponse
    {
        $site = Website::findOrFail($website);
        $record = $this->query($site)->findOrFail($hostname);
        $this->service->queueVerification($record);
        return response()->json(['message' => 'DNS verification queued.', 'data' => $this->resource($record)], 202);
    }

    public function makePrimary(int $website, int $hostname): JsonResponse
    {
        $site = Website::findOrFail($website);
        $tenantId = (string) tenant('id');
        $record = DB::transaction(function () use ($site, $hostname, $tenantId) {
            $records = $this->query($site)->lockForUpdate()->get();
            $target = $records->firstWhere('id', $hostname);
            abort_unless($target, 404);
            $this->query($site)->update(['primary_requested' => false]);
            $target->primary_requested = true;
            if ($target->serving_status === 'active') {
                $this->query($site)->update(['primary_effective' => false]);
                $target->primary_effective = true;
            }
            $target->lock_version++;
            $target->save();
            return $target->fresh();
        });
        return response()->json(['data' => $this->resource($record)]);
    }

    public function move(Request $request, int $website, int $hostname): JsonResponse
    {
        $source = Website::findOrFail($website);
        $data = $request->validate(['destination_website_id' => ['required', 'integer', Rule::exists('wb_websites', 'id')]]);
        $destination = Website::findOrFail($data['destination_website_id']);
        abort_if($destination->status !== 'published', 422, 'Destination website must be published.');
        $record = $this->query($source)->findOrFail($hostname);
        $record->update(['website_uuid' => $destination->uuid, 'primary_requested' => false, 'primary_effective' => false, 'lock_version' => $record->lock_version + 1]);
        return response()->json(['data' => $this->resource($record->fresh())]);
    }

    public function destroy(Request $request, int $website, int $hostname): JsonResponse
    {
        $site = Website::findOrFail($website);
        $record = $this->query($site)->findOrFail($hostname);
        $this->service->requestRelease($record, RequirePermission::resolveCentralUserId($request->user()));
        return response()->json(['message' => 'Domain disabled and queued for release.'], 202);
    }

    private function query(Website $website)
    {
        return Hostname::query()->where('tenant_id', (string) tenant('id'))->where('website_uuid', $website->uuid)->where('purpose', 'website');
    }

    private function resource(Hostname $hostname, bool $instructions = false): array
    {
        $data = $hostname->only(['id', 'hostname', 'website_uuid', 'primary_requested', 'primary_effective', 'ownership_status', 'routing_status', 'tls_status', 'serving_status', 'verified_at', 'last_ownership_check_at', 'failure_code', 'failure_message', 'lock_version', 'created_at']);
        if ($instructions && $hostname->serving_status === 'pending') {
            $data['dns_instructions'] = $this->service->dnsInstructions($hostname);
        }
        return $data;
    }
}
