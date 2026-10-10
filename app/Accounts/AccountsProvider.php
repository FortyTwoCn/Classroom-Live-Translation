<?php
namespace App\Accounts;
use Illuminate\Support\ServiceProvider;
final class AccountsProvider extends ServiceProvider {
 public function boot():void {
  $this->app->make('session')->extend('database',function($app){return new AccountSessionHandler($app['db']->connection(config('session.connection')),config('session.table','sessions'),config('session.lifetime',120),$app);});
  $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class,function($schedule){$schedule->call(fn()=>\Illuminate\Support\Facades\DB::table('session_revocations')->where('expires_at','<=',now())->delete())->hourly()->name('session-revocations-cleanup')->withoutOverlapping();});
 }
}
