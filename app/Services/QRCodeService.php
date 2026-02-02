<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\Location;

/**
 * QR Code Service using QRServer API (free and reliable)
 */
class QRCodeService
{
    /**
     * Generate a QR code for a location using QRServer API.
     */
    public function generateForLocation(Location $location): string
    {
        $url = $location->qr_url;
        $qrUrl = $this->getQRServerUrl($url, 400);

        // Download and save the QR code
        $contents = file_get_contents($qrUrl);
        $filename = "qrcodes/{$location->qr_code}.png";
        Storage::disk('public')->put($filename, $contents);

        return $filename;
    }

    /**
     * Generate QR code URL for display (no download).
     */
    public function getQrUrl(Location $location, int $size = 300): string
    {
        return $this->getQRServerUrl($location->qr_url, $size);
    }

    /**
     * Get QRServer API QR code URL.
     * https://goqr.me/api/
     */
    private function getQRServerUrl(string $data, int $size = 300): string
    {
        $encodedData = urlencode($data);
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedData}&format=png";
    }

    /**
     * Generate QR code as inline image tag.
     */
    public function generateImageTag(Location $location, int $size = 300): string
    {
        $qrUrl = $this->getQrUrl($location, $size);
        return '<img src="' . $qrUrl . '" alt="QR Code - ' . e($location->name) . '" width="' . $size . '" height="' . $size . '">';
    }

    /**
     * Generate printable QR code sheet for multiple locations.
     */
    public function generatePrintSheet(array $locationIds): array
    {
        $locations = Location::whereIn('id', $locationIds)->get();
        $qrCodes = [];

        foreach ($locations as $location) {
            $qrCodes[] = [
                'location' => $location,
                'qr_url' => $this->getQrUrl($location, 400),
                'consume_url' => $location->qr_url,
            ];
        }

        return $qrCodes;
    }
}
