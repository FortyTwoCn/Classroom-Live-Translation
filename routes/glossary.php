<?php
use App\Accounts\DeviceAuth;
use App\Domain\{Access,Failure};
use App\Glossary\Glossary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
$glossaryActor=function(Request $r,bool $recent=false){$u=Access::user((int)$r->session()->get('uid'));Access::admin($u);if($recent)DeviceAuth::requireRecent($r);return $u;};
Route::get('/api/admin/courses/{course}/glossary',function(Request $r,int $course)use($glossaryActor){$u=$glossaryActor($r);$v=$r->validate(['page'=>'sometimes|integer|min:1|max:100000','source_language'=>'nullable|string|max:10','target_language'=>'nullable|string|max:10','search'=>'nullable|string|max:200','enabled'=>'nullable|boolean','deleted'=>'sometimes|in:active,only,all']);return app(Glossary::class)->listing($course,$u,$v);})->whereNumber('course');
Route::post('/api/admin/courses/{course}/glossary/copy',function(Request $r,int $course)use($glossaryActor){$u=$glossaryActor($r,true);if($r->input('confirmed')!==true)throw new Failure('confirmation_required');$v=$r->validate(['source_course_id'=>'required|integer|min:1','expected_glossary_version'=>'required|integer|min:1']);return app(Glossary::class)->copy((int)$v['source_course_id'],$course,$u,(int)$v['expected_glossary_version']);})->whereNumber('course');
$glossaryValues=function(Request $r,bool $create):array{$rule=$create?'required':'sometimes|required';$v=$r->validate(['source_language'=>$rule.'|string|max:10','target_language'=>$rule.'|string|max:10','source_text'=>$rule.'|string|max:1000','target_text'=>$rule.'|string|max:1000','note'=>'sometimes|nullable|string|max:2000','enabled'=>'sometimes|boolean','expected_glossary_version'=>'required|integer|min:1']);if(array_key_exists('note',$v)&&$v['note']===null)$v['note']='';return $v;};
Route::post('/api/admin/courses/{course}/glossary',function(Request $r,int $course)use($glossaryActor,$glossaryValues){$u=$glossaryActor($r,true);$v=$glossaryValues($r,true);return app(Glossary::class)->save($course,null,$u,$v,(int)$v['expected_glossary_version']);})->whereNumber('course');
Route::patch('/api/admin/courses/{course}/glossary/{term}',function(Request $r,int $course,int $term)use($glossaryActor,$glossaryValues){$u=$glossaryActor($r,true);$v=$glossaryValues($r,false);return app(Glossary::class)->save($course,$term,$u,$v,(int)$v['expected_glossary_version']);})->whereNumber(['course','term']);
$glossaryDeletion=function(Request $r,int $course,int $term,bool $deleted)use($glossaryActor){$u=$glossaryActor($r,true);if($r->input('confirmed')!==true)throw new Failure('confirmation_required');$v=$r->validate(['expected_glossary_version'=>'required|integer|min:1']);return app(Glossary::class)->deletion($course,$term,$u,$deleted,(int)$v['expected_glossary_version']);};
Route::delete('/api/admin/courses/{course}/glossary/{term}',fn(Request $r,int $course,int $term)=>$glossaryDeletion($r,$course,$term,true))->whereNumber(['course','term']);
Route::post('/api/admin/courses/{course}/glossary/{term}/restore',fn(Request $r,int $course,int $term)=>$glossaryDeletion($r,$course,$term,false))->whereNumber(['course','term']);

