<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ReceiptSettingsController extends Controller
{
    public function show()
    {
        $settings = Setting::getValue('receipt_settings', [
            'store_name' => '',
            'store_address' => '',
            'store_phone' => '',
            'footer_text' => 'Thank you for shopping with us!',
            'logo_url' => null,
            'show_logo' => false,
            // Layout options
            'paper_width' => '80mm',
            'font_size' => 'medium',
            'line_spacing' => 'normal',
            'header_alignment' => 'center',
            'footer_alignment' => 'center',
            // Display options
            'show_receipt_number' => true,
            'show_date' => true,
            'show_cashier' => true,
            'show_tax_breakdown' => true,
            'show_qr_code' => false,
            'qr_code_content' => 'receipt_url',
        ]);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_name' => 'nullable|string|max:255',
            'store_address' => 'nullable|string|max:500',
            'store_phone' => 'nullable|string|max:50',
            'footer_text' => 'nullable|string|max:500',
            'show_logo' => 'boolean',
            // Layout options
            'paper_width' => 'nullable|in:58mm,80mm',
            'font_size' => 'nullable|in:small,medium,large',
            'line_spacing' => 'nullable|in:compact,normal,relaxed',
            'header_alignment' => 'nullable|in:left,center,right',
            'footer_alignment' => 'nullable|in:left,center,right',
            // Display options
            'show_receipt_number' => 'boolean',
            'show_date' => 'boolean',
            'show_cashier' => 'boolean',
            'show_tax_breakdown' => 'boolean',
            'show_qr_code' => 'boolean',
            'qr_code_content' => 'nullable|in:receipt_url,store_website,custom',
            'qr_code_custom_url' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentSettings = Setting::getValue('receipt_settings', []);
        
        $newSettings = array_merge($currentSettings, $request->only([
            'store_name',
            'store_address',
            'store_phone',
            'footer_text',
            'show_logo',
            'paper_width',
            'font_size',
            'line_spacing',
            'header_alignment',
            'footer_alignment',
            'show_receipt_number',
            'show_date',
            'show_cashier',
            'show_tax_breakdown',
            'show_qr_code',
            'qr_code_content',
            'qr_code_custom_url',
        ]));

        // Preserve logo_url
        $newSettings['logo_url'] = $currentSettings['logo_url'] ?? null;

        Setting::setValue('receipt_settings', $newSettings);

        return response()->json([
            'success' => true,
            'data' => $newSettings,
            'message' => 'Receipt settings updated successfully',
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentSettings = Setting::getValue('receipt_settings', []);
            if (!empty($currentSettings['logo_url'])) {
                $oldPath = str_replace('/storage/', '', $currentSettings['logo_url']);
                Storage::disk('public')->delete($oldPath);
            }

            $file = $request->file('logo');
            $filename = 'receipt_logo_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('receipts', $filename, 'public');
            $url = '/storage/' . $path;

            $newSettings = array_merge($currentSettings, [
                'logo_url' => $url,
            ]);
            Setting::setValue('receipt_settings', $newSettings);

            return response()->json([
                'success' => true,
                'data' => [
                    'logo_url' => $url,
                ],
                'message' => 'Logo uploaded successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload logo: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteLogo()
    {
        try {
            $currentSettings = Setting::getValue('receipt_settings', []);
            
            if (!empty($currentSettings['logo_url'])) {
                $oldPath = str_replace('/storage/', '', $currentSettings['logo_url']);
                Storage::disk('public')->delete($oldPath);
            }

            $newSettings = array_merge($currentSettings, [
                'logo_url' => null,
                'show_logo' => false,
            ]);
            Setting::setValue('receipt_settings', $newSettings);

            return response()->json([
                'success' => true,
                'message' => 'Logo deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete logo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
