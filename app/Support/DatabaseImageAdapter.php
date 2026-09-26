<?php

namespace App\Support;

use App\Models\ArchivedImage;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * A filesystem whose files are rows in `archived_images`.
 *
 * The production host wipes its disk on every deploy, and an archived
 * thumbnail that can vanish is not archived. Behind the `images` disk the app
 * keeps writing, reading and deleting by path exactly as it did with files.
 *
 * Directories are implied by paths, so creating one is a no-op, and there is
 * no visibility: everything here is served only to the signed-in user.
 */
class DatabaseImageAdapter implements FilesystemAdapter
{
    public function fileExists(string $path): bool
    {
        return ArchivedImage::query()->where('path', $path)->exists();
    }

    public function directoryExists(string $path): bool
    {
        return ArchivedImage::query()->where('path', 'like', $this->prefix($path).'%')->exists();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        ArchivedImage::query()->updateOrCreate(['path' => $path], [
            'contents' => base64_encode($contents),
            'mime_type' => (new FinfoMimeTypeDetector)->detectMimeType($path, $contents) ?? 'application/octet-stream',
            'size' => strlen($contents),
        ]);
    }

    /**
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $image = $this->find($path) ?? throw UnableToReadFile::fromLocation($path, 'No such archived image.');

        return (string) base64_decode($image->contents, true);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not open a temporary stream.');
        }

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        ArchivedImage::query()->where('path', $path)->delete();
    }

    public function deleteDirectory(string $path): void
    {
        ArchivedImage::query()->where('path', 'like', $this->prefix($path).'%')->delete();
    }

    public function createDirectory(string $path, Config $config): void
    {
        //
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Archived images have no visibility.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Archived images have no visibility.');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->attributes($path, UnableToRetrieveMetadata::mimeType(...));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->attributes($path, UnableToRetrieveMetadata::lastModified(...));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->attributes($path, UnableToRetrieveMetadata::fileSize(...));
    }

    /**
     * @return iterable<FileAttributes|DirectoryAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $this->prefix($path);

        $images = ArchivedImage::query()
            ->select(['path', 'mime_type', 'size', 'updated_at'])
            ->where('path', 'like', $prefix.'%')
            ->orderBy('path')
            ->lazy();

        foreach ($images as $image) {
            if (! $deep && str_contains(substr($image->path, strlen($prefix)), '/')) {
                continue;
            }

            yield $this->toAttributes($image);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }

    private function find(string $path): ?ArchivedImage
    {
        return ArchivedImage::query()->where('path', $path)->first();
    }

    /**
     * @param  callable(string, string): UnableToRetrieveMetadata  $failure
     */
    private function attributes(string $path, callable $failure): FileAttributes
    {
        $image = ArchivedImage::query()
            ->select(['path', 'mime_type', 'size', 'updated_at'])
            ->where('path', $path)
            ->first();

        if ($image === null) {
            throw $failure($path, 'No such archived image.');
        }

        return $this->toAttributes($image);
    }

    private function toAttributes(ArchivedImage $image): FileAttributes
    {
        return new FileAttributes(
            $image->path,
            $image->size,
            null,
            $image->updated_at?->getTimestamp(),
            $image->mime_type,
        );
    }

    private function prefix(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : $path.'/';
    }
}
