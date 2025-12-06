<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    public function __construct(
        private string $targetDirectory,
        private SluggerInterface $slugger,
    ) {}

    public function upload(UploadedFile $file): array
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $storedFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        // Get file info BEFORE moving it
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        try {
            $file->move($this->targetDirectory, $storedFilename);
        } catch (FileException $e) {
            throw new \RuntimeException('Failed to upload file: ' . $e->getMessage());
        }

        return [
            'filename' => $file->getClientOriginalName(),
            'storedFilename' => $storedFilename,
            'mimeType' => $mimeType,
            'size' => $size,
        ];
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function delete(string $storedFilename): void
    {
        $filePath = $this->targetDirectory . '/' . $storedFilename;

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
