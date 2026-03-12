<?php

namespace MakeDev\Orca\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\PopOutTerminalService;

class PopOutController extends Controller
{
    public function returned(Request $request, PopOutTerminalService $service): JsonResponse
    {
        if (! app()->isLocal()) {
            abort(403);
        }

        $request->validate([
            'session_id' => 'required|string',
            'exit_code' => 'required|integer',
            'transcript_path' => 'nullable|string',
        ]);

        $session = OrcaSession::findOrFail($request->input('session_id'));

        $service->handleReturn(
            $session,
            (int) $request->input('exit_code'),
            $request->input('transcript_path'),
        );

        return response()->json(['status' => 'ok']);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        if (! app()->isLocal()) {
            abort(403);
        }

        $request->validate([
            'session_id' => 'required|string',
        ]);

        OrcaSession::where('id', $request->input('session_id'))
            ->where('status', OrcaSessionStatus::PoppedOut)
            ->update(['last_heartbeat_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
