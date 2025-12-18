<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'folder' => 'nullable|string|max:100',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $folder = $validated['folder'] ?? 'uploads';
            $folder = trim($folder, '/');
            if ($folder === '') {
                $folder = 'uploads';
            }
            
            // Generate a unique filename
            $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
            
            // Store the file in the public disk
            $path = $file->storeAs($folder, $filename, 'public');
            
            // Return the full URL
            return response()->json([
                'path' => $path,
                'url' => Storage::url($path),
            ]);
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }
}
