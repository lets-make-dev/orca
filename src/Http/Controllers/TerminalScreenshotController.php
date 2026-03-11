<?php

namespace MakeDev\Orca\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\PopOutTerminalService;

class TerminalScreenshotController extends Controller
{
    public function show(string $session, PopOutTerminalService $service): Response
    {
        if (! app()->isLocal()) {
            abort(403);
        }

        $orcaSession = OrcaSession::query()->find($session);

        if (! $orcaSession instanceof OrcaSession || $orcaSession->status !== OrcaSessionStatus::PoppedOut) {
            abort(404);
        }

        $path = $service->screenshotPath($orcaSession);

        if (! file_exists($path)) {
            abort(404);
        }

        $image = imagecreatefrompng($path);
        $width = imagesx($image);
        $height = imagesy($image);

        // Crop: remove title bar (top ~4%) and scrollbar (right ~2%)
        $cropTop = 65;
        $cropRight = (int) round($width - 65);
        $newWidth = $width - $cropRight;
        $newHeight = $height - $cropTop;

        $cropped = imagecrop($image, [
            'x' => 0,
            'y' => $cropTop,
            'width' => $newWidth,
            'height' => $newHeight,
        ]);
        imagedestroy($image);

        ob_start();
        imagepng($cropped);
        $pngData = ob_get_clean();
        imagedestroy($cropped);

        return response($pngData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
