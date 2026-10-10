<?php
declare(strict_types=1);
namespace App\Content;
use App\Domain\{Access,Failure};
use Illuminate\Support\Facades\DB;

/** Call inside the lesson transaction; serialize mutation against account revocation. */
final class MutationActor
{
    public static function lock(?object $initial,bool $admin=false):object
    {
        Access::member($initial);
        DB::table('users')->where('id',$initial->id)->lockForUpdate()->first();
        $fresh=Access::user((int)$initial->id);Access::member($fresh);
        if(isset($initial->auth_version)&&(int)$fresh->auth_version!==(int)$initial->auth_version)throw new Failure('session_revoked',401);
        if($admin)Access::admin($fresh);
        return $fresh;
    }
}
