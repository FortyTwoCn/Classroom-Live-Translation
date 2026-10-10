<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {})
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn(Request $r) => $r->is('api/*'));
        $exceptions->render(function (App\Domain\Failure $e, Request $r) { return response()->json(['code'=>$e->errorCode,'params'=>$e->params],$e->httpStatus); });
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Request $r) { if ($r->is('api/*')) return response()->json(['code'=>'validation_failed','params'=>['fields'=>array_keys($e->errors())]],422); });
    })->create();
