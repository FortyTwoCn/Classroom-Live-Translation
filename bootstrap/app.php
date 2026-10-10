<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {$middleware->web(append: [App\Accounts\AccountMiddleware::class]);})
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn(Request $r) => $r->is('api/*'));
        $exceptions->render(function (App\Domain\Failure $e, Request $r) { return response()->json(['code'=>$e->errorCode,'params'=>$e->params],$e->httpStatus); });
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Request $r) { if ($r->is('api/*')) return response()->json(['code'=>'validation_failed','params'=>['fields'=>array_keys($e->errors())]],422); });
    })->create();
$setStoragePath=static function(Application $app,?string $path):void {if(!$path)return;$absolute=preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~',$path);$app->useStoragePath($absolute?$path:$app->basePath($path));};
$setStoragePath($application,getenv('LARAVEL_STORAGE_PATH')?:null);
// The .env has not been loaded when Application is constructed. Apply its path
// before configuration computes view/session/cache directories.
$application->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,static function(Application $app)use($setStoragePath){$setStoragePath($app,env('LARAVEL_STORAGE_PATH'));});
return $application;
