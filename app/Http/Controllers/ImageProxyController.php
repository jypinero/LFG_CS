<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageProxyController extends Controller
{
    /**
     * Proxy image requests from storage
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function proxy(Request $request)
    {
        $url = $request->query('url');
        
        if (!$url) {
            return response()->json([
                'status' => 'error',
                'message' => 'URL parameter is required'
            ], 400);
        }

        try {
            // Decode the URL
            $decodedUrl = urldecode($url);
            
            // Extract the storage path from the URL
            // Handle both full URLs (https://domain.com/storage/path) and relative paths (/storage/path)
            $path = $this->extractStoragePath($decodedUrl);
            
            if (!$path) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid storage path'
                ], 400);
            }

            // Check if file exists in public storage
            if (!Storage::disk('public')->exists($path)) {
                Log::warning('Image not found in storage', ['path' => $path, 'url' => $url]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Image not found'
                ], 404);
            }

            // Get the file
            $file = Storage::disk('public')->get($path);
            
            // Determine content type based on file extension
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
            ];
            $contentType = $mimeTypes[$extension] ?? 'image/jpeg';

            // Return the image with proper headers
            return response($file, 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'public, max-age=31536000')
                ->header('Content-Disposition', 'inline');
                
        } catch (\Exception $e) {
            Log::error('Image proxy error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load image'
            ], 500);
        }
    }

    /**
     * Extract storage path from URL
     * 
     * @param string $url
     * @return string|null
     */
    private function extractStoragePath(string $url): ?string
    {
        // Remove query parameters if any
        $url = parse_url($url, PHP_URL_PATH);
        
        if (!$url) {
            return null;
        }

        // Remove leading slash if present
        $url = ltrim($url, '/');
        
        // If URL starts with 'storage/', remove it (we need just the path after storage/)
        if (strpos($url, 'storage/') === 0) {
            $url = substr($url, 8); // Remove 'storage/' (8 characters)
        }
        
        // Security: Prevent directory traversal
        if (strpos($url, '..') !== false) {
            return null;
        }
        
        return $url ?: null;
    }
}
