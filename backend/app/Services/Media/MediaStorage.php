<?php

namespace App\Services\Media;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores tenant images on the media disk under tenants/{tenant_id}/{folder}/
 * with random file names (never the client's file name).
 */
class MediaStorage
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('avantpos.app.media_disk'));
    }

    public function storeImage(UploadedFile $file, string $folder): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = sprintf('tenants/%d/%s/%s.%s', $this->tenant->requireId(), trim($folder, '/'), Str::random(32), $extension);

        $this->disk()->put($path, $file->getContent(), ['visibility' => 'public']);

        return $path;
    }

    /**
     * Private documents (expense receipts, supplier bills): never public,
     * served only through an authorised API endpoint.
     */
    public function documents(): Filesystem
    {
        return Storage::disk(config('avantpos.app.documents_disk', 'local'));
    }

    public function storeDocument(UploadedFile $file, string $folder): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'jpg',
        };
        $path = sprintf('tenants/%d/%s/%s.%s', $this->tenant->requireId(), trim($folder, '/'), Str::random(40), $extension);
        $this->documents()->put($path, $file->getContent());

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk()->url($path) : null;
    }
}
