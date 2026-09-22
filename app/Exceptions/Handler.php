<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        //
    }

    /**
     * Always return JSON for this API-only application.
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            // Normalize framework exceptions first (ModelNotFoundException ->
            // 404 etc.); without this every firstOrFail() answers 500.
            $e = $this->prepareException($e);

            return $this->apiResponse($request, $e);
        }

        return parent::render($request, $e);
    }

    private function apiResponse(Request $request, Throwable $e): JsonResponse
    {
        // Phase 13: map framework auth exceptions to their proper HTTP
        // statuses. (AuthenticationException carries no getStatusCode(), so
        // without this mapping every guest API request answered 500.)
        $status = match (true) {
            $e instanceof \Illuminate\Auth\AuthenticationException => 401,
            $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
            $e instanceof \Illuminate\Validation\ValidationException => 422,
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
            method_exists($e, 'getStatusCode') => $e->getStatusCode(),
            default => 500,
        };

        // Never leak internals (SQL, file paths, stack context) to API
        // clients in production: a 500 with debug off answers with a generic
        // message. The real exception is still reported to the logs via
        // report() before render() runs.
        $message = $e->getMessage() ?: 'Server Error';
        if ($status >= 500 && !config('app.debug')) {
            $message = 'Server Error';
        }

        $payload = [
            'success' => false,
            'message' => $message,
        ];

        // Consistent error shape: validation failures always carry the
        // field-level errors map, matching the controllers' manual 422s.
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $payload['errors'] = $e->errors();
        }

        return response()->json($payload, $status ?: 500);
    }
}
