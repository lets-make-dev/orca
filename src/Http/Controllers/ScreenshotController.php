<?php

namespace MakeDev\Orca\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScreenshotController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! app()->isLocal()) {
            abort(403);
        }

        $request->validate([
            'screenshot' => [
                'required',
                'file',
                'mimes:png',
                'max:'.config('orca.screenshots.max_size_kb', 10240),
            ],
        ]);

        $directory = config('orca.screenshots.directory', 'orca/screenshots');
        $disk = config('orca.screenshots.disk', 'local');
        $filename = Str::ulid().'.png';

        $path = $request->file('screenshot')->storeAs($directory, $filename, $disk);

        $absolutePath = Storage::disk($disk)->path($path);

        return response()->json([
            'path' => $absolutePath,
        ]);
    }
}
