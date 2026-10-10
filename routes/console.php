<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
Artisan::command('classroom:admin {student_id} {name}',function(){
 $id=(string)$this->argument('student_id'); if(mb_strlen($id)<6){$this->error('student_id must contain at least 6 characters');return 1;}
 $password=$this->secret('Set a unique admin password (at least 12 characters)');
 if(mb_strlen($password)<12||$password===$id||$password===mb_substr($id,-6)){ $this->error('Password is too short or predictable'); return 1; }
 DB::table('users')->insert(['student_id'=>$id,'name'=>$this->argument('name'),'role'=>'admin','password'=>Hash::make($password),'must_change_password'=>false]); $this->info('Admin created; no password printed.');
});
Artisan::command('classroom:user {student_id} {name}',function(){ $id=(string)$this->argument('student_id');if(mb_strlen($id)<6){$this->error('student_id must contain at least 6 characters');return 1;} DB::table('users')->insert(['student_id'=>$id,'name'=>$this->argument('name'),'role'=>'user','password'=>Hash::make(mb_substr($id,-6)),'must_change_password'=>true]);$this->info('User created. Require first password change.'); });
Artisan::command('classroom:archive {lesson}',function(){
 $id=(int)$this->argument('lesson');
 return DB::transaction(function()use($id){
  $l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();
  if(!$l||$l->status!=='ended'){$this->error('Lesson must have ended');return 1;}
  if($l->purge_status!==null){$this->error('Lesson is scheduled for data deletion');return 1;}
  if($l->archive_status==='failed'){DB::table('lessons')->where('id',$id)->whereNull('purge_status')->update(['archive_status'=>'pending','updated_at'=>now()]);App\Domain\Access::lessonEvent($id);}
  DB::table('audit')->insert(['lesson_id'=>$id,'action'=>'recording.archive_cli_requested','details'=>json_encode(['previous_status'=>$l->archive_status],JSON_THROW_ON_ERROR)]);
  App\Jobs\ArchiveLesson::dispatch($id)->afterCommit();$this->info('Archive queued');return 0;
 });
});
Schedule::call(fn()=>app(App\Exports\ExportService::class)->cleanup())->hourly()->name('private-export-cleanup')->withoutOverlapping();
Schedule::job(new App\Jobs\ApplyRetention)->daily()->name("retention-cleanup")->withoutOverlapping();
Schedule::job(new App\Jobs\ReconcileRecordings)->everyFiveMinutes()->name('recording-reconciliation')->withoutOverlapping();
