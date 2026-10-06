<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\ZkAdmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller handling real-time push events from ZKTeco MB360 biometric terminals.
 * Uses ZKTeco iClock / ADMS protocol.
 */
class ZkAdmsController extends Controller
{
    public function __construct(
        private readonly ZkAdmsService $admsService
    ) {}

    /**
     * Handle incoming cdata requests (Handshake on GET, Log push on POST).
     */
    public function cdata(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $result = $this->admsService->handleAttendancePush($request);

            return response($result['response'], 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // GET request is device handshake/options negotiation
        $responseContent = $this->admsService->handleHandshake($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Device polling for pending commands from server.
     */
    public function getrequest(Request $request): Response
    {
        $responseContent = $this->admsService->handleGetRequest($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Device reporting command completion back to server.
     */
    public function devicecmd(Request $request): Response
    {
        $responseContent = $this->admsService->handleDeviceCmd($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
