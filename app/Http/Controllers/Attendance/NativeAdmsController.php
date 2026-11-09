<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\NativeAdmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NativeAdmsController extends Controller
{
    public function __construct(
        protected NativeAdmsService $admsService
    ) {}

    /**
     * Primary ADMS endpoint (GET /iclock/cdata for handshake, POST /iclock/cdata for punches).
     */
    public function cdata(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return response("ERROR: Missing SN\r\n", 400)->header('Content-Type', 'text/plain');
        }

        if ($request->isMethod('POST')) {
            $result = $this->admsService->handleDataPush($request, $sn);

            return response($result['response_text']."\r\n", 200)->header('Content-Type', 'text/plain');
        }

        $handshake = $this->admsService->handleHandshake($request, $sn);

        return response($handshake, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Device command polling and heartbeat endpoint (GET /iclock/getrequest).
     */
    public function getrequest(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return response("ERROR: Missing SN\r\n", 400)->header('Content-Type', 'text/plain');
        }

        $response = $this->admsService->handleGetRequest($request, $sn);

        return response($response."\r\n", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Device command acknowledgment endpoint (POST /iclock/devicecmd).
     */
    public function devicecmd(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return response("ERROR: Missing SN\r\n", 400)->header('Content-Type', 'text/plain');
        }

        $response = $this->admsService->handleDeviceCmd($request, $sn);

        return response($response."\r\n", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Device registry endpoint (GET / POST /iclock/registry).
     */
    public function registry(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return response("ERROR: Missing SN\r\n", 400)->header('Content-Type', 'text/plain');
        }

        $response = $this->admsService->handleRegistry($request, $sn);

        return response($response, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Biometric face / photo data upload endpoint (POST /iclock/fdata).
     */
    public function fdata(Request $request): Response
    {
        return response("OK\r\n", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Extract device serial number from query parameters, form input, or headers.
     */
    private function extractSerialNumber(Request $request): string
    {
        $sn = $request->query('SN')
            ?: $request->query('sn')
            ?: $request->input('SN')
            ?: $request->input('sn')
            ?: $request->header('X-Serial-Number')
            ?: '';

        return trim((string) $sn);
    }
}
