<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebCustomerController extends Controller
{
    /**
     * Get customer with addresses
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'addresses' => $customer->addresses->map(fn ($addr) => $this->formatAddressResponse($addr)),
            ],
        ]);
    }

    /**
     * List customer addresses
     */
    public function addresses(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $addresses = $customer->addresses()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'addresses' => $addresses->map(fn ($addr) => $this->formatAddressResponse($addr)),
        ]);
    }

    /**
     * Add new address
     */
    public function addAddress(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'type' => 'required|in:shipping,billing',
            'name' => 'required|string|max:100',
            'company' => 'nullable|string|max:100',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'is_default' => 'boolean',
        ]);

        $address = $customer->addresses()->create([
            'type' => $validated['type'],
            'label' => $validated['name'],
            'street' => $validated['address'],
            'city' => $validated['city'],
            'state' => $validated['state'] ?? null,
            'zip' => $validated['postal_code'],
            'country' => $validated['country'],
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return response()->json([
            'success' => true,
            'address' => $this->formatAddressResponse($address),
        ], 201);
    }

    /**
     * Update address
     */
    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $address = $customer->addresses()->find($id);

        if (! $address) {
            return response()->json([
                'error' => 'Address not found',
            ], 404);
        }

        $validated = $request->validate([
            'type' => 'required|in:shipping,billing',
            'name' => 'required|string|max:100',
            'company' => 'nullable|string|max:100',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'is_default' => 'boolean',
        ]);

        $address->update([
            'type' => $validated['type'],
            'label' => $validated['name'],
            'street' => $validated['address'],
            'city' => $validated['city'],
            'state' => $validated['state'] ?? null,
            'zip' => $validated['postal_code'],
            'country' => $validated['country'],
            'is_default' => $validated['is_default'] ?? $address->is_default,
        ]);

        return response()->json([
            'success' => true,
            'address' => $this->formatAddressResponse($address),
        ]);
    }

    /**
     * Delete address
     */
    public function deleteAddress(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $address = $customer->addresses()->find($id);

        if (! $address) {
            return response()->json([
                'error' => 'Address not found',
            ], 404);
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully',
        ]);
    }

    /**
     * Set address as default
     */
    public function setDefaultAddress(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $address = $customer->addresses()->find($id);

        if (! $address) {
            return response()->json([
                'error' => 'Address not found',
            ], 404);
        }

        $address->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'address' => $this->formatAddressResponse($address),
        ]);
    }

    /**
     * Format address for API response
     */
    private function formatAddressResponse(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'type' => $address->type,
            'name' => $address->label,
            'company' => null,
            'address' => $address->street,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->zip,
            'country' => $address->country,
            'phone' => null,
            'is_default' => $address->is_default,
        ];
    }
}
