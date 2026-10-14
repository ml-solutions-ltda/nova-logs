<?php

namespace MlSolutions\NovaLogViewer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use MlSolutions\NovaLogViewer\Tool;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $tool = collect(Nova::registeredTools())->first(fn ($tool) => $tool instanceof Tool);
        $user = $request->user();
        $allowed = $user && ((function_exists('isAdmin') && isAdmin()) || (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('viewAlarmDashboard')));
        abort_unless($tool && $allowed && $tool->authorize($request), 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
