<?php
use App\Domain\Access;
use App\Reliability\Usage;
use App\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
$usageView=function(?int $lesson,array $filters=[]):array{$policies=Settings::policies();$v=Usage::summary($lesson,$policies['prices']??[],$filters);$v['audio']['limits']=array_intersect_key($policies,array_flip(['max_lesson_seconds','max_daily_audio_seconds','warning_ratio','max_active_lessons']));return $v;};
Route::get('/api/lessons/{lesson}/usage',function(Request $r,int $lesson)use($usageView){$u=Access::user((int)$r->session()->get('uid'));Access::member($u);Access::lesson($lesson,$u,$r->session()->getId());return $usageView($lesson);})->whereNumber('lesson');
Route::get('/api/admin/usage',function(Request $r)use($usageView){$u=Access::user((int)$r->session()->get('uid'));Access::admin($u);$filters=$r->validate(['course_id'=>'sometimes|integer|min:1','lesson_id'=>'sometimes|integer|min:1','from'=>'sometimes|date_format:Y-m-d','to'=>'sometimes|date_format:Y-m-d'.($r->filled('from')?'|after_or_equal:from':'')]);return $usageView(isset($filters['lesson_id'])?(int)$filters['lesson_id']:null,$filters);});
