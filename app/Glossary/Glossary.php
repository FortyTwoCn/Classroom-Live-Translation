<?php
declare(strict_types=1);
namespace App\Glossary;
use App\Content\MutationActor;
use App\Domain\{Access,Failure};
use App\Translation\QwenConfig;
use Illuminate\Support\Facades\DB;

/** Course changes take effect at a future lesson's first start, never during an epoch. */
final class Glossary
{
    public const MAX_ENTRIES=1000;
    public const MAX_TERM_BYTES=QwenConfig::MAX_PHRASE_SOURCE_BYTES;
    public const MAX_TARGET_BYTES=QwenConfig::MAX_PHRASE_TARGET_BYTES;
    public const MAX_PAYLOAD_BYTES=65536;

    public function listing(int $courseId,?object $actor,array $filters=[]):array
    {
        Access::admin($actor);
        return DB::transaction(function()use($courseId,$filters){
            $course=DB::table('courses')->where('id',$courseId)->whereNull('deleted_at')->sharedLock()->first();
            if(!$course)throw new Failure('course_unavailable',404);
            $q=DB::table('course_terms')->where('course_id',$courseId);
            $deleted=$filters['deleted']??'active';
            if($deleted==='active')$q->whereNull('deleted_at');elseif($deleted==='only')$q->whereNotNull('deleted_at');
            foreach(['source_language','target_language']as $language)if(!empty($filters[$language]))$q->where($language,$filters[$language]);
            if(isset($filters['enabled']))$q->where('enabled',filter_var($filters['enabled'],FILTER_VALIDATE_BOOLEAN));
            if(!empty($filters['search'])){$s='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['search']).'%';$q->where(fn($w)=>$w->where('source_text','ilike',$s)->orWhere('target_text','ilike',$s)->orWhere('note','ilike',$s));}
            $total=(clone$q)->count();$page=max(1,(int)($filters['page']??1));
            return ['course_id'=>$courseId,'version'=>(int)$course->glossary_version,'entries'=>$q->orderBy('id')->offset(($page-1)*30)->limit(30)->get()->map(fn($r)=>self::entry($r))->all(),'total'=>$total,'page'=>$page,'per_page'=>30,'limits'=>self::limits(),'effective_policy'=>'next_lesson_first_start'];
        });
    }

    public function save(int $courseId,?int $termId,?object $actor,array $values,int $expectedVersion):array
    {
        return $this->mutate($courseId,$actor,$expectedVersion,function(object $course)use($courseId,$termId,$values){
            $old=$termId?DB::table('course_terms')->where('id',$termId)->where('course_id',$courseId)->whereNull('deleted_at')->first():null;
            if($termId&&!$old)throw new Failure('glossary_term_unavailable',404);
            $v=self::validateEntry($values,$old);
            if($termId){$v['row_version']=(int)$old->row_version+1;DB::table('course_terms')->where('id',$termId)->update($v+['updated_at'=>now()]);}
            else{$termId=DB::table('course_terms')->insertGetId($v+['course_id'=>$courseId]);}
            $this->validateActive($courseId);
            return ['entry'=>self::entry(DB::table('course_terms')->where('id',$termId)->first())];
        },'glossary.saved');
    }

    public function deletion(int $courseId,int $termId,?object $actor,bool $deleted,int $expectedVersion):array
    {
        return $this->mutate($courseId,$actor,$expectedVersion,function()use($courseId,$termId,$deleted){
            $term=DB::table('course_terms')->where('id',$termId)->where('course_id',$courseId)->first();if(!$term)throw new Failure('glossary_term_unavailable',404);
            if(($term->deleted_at!==null)!==$deleted){DB::table('course_terms')->where('id',$termId)->update(['deleted_at'=>$deleted?now():null,'row_version'=>(int)$term->row_version+1,'updated_at'=>now()]);}
            if(!$deleted)$this->validateActive($courseId);
            return $deleted?['deleted'=>true]:['entry'=>self::entry(DB::table('course_terms')->where('id',$termId)->first())];
        },$deleted?'glossary.deleted':'glossary.restored');
    }

    /** Merge the complete active glossary, including disabled terms, without replacing target entries. */
    public function copy(int $sourceId,int $targetId,?object $actor,int $expectedVersion):array
    {
        Access::admin($actor);
        if($sourceId===$targetId)throw new Failure('glossary_copy_same_course',422);
        return DB::transaction(function()use($sourceId,$targetId,$actor,$expectedVersion){
            // A consistent order also makes simultaneous A→B and B→A copies safe.
            $courses=DB::table('courses')->whereIn('id',[$sourceId,$targetId])->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
            if($courses->count()!==2)throw new Failure('course_unavailable',404);
            return $this->mutate($targetId,$actor,$expectedVersion,function()use($sourceId,$targetId){
                $existing=[];
                foreach(DB::table('course_terms')->where('course_id',$targetId)->whereNull('deleted_at')->get()as$term){
                    $existing[json_encode([$term->target_language,$term->source_text],JSON_THROW_ON_ERROR)]=true;
                }
                $rows=[];$skipped=0;
                foreach(DB::table('course_terms')->where('course_id',$sourceId)->whereNull('deleted_at')->orderBy('id')->get()as$term){
                    // The provider has one source-text key per target language, regardless of source language.
                    $key=json_encode([$term->target_language,$term->source_text],JSON_THROW_ON_ERROR);
                    if(isset($existing[$key])){$skipped++;continue;}
                    $rows[]=self::validateEntry((array)$term,null)+['course_id'=>$targetId];
                }
                if($rows)DB::table('course_terms')->insert($rows);
                $this->validateActive($targetId);
                return ['source_course_id'=>$sourceId,'copied'=>count($rows),'skipped'=>$skipped];
            },'glossary.copied');
        });
    }

    /** Root calls under the lesson lock. One SQL MVCC view avoids course/lesson lock inversion. */
    public static function snapshot(int $lessonId,int $courseId,string $targetLanguage):array
    {
        return DB::transaction(function()use($lessonId,$courseId,$targetLanguage){
            $lesson=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
            if(!$lesson||(int)$lesson->course_id!==$courseId||$lesson->target_language!==$targetLanguage)throw new Failure('glossary_snapshot_mismatch',409);
            $old=DB::table('lesson_glossary_snapshots')->where('lesson_id',$lessonId)->first();
            if($old)return self::snapshotResult($old);
            if(!in_array($targetLanguage,QwenConfig::LANGUAGES,true))throw new Failure('glossary_term_invalid');
            $row=DB::selectOne("SELECT c.glossary_version,COALESCE((SELECT jsonb_agg(jsonb_build_object('id',t.id,'source_language',t.source_language,'target_language',t.target_language,'source_text',t.source_text,'target_text',t.target_text,'note',t.note,'row_version',t.row_version) ORDER BY t.id) FROM course_terms t WHERE t.course_id=c.id AND t.deleted_at IS NULL AND t.enabled AND t.target_language=?),'[]'::jsonb) entries FROM courses c WHERE c.id=? AND c.deleted_at IS NULL AND c.enabled",[$targetLanguage,$courseId]);
            if(!$row)throw new Failure('course_unavailable',404);
            $entries=json_decode($row->entries,true,64,JSON_THROW_ON_ERROR);$phrases=self::phraseMap($entries);$bytes=self::payloadBytes($phrases,$targetLanguage);
            $encoded=json_encode((object)$phrases,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            DB::table('lesson_glossary_snapshots')->insert(['lesson_id'=>$lessonId,'course_id'=>$courseId,'target_language'=>$targetLanguage,'version'=>$row->glossary_version,'hash'=>hash('sha256',$encoded),'phrases'=>$encoded,'entries'=>$row->entries,'phrase_count'=>count($phrases),'payload_bytes'=>$bytes]);
            return self::snapshotResult(DB::table('lesson_glossary_snapshots')->where('lesson_id',$lessonId)->first());
        });
    }

    private function mutate(int $courseId,?object $actor,int $expectedVersion,callable $operation,string $action):array
    {
        Access::admin($actor);
        return DB::transaction(function()use($courseId,$actor,$expectedVersion,$operation,$action){
            $course=DB::table('courses')->where('id',$courseId)->whereNull('deleted_at')->lockForUpdate()->first();if(!$course)throw new Failure('course_unavailable',404);
            $fresh=MutationActor::lock($actor,true);
            if((int)$course->glossary_version!==$expectedVersion)throw new Failure('glossary_version_conflict',409,['latest_version'=>(int)$course->glossary_version]);
            $result=$operation($course);$version=(int)$course->glossary_version+1;
            DB::table('courses')->where('id',$courseId)->update(['glossary_version'=>$version,'updated_at'=>now()]);
            DB::table('audit')->insert(['user_id'=>$fresh->id,'action'=>$action,'details'=>json_encode(['course_id'=>$courseId,'version'=>$version,'term_id'=>$result['entry']['id']??null]+array_intersect_key($result,array_flip(['source_course_id','copied','skipped'])))]);
            return ['version'=>$version]+$result;
        });
    }

    private function validateActive(int $courseId):void
    {
        $all=DB::table('course_terms')->where('course_id',$courseId)->whereNull('deleted_at')->get();if($all->count()>self::MAX_ENTRIES)throw new Failure('glossary_local_limit',422,self::limits());
        foreach($all->where('enabled',true)->groupBy('target_language')as $target=>$rows){$map=self::phraseMap($rows->map(fn($r)=>(array)$r)->all());self::payloadBytes($map,(string)$target);}
    }
    private static function validateEntry(array $input,?object $old):array
    {
        $v=$old?array_intersect_key((array)$old,array_flip(['source_language','target_language','source_text','target_text','note','enabled'])):[];
        foreach(['source_language','target_language','source_text','target_text','note','enabled']as $key)if(array_key_exists($key,$input))$v[$key]=$input[$key];
        foreach(['source_language','target_language']as $key)if(!is_string($v[$key]??null)||!in_array($v[$key],QwenConfig::LANGUAGES,true))throw new Failure('glossary_term_invalid');
        if($v['source_language']===$v['target_language'])throw new Failure('glossary_term_invalid');
        foreach(['source_text','target_text']as $key){$cap=$key==='source_text'?self::MAX_TERM_BYTES:self::MAX_TARGET_BYTES;if(!is_string($v[$key]??null)||!mb_check_encoding($v[$key],'UTF-8')||trim($v[$key])===''||strlen($v[$key])>$cap||preg_match('/[\x00-\x1f\x7f]/u',$v[$key]))throw new Failure('glossary_term_invalid',422,self::limits());$v[$key]=trim($v[$key]);}
        $v['note']=$v['note']??'';$v['enabled']=$v['enabled']??true;
        if(!is_bool($v['enabled'])||!is_string($v['note'])||mb_strlen($v['note'])>2000||!mb_check_encoding($v['note'],'UTF-8')||str_contains($v['note'],"\0"))throw new Failure('glossary_term_invalid');
        return $v;
    }
    private static function phraseMap(array $entries):array
    {
        $map=[];foreach($entries as $entry){$s=$entry['source_text'];$t=$entry['target_text'];if(array_key_exists($s,$map)&&$map[$s]!==$t)throw new Failure('glossary_phrase_conflict',409);$map[$s]=$t;}uksort($map,fn($a,$b)=>strcmp((string)$a,(string)$b));return $map;
    }
    private static function payloadBytes(array $map,string $target):int
    {
        try{return QwenConfig::updateBytes(QwenConfig::fromArray(['phrases'=>$map])->sessionUpdate($target));}
        catch(\InvalidArgumentException){throw new Failure('glossary_local_limit',422,self::limits());}
    }
    private static function snapshotResult(object $r):array{return ['version'=>(int)$r->version,'hash'=>$r->hash,'phrases'=>json_decode($r->phrases,true,64,JSON_THROW_ON_ERROR),'count'=>(int)$r->phrase_count,'bytes'=>(int)$r->payload_bytes,'created_at'=>$r->created_at];}
    private static function entry(object $r):array{$v=(array)$r;$v['id']=(int)$v['id'];$v['row_version']=(int)$v['row_version'];return $v;}
    private static function limits():array{return ['max_entries'=>self::MAX_ENTRIES,'max_term_bytes'=>self::MAX_TERM_BYTES,'max_source_bytes'=>self::MAX_TERM_BYTES,'max_target_bytes'=>self::MAX_TARGET_BYTES,'max_payload_bytes'=>self::MAX_PAYLOAD_BYTES];}
}

