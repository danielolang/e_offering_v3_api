<?php

use App\Helpers\ResponseStatusCodes;
use Symfony\Component\HttpFoundation\Response;

if (!function_exists('successResponse')) {
    function successResponse($message = "Successful.", $data = [])
    {
        return response()->json([
            "status" => true,
            "statusCode" => ResponseStatusCodes::OK,
            "message" => $message,
            "data" => $data,
        ], Response::HTTP_OK);
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse(int $statusCode = ResponseStatusCodes::BAD_REQUEST, string $message = "An error occurred.", $data = [], $code = Response::HTTP_UNPROCESSABLE_ENTITY)
    {
        return response()->json([
            "status" => false,
            "statusCode" => $statusCode,
            "message" => $message,
            "data" => $data,
        ], $code);
    }
}

if (!function_exists('frontendUrl')) {
    /** Absolute link into the Vue app, for emails and notifications. */
    function frontendUrl(string $path = '/'): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('signedFileUrl')) {
    /** A link to a private file that works without a bearer token and expires after an hour. */
    function signedFileUrl(string $route, $params): string
    {
        $params = is_array($params) ? array_values($params) : [$params];
        $names = ['documents.show' => ['document'], 'bids.evidence' => ['bid'], 'bids.commitment-file' => ['bid', 'file'], 'fees.evidence' => ['invoice'], 'institutions.file' => ['institution', 'index']];
        $named = array_combine($names[$route], $params);
        $named['uid'] = auth('api')->id();

        return \Illuminate\Support\Facades\URL::temporarySignedRoute($route, now()->addHour(), $named);
    }
}

if (!function_exists('screenPath')) {
    /** The React screen address for a named API route. */
    function screenPath(string $apiUrl): string
    {
        return preg_replace('#^https?://[^/]+/api#', '', $apiUrl) ?: '/';
    }
}

if (! function_exists('titleCase')) {
    /**
     * "Your onboarding request was approved" -> "Your Onboarding Request Was Approved", as the
     * web app's titleCase(): words with inner capitals (e-Offering, QPay, FMDQ, TSP/RM) are kept,
     * small joining words stay lower case unless they come first.
     */
    function titleCase(string $text): string
    {
        $small = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'with', 'from', 'vs'];
        $first = true;

        return (string) preg_replace_callback("/[A-Za-z][A-Za-z'’-]*/u", function ($m) use (&$first, $small) {
            $word = $m[0];
            if (preg_match('/[A-Z]/', substr($word, 1))) {
                $result = $word;
            } elseif (! $first && in_array(strtolower($word), $small, true)) {
                $result = strtolower($word);
            } else {
                $result = ucfirst($word);
            }
            $first = false;

            return $result;
        }, $text);
    }
}
