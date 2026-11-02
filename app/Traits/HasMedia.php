<?php

namespace App\Traits;

use App\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

trait HasMedia
{
    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function addMedia($file, $collection = 'default', $customName = null)
    {
        $fileName = $customName ?? $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: 'png';

        // Ensure filename has extension
        if (!str_contains($fileName, '.')) {
            $fileName .= '.' . $extension;
        }

        return $this->storeImageMedia($file->get(), $fileName, $collection);
    }

    /**
     * Download a remote image and attach it to a media collection.
     * Returns null when the URL can't be fetched or isn't an image.
     */
    public function addMediaFromUrl(string $url, $collection = 'default', $customName = null)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $headers = ['User-Agent' => 'HiteePlatform/1.0 (+https://hitee.ai)'];

        // Google photo/streetview thumbnails only respond to a browser-like
        // request referred from google.com; other hosts get an identifiable UA.
        if (str_contains($host, 'google')) {
            $headers['User-Agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
            $headers['Referer'] = 'https://www.google.com/';
        }

        $response = Http::timeout(20)->retry(2, 500)->withHeaders($headers)->get($url);

        if (!$response->successful()) {
            return null;
        }

        $mime = strtolower((string) $response->header('Content-Type'));
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $fileName = $customName ?? basename((string) parse_url($url, PHP_URL_PATH));
        if ($fileName === '') {
            $fileName = uniqid('media_');
        }
        if (!str_contains($fileName, '.')) {
            $fileName .= '.png';
        }

        return $this->storeImageMedia($response->body(), $fileName, $collection);
    }

    protected function storeImageMedia($contents, string $fileName, string $collection)
    {
        $folder = 'media/' . $collection;
        $path = $folder . '/' . uniqid() . '_' . $fileName;

        // Process Image with Intervention
        $manager = new ImageManager(new Driver());
        $image = $manager->decode($contents);

        // Define dimensions based on collection
        if ($collection === 'avatar') {
            $image->cover(500, 500); // Square crop for profile
        } elseif ($collection === 'featured') {
            $image->cover(800, 600); // 4:3 crop for featured
        } else {
            // Just resize to max width while maintaining aspect ratio for gallery
            $image->scale(width: 1200);
        }

        $encoded = $image->encodeUsingFileExtension('png')->toString();

        Storage::disk('public')->put($path, $encoded);

        return $this->media()->create([
            'file_path' => $path,
            'file_name' => $fileName,
            'collection_name' => $collection,
            'mime_type' => 'image/png',
            'size' => strlen($encoded),
        ]);
    }

    public function getFirstMediaUrl($collection = 'default', $default = null)
    {
        $media = $this->media()->where('collection_name', $collection)->first();
        return $media ? $media->url : ($default ?? asset('assets/img/no_image.png'));
    }

    public function clearMediaCollection($collection = 'default')
    {
        $medias = $this->media()->where('collection_name', $collection)->get();
        foreach ($medias as $media) {
            if (Storage::disk('public')->exists($media->file_path)) {
                Storage::disk('public')->delete($media->file_path);
            }
            $media->delete();
        }
    }
}
