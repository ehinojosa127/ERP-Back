<?php

namespace App\Support\Inventory;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class PaymentReceiptStorage
{
    private const DISK = 'local';

    private const DIRECTORY = 'payment-receipts';

    /**
     * @return array{receipt_file_path: string, receipt_file_name: string, receipt_file_mime: string}|null
     */
    public static function store(?UploadedFile $file): ?array
    {
        if ($file === null) {
            return null;
        }

        $path = $file->store(self::DIRECTORY, self::DISK);

        return [
            'receipt_file_path' => $path,
            'receipt_file_name' => $file->getClientOriginalName(),
            'receipt_file_mime' => $file->getClientMimeType() ?? 'application/octet-stream',
        ];
    }

    public static function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
