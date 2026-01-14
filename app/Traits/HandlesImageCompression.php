<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

trait HandlesImageCompression
{
    /**
     * Compress and store an uploaded image
     * 
     * @param UploadedFile $file The uploaded file
     * @param string $directory Storage directory (e.g., 'userpfp', 'team_photos')
     * @param int $maxWidth Maximum width in pixels (default: 1920)
     * @param int $maxHeight Maximum height in pixels (default: 1920)
     * @param int $quality JPEG quality 1-100 (default: 85)
     * @param string|null $fileName Custom filename (optional)
     * @return string The stored file path
     */
    protected function compressAndStoreImage(
        UploadedFile $file,
        string $directory,
        int $maxWidth = 1920,
        int $maxHeight = 1920,
        int $quality = 85,
        ?string $fileName = null
    ): string {
        try {
            // Generate filename if not provided
            if (!$fileName) {
                $extension = $file->getClientOriginalExtension();
                $fileName = time() . '_' . uniqid() . '.' . $extension;
            }

            // Create image manager with GD driver
            $manager = new ImageManager(new Driver());

            // Read the image
            $image = $manager->read($file->getRealPath());

            // Get original dimensions
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            // Calculate new dimensions maintaining aspect ratio
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            
            // Only resize if image is larger than max dimensions
            if ($ratio < 1) {
                $newWidth = (int) ($originalWidth * $ratio);
                $newHeight = (int) ($originalHeight * $ratio);
                
                $image->scale($newWidth, $newHeight);
            }

            // Convert to appropriate format and compress
            $mimeType = $file->getMimeType();
            
            // For JPEG images, apply quality compression
            if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
                $image->toJpeg($quality);
            } 
            // For PNG images, try to reduce file size (PNG compression is less effective)
            elseif ($mimeType === 'image/png') {
                $image->toPng();
            }
            // For other formats, convert to JPEG for better compression
            else {
                $image->toJpeg($quality);
                $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.jpg';
            }

            // Store the compressed image
            $path = $directory . '/' . $fileName;
            Storage::disk('public')->put($path, (string) $image);

            return $path;
        } catch (\Exception $e) {
            // Fallback to original storage if compression fails
            \Log::warning('Image compression failed, using original file', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ]);
            
            $fileName = $fileName ?: time() . '_' . $file->getClientOriginalName();
            return $file->storeAs($directory, $fileName, 'public');
        }
    }

    /**
     * Check if file is an image
     * 
     * @param UploadedFile $file
     * @return bool
     */
    protected function isImage(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();
        return str_starts_with($mimeType, 'image/');
    }
}
