<?php
require __DIR__.'/../../bin/bootstrap.php';
use Illuminate\Support\Facades\DB;
if(!app()->environment('testing')||getenv('RECOVERY_TEST_PROFILE')!=='1'||!preg_match('/^classroom_integration_[a-f0-9]{12}$/D',config('database.connections.pgsql.database')))throw new RuntimeException('isolated_recovery_fixture_required');
$lesson=(int)($argv[2]??0);$l=DB::table('lessons')->find($lesson);if(!$l)throw new RuntimeException('fixture_lesson_required');
if(!str_starts_with(DB::table('courses')->where('id',$l->course_id)->value('name'),'EXPLICIT RECOVERY MOCK'))throw new RuntimeException('fixture_course_required');
$action=$argv[1]??'';
if(in_array($action,['disable','enable'],true)){DB::table('users')->where('id',$l->creator_id)->update(['disabled'=>$action==='disable','auth_version'=>DB::raw('auth_version+1')]);echo json_encode(['action'=>$action,'status'=>'completed']);exit;}
if($action!=='readback')throw new RuntimeException('fixture_action_invalid');
$segments=DB::table('audio_segments')->where('lesson_id',$lesson)->orderBy('start_sample')->get();$total=0;$valid=true;$fixtureValid=true;$epochs=[];$actualHash=hash_init('sha256');$expectedHash=hash_init('sha256');
foreach($segments as$s){
 $valid=$valid&&(int)$s->start_sample===$total&&is_file($s->local_path)&&filesize($s->local_path)===(int)$s->sample_count*2&&hash_file('sha256',$s->local_path)===$s->sha256;
 $expected=str_repeat(pack('v',1000+(int)$s->sequence),(int)$s->sample_count);$actual=is_file($s->local_path)?file_get_contents($s->local_path):'';
 $fixtureValid=$fixtureValid&&is_string($actual)&&hash_equals(hash('sha256',$expected),(string)$s->sha256);
 hash_update($actualHash,$actual===false?'':$actual);hash_update($expectedHash,$expected);
 $total+=(int)$s->sample_count;$epochs[$s->epoch]=($epochs[$s->epoch]??0)+1;
}
echo json_encode(['status'=>$l->status,'media_samples'=>(int)$l->media_samples,'frames'=>$segments->count(),'frame_samples'=>$total,'local_hash_and_contiguous_timeline'=>$valid,'local_pcm_matches_sent_fixture'=>$fixtureValid,'persisted_stream_sha256'=>hash_final($actualHash),'expected_stream_sha256'=>hash_final($expectedHash),'epoch_frame_counts'=>$epochs,'model_statuses'=>DB::table('model_sessions')->where('lesson_id',$lesson)->orderBy('created_at')->pluck('status')->all(),'unfinished_model_count'=>DB::table('model_sessions')->where('lesson_id',$lesson)->whereNull('ended_at')->count(),'interrupted_units'=>DB::table('subtitle_units')->where('lesson_id',$lesson)->where('status','interrupted')->count(),'completed_units'=>DB::table('subtitle_units')->where('lesson_id',$lesson)->where('status','completed')->count(),'issues'=>DB::table('recording_issues')->where('lesson_id',$lesson)->get(['kind','start_sample','end_sample'])->all()],JSON_THROW_ON_ERROR);
