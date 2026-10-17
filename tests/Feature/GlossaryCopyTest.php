<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Domain\{Access,Failure};
use App\Glossary\Glossary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GlossaryCopyTest extends TestCase
{
    use DatabaseTransactions;
    private object $admin;
    private int $source;
    private int $target;

    protected function setUp():void
    {
        parent::setUp();
        $id=DB::table('users')->insertGetId(['student_id'=>'copy-'.Str::uuid(),'name'=>'Copy fixture','password'=>'unused','role'=>'admin','must_change_password'=>false]);
        $this->admin=Access::user($id);
        $this->source=DB::table('courses')->insertGetId(['name'=>'Source']);
        $this->target=DB::table('courses')->insertGetId(['name'=>'Target']);
    }

    public function test_copies_all_pages_preserves_target_terms_and_leaves_existing_snapshots_unchanged():void
    {
        $g=new Glossary;
        $g->save($this->target,null,$this->admin,$this->term('airfoil','目标译法'),1);
        $lesson=DB::table('lessons')->insertGetId(['course_id'=>$this->target,'creator_id'=>$this->admin->id,'name'=>'Existing lesson','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
        $snapshot=Glossary::snapshot($lesson,$this->target,'zh');
        $g->save($this->source,null,$this->admin,$this->term('airfoil','来源译法'),1);
        for($i=0;$i<35;$i++)DB::table('course_terms')->insert($this->term('source-'.$i,'译文')+['course_id'=>$this->source]);
        DB::table('course_terms')->insert(array_replace($this->term('disabled','停用'),['course_id'=>$this->source,'enabled'=>false,'note'=>'保留备注 Ї Є']));
        DB::table('course_terms')->insert($this->term('deleted','已删除')+['course_id'=>$this->source,'deleted_at'=>now()]);
        $result=$g->copy($this->source,$this->target,$this->admin,2);
        self::assertSame(36,$result['copied']);
        self::assertSame(1,$result['skipped']);
        self::assertSame(3,$result['version']);
        self::assertSame('目标译法',DB::table('course_terms')->where('course_id',$this->target)->where('source_text','airfoil')->value('target_text'));
        $disabled=DB::table('course_terms')->where('course_id',$this->target)->where('source_text','disabled')->first();
        self::assertFalse($disabled->enabled);
        self::assertSame('保留备注 Ї Є',$disabled->note);
        self::assertFalse(DB::table('course_terms')->where('course_id',$this->target)->where('source_text','deleted')->exists());
        self::assertEquals($snapshot,Glossary::snapshot($lesson,$this->target,'zh'));
        $again=$g->copy($this->source,$this->target,$this->admin,3);
        self::assertSame(0,$again['copied']);
        self::assertSame(37,$again['skipped']);
        self::assertSame(37,DB::table('course_terms')->where('course_id',$this->target)->count());
        self::assertSame(2,DB::table('audit')->where('user_id',$this->admin->id)->where('action','glossary.copied')->count());
    }

    public function test_capacity_failure_rolls_back_every_term_version_and_audit():void
    {
        $g=new Glossary;$g->save($this->source,null,$this->admin,$this->term('one-more','新增'),1);
        for($i=0;$i<Glossary::MAX_ENTRIES;$i++)DB::table('course_terms')->insert(array_replace($this->term('existing-'.$i,'保留'),['course_id'=>$this->target,'enabled'=>false]));
        try{$g->copy($this->source,$this->target,$this->admin,1);self::fail('Expected capacity rejection');}
        catch(Failure $e){self::assertSame('glossary_local_limit',$e->errorCode);}
        self::assertSame(Glossary::MAX_ENTRIES,DB::table('course_terms')->where('course_id',$this->target)->count());
        self::assertSame(1,DB::table('courses')->where('id',$this->target)->value('glossary_version'));
        self::assertFalse(DB::table('audit')->where('user_id',$this->admin->id)->where('action','glossary.copied')->exists());
    }

    public function test_http_copy_requires_admin_recent_auth_confirmation_and_current_version():void
    {
        $path='/api/admin/courses/'.$this->target.'/glossary/copy';
        $values=['source_course_id'=>$this->source,'expected_glossary_version'=>1,'confirmed'=>true];
        $this->postJson($path,$values)->assertStatus(401);
        $this->withSession(['uid'=>$this->admin->id])->postJson($path,$values)->assertStatus(403)->assertJsonPath('code','reauth_required');
        $this->withSession(['uid'=>$this->admin->id,'reauth_until'=>time()+900]);
        $this->postJson($path,array_replace($values,['confirmed'=>false]))->assertStatus(400)->assertJsonPath('code','confirmation_required');
        $this->postJson($path,array_replace($values,['source_course_id'=>$this->target]))->assertStatus(422);
        $this->postJson($path,array_replace($values,['expected_glossary_version'=>2]))->assertStatus(409)->assertJsonPath('code','glossary_version_conflict');
        DB::table('courses')->where('id',$this->source)->update(['deleted_at'=>now()]);
        $this->postJson($path,$values)->assertStatus(404);
        DB::table('courses')->where('id',$this->source)->update(['deleted_at'=>null]);
        DB::table('users')->where('id',$this->admin->id)->update(['role'=>'user']);
        $this->postJson($path,$values)->assertStatus(403);
    }

    private function term(string $source,string $target):array
    {
        return ['source_language'=>'en','target_language'=>'zh','source_text'=>$source,'target_text'=>$target,'enabled'=>true,'note'=>'Fixture'];
    }
}
