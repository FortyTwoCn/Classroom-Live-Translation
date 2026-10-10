<?php
declare(strict_types=1);
namespace App\Reliability;
use App\Domain\{Access,Failure};
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/** Accepted audio samples protect budget; provider tokens and configured estimates are separate. */
final class Usage
{
    private const RATE=16000;
    public const PRICE_ITEMS=['audio_input','text_input','image_input','text_output','audio_output'];

    public static function assertStart(int $lessonId,array $limits):void
    {
        $v=self::limits($limits);
        DB::transaction(function()use($lessonId,$v){
            $lesson=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();if(!$lesson)throw new Failure('lesson_unavailable',404);
            if((int)$lesson->media_samples >= $v['max_lesson_seconds']*self::RATE)throw new Failure('duration_limit',429);
            $day=self::day();$daily=self::daily($day);if((int)$daily->sample_count >= $v['max_daily_audio_seconds']*self::RATE)throw new Failure('daily_audio_limit',429,['reset_at'=>self::resetAt($day)]);
        });
    }

    /** Must run after duplicate/sequence checks, inside the audio segment transaction. */
    public static function acceptSamples(object $lockedLesson,int $samples,array $limits):void
    {
        if(DB::transactionLevel()<1||$samples<1||$samples>1600)throw new Failure('usage_transaction_required',500);
        $v=self::limits($limits);$lesson=DB::table('lessons')->where('id',$lockedLesson->id)->lockForUpdate()->first();
        if(!$lesson||(int)$lesson->media_samples!==(int)$lockedLesson->media_samples)throw new Failure('usage_lesson_stale',409);
        $after=(int)$lesson->media_samples+$samples;$lessonLimit=$v['max_lesson_seconds']*self::RATE;
        if($after>$lessonLimit)throw new Failure('duration_limit',429);
        $day=self::day();$daily=self::daily($day);$dailyAfter=(int)$daily->sample_count+$samples;$dailyLimit=$v['max_daily_audio_seconds']*self::RATE;
        if($dailyAfter>$dailyLimit)throw new Failure('daily_audio_limit',429,['reset_at'=>self::resetAt($day)]);
        DB::table('daily_audio_usage')->where('day',$day)->update(['sample_count'=>$dailyAfter,'updated_at'=>now()]);
        DB::table('lesson_daily_audio_usage')->insertOrIgnore(['lesson_id'=>$lesson->id,'day'=>$day,'sample_count'=>0]);
        DB::table('lesson_daily_audio_usage')->where('lesson_id',$lesson->id)->where('day',$day)->increment('sample_count',$samples);
        foreach(['lesson'=>[$after,$lessonLimit,'lesson_duration_warning'],'daily'=>[$dailyAfter,$dailyLimit,'daily_audio_warning']]as $scope=>[$count,$cap,$code]){
            $threshold=(int)bcadd(bcmul((string)$cap,$v['warning_ratio'],6),'0.999999',0);
            if($count>=$threshold&&DB::table('usage_alerts')->insertOrIgnore(['lesson_id'=>$lesson->id,'day'=>$day,'scope'=>$scope,'threshold_samples'=>$threshold])===1){
                Access::event((int)$lesson->id,'usage.warning',['code'=>$code,'scope'=>$scope,'ratio'=>(float)bcdiv((string)$count,(string)$cap,6),'remaining_seconds'=>max(0,($cap-$count)/self::RATE),'reset_at'=>$scope==='daily'?self::resetAt($day):null]);
            }
        }
    }

    /** Retains raw official usage; duplicate callbacks cannot add a second billed token row. */
    public static function recordEvent(int $lessonId,string $sessionId,array $event):bool
    {
        if(($event['type']??null)!=='usage')throw new Failure('usage_event_invalid',500);
        $response=$event['response_id']??null;$eventId=$event['provider_event_id']??null;
        foreach([$response,$eventId]as $id)if($id!==null&&(!is_string($id)||strlen($id)>512||$id===''))throw new Failure('usage_event_invalid',500);
        if($response===null&&$eventId===null)throw new Failure('usage_event_identity_missing',500);
        $raw=$event['usage']??null;if($raw!==null&&!is_array($raw))throw new Failure('usage_event_invalid',500);
        $json=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>65536)throw new Failure('usage_event_invalid',500);
        $official=($event['official']??false)===true&&$raw!==null;
        $status=$event['status']??null;if($status!==null&&(!is_string($status)||!in_array($status,['completed','failed','incomplete','interrupted','in_progress'],true)))throw new Failure('usage_event_invalid',500);
        $key=($response!==null?'r:':'e:').hash('sha256',$response??$eventId);
        return DB::transaction(function()use($lessonId,$sessionId,$response,$eventId,$json,$official,$status,$key){
            DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
            if(!DB::table('model_sessions')->where('id',$sessionId)->where('lesson_id',$lessonId)->exists())throw new Failure('usage_session_mismatch',500);
            if(DB::table('usage_receipts')->insertOrIgnore(['model_session_id'=>$sessionId,'dedup_key'=>$key])===0)return false;
            $id=DB::table('usage_records')->insertGetId(['lesson_id'=>$lessonId,'model_session_id'=>$sessionId,'response_id'=>$response,'provider_event_id'=>$eventId,'status'=>$status,'usage'=>$json,'official'=>$official]);
            DB::table('usage_receipts')->where('model_session_id',$sessionId)->where('dedup_key',$key)->update(['usage_record_id'=>$id]);return true;
        });
    }

    /** unit_price is a configured decimal amount per 1,000,000 tokens. No default prices or FX. */
    public static function validatePrices(array $prices):array
    {
        if(count($prices)>200)throw new Failure('usage_price_invalid',422);
        $result=[];$keys=[];
        foreach($prices as $p){
            if(!is_array($p)||!is_string($p['region']??null)||!in_array($p['region'],['cn-beijing','ap-southeast-1'],true)||!is_string($p['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$p['currency'])||!in_array($p['item']??null,self::PRICE_ITEMS,true)||!is_string($p['unit_price']??null)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,12})?$/D',$p['unit_price'])||!is_string($p['effective_at']??null))throw new Failure('usage_price_invalid',422);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$p['effective_at']))throw new Failure('usage_price_invalid',422);
            try{$date=new DateTimeImmutable($p['effective_at']);$errors=DateTimeImmutable::getLastErrors();if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new \RuntimeException;}
            catch(\Throwable){throw new Failure('usage_price_invalid',422);}
            $at=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');$key=$p['region'].'|'.$p['item'].'|'.$at;
            if(isset($keys[$key]))throw new Failure('usage_price_invalid',422);$keys[$key]=true;
            $url=$p['source_url']??null;if($url==='')$url=null;
            if($url!==null){$parts=is_string($url)?parse_url($url):false;if(!is_string($url)||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??null)!=='https'||isset($parts['user'])||isset($parts['pass']))throw new Failure('usage_price_invalid',422);}
            $verified=$p['verified_at']??null;if($verified==='')$verified=null;
            if($verified!==null){if(!is_string($verified)||!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2}))?$/D',$verified))throw new Failure('usage_price_invalid',422);try{$v=new DateTimeImmutable(strlen($verified)===10?$verified.'T00:00:00Z':$verified);$e=DateTimeImmutable::getLastErrors();if($e!==false&&($e['warning_count']||$e['error_count']))throw new \RuntimeException;$verified=$v->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}catch(\Throwable){throw new Failure('usage_price_invalid',422);}}
            $result[]=['region'=>$p['region'],'currency'=>$p['currency'],'item'=>$p['item'],'unit_price'=>$p['unit_price'],'effective_at'=>$at,'source_url'=>$url,'verified_at'=>$verified];
        }
        usort($result,fn($a,$b)=>strcmp($a['effective_at'],$b['effective_at']));return $result;
    }

    /** Caller performs membership/access. Stats contain no credentials, names or source text. */
    public static function summary(?int $lessonId,array $prices,array $filters=[]):array
    {
        if(DB::transactionLevel()===0)return DB::transaction(function()use($lessonId,$prices,$filters){DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');return self::summary($lessonId,$prices,$filters);});
        $prices=self::validatePrices($prices);$bindings=[];$where='WHERE 1=1';
        if($lessonId!==null){$where.=' AND u.lesson_id=?';$bindings[]=$lessonId;}
        if(isset($filters['course_id'])){$where.=' AND l.course_id=?';$bindings[]=(int)$filters['course_id'];}
        $dateWhere='';$dateBindings=[];
        foreach(['from'=>'>=','to'=>'<=']as $key=>$op)if(isset($filters[$key])){$dateWhere.=" AND created_at $op ?";$dateBindings[]=self::filterDate($filters[$key],$key==='to');}
        $sql="SELECT u.*,s.region FROM usage_records u JOIN model_sessions s ON s.id=u.model_session_id JOIN lessons l ON l.id=u.lesson_id $where";
        // Historical duplicates are retained. One authoritative row per response is used for estimates.
        $rawCount=(int)DB::selectOne("SELECT count(*) n FROM ($sql) raw WHERE 1=1 $dateWhere",[...$bindings,...$dateBindings])->n;
        $unique="SELECT * FROM (SELECT q.*,row_number() OVER(PARTITION BY model_session_id,COALESCE('r:'||NULLIF(response_id,''),'e:'||provider_event_id,'i:'||id::text) ORDER BY official DESC,id) rn FROM ($sql) q) ranked WHERE rn=1 $dateWhere ORDER BY id";
        $tokens=['total'=>null,'input'=>null,'output'=>null,'input_details'=>['audio'=>null,'text'=>null,'image'=>null],'output_details'=>['audio'=>null,'text'=>null]];
        $official=0;$unknown=0;$uniqueCount=0;$totals=[];$unpriced=[];$allComplete=true;
        foreach(DB::cursor($unique,[...$bindings,...$dateBindings])as $row){
            ++$uniqueCount;$usage=json_decode($row->usage,true,64,JSON_THROW_ON_ERROR);$at=(new DateTimeImmutable($row->created_at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            if(!$row->official||!is_array($usage)){++$unknown;$allComplete=false;continue;}++$official;
            foreach(['total'=>'total_tokens','input'=>'input_tokens','output'=>'output_tokens']as $field=>$key){$n=self::token($usage[$key]??null);if($n!==null)$tokens[$field]=($tokens[$field]??0)+$n;}
            $items=[];$rowUnknown=false;
            $totalTokens=self::token($usage['total_tokens']??null);$inputTokens=self::token($usage['input_tokens']??null);$outputTokens=self::token($usage['output_tokens']??null);
            if($totalTokens===null||$inputTokens===null||$outputTokens===null||$totalTokens!==$inputTokens+$outputTokens){$rowUnknown=true;self::missing($unpriced,$row->region,'total_unclassified',null,'usage_totals_inconsistent');}
            foreach(['input'=>['audio','text','image'],'output'=>['audio','text']]as $direction=>$kinds){
                $detail=$usage[$direction.'_tokens_details']??null;$detailSum=0;$detailKnown=false;
                foreach($kinds as $kind){$n=self::token(is_array($detail)?($detail[$kind.'_tokens']??null):null);if($n!==null){$tokens[$direction.'_details'][$kind]=($tokens[$direction.'_details'][$kind]??0)+$n;$items[$kind.'_'.$direction]=$n;$detailSum+=$n;$detailKnown=true;}}
                $total=self::token($usage[$direction.'_tokens']??null);
                if($total===null||($total>0&&!$detailKnown)||$detailSum!==$total){$rowUnknown=true;self::missing($unpriced,$row->region,$direction.'_unclassified',$total===null?null:max(0,$total-$detailSum),'usage_breakdown_missing');}
            }
            foreach($items as $item=>$n){
                if($n===0)continue;$price=null;foreach($prices as $p)if($p['region']===$row->region&&$p['item']===$item&&$p['effective_at']<=$at)$price=$p;
                if($price===null){$rowUnknown=true;self::missing($unpriced,$row->region,$item,$n,'price_missing');continue;}
                $currency=$price['currency'];$totals[$currency]??=['currency'=>$currency,'priced_subtotal'=>'0.000000000000000000','estimated_total'=>null,'complete'=>true];
                $amount=bcdiv(bcmul((string)$n,$price['unit_price'],12),'1000000',18);$totals[$currency]['priced_subtotal']=bcadd($totals[$currency]['priced_subtotal'],$amount,18);
            }
            if($rowUnknown)$allComplete=false;
        }
        if($unknown>0)self::missing($unpriced,null,'unknown',null,'official_usage_missing');
        foreach($totals as &$total){$total['priced_subtotal']=bcadd($total['priced_subtotal'],'0',12);$total['complete']=$allComplete;$total['estimated_total']=$allComplete?$total['priced_subtotal']:null;}unset($total);
        $sessionQuery=DB::table('model_sessions as s')->join('lessons as l','l.id','=','s.lesson_id');if($lessonId!==null)$sessionQuery->where('s.lesson_id',$lessonId);if(isset($filters['course_id']))$sessionQuery->where('l.course_id',$filters['course_id']);
        foreach(['from'=>'>=','to'=>'<=']as $key=>$op)if(isset($filters[$key]))$sessionQuery->where('s.created_at',$op,self::filterDate($filters[$key],$key==='to'));
        $sessions=(clone$sessionQuery)->selectRaw("count(*) session_count,count(*) FILTER(WHERE s.status='failed') failed_sessions,count(*) FILTER(WHERE s.status='interrupted') interrupted_sessions,count(*) FILTER(WHERE s.config->>'purpose'='initial') initial_sessions,count(*) FILTER(WHERE s.config->>'purpose'='resume') resume_sessions,count(*) FILTER(WHERE s.config->>'purpose'='rotation') rotation_sessions,count(*) FILTER(WHERE COALESCE(s.config->>'purpose','') NOT IN ('initial','resume','rotation')) unknown_purpose_sessions,COALESCE(sum(GREATEST(0,EXTRACT(EPOCH FROM COALESCE(s.ended_at,now())-s.created_at))),0) seconds")->first();
        $audioQuery=DB::table('lesson_daily_audio_usage as d')->join('lessons as l','l.id','=','d.lesson_id');if($lessonId!==null)$audioQuery->where('d.lesson_id',$lessonId);if(isset($filters['course_id']))$audioQuery->where('l.course_id',$filters['course_id']);
        foreach(['from'=>'>=','to'=>'<=']as $key=>$op)if(isset($filters[$key]))$audioQuery->where('d.day',$op,substr(self::filterDate($filters[$key],false),0,10));
        $day=self::day();$samples=(int)(clone$audioQuery)->sum('sample_count');$daily=(int)DB::table('daily_audio_usage')->where('day',$day)->value('sample_count');
        return ['scope'=>$lessonId===null?'account':'lesson','lesson_id'=>$lessonId,'audio'=>['samples'=>$samples,'seconds'=>$samples/self::RATE,'daily_samples'=>$daily,'daily_seconds'=>$daily/self::RATE,'day'=>$day,'reset_at'=>self::resetAt($day)],
            'model'=>['session_count'=>(int)$sessions->session_count,'failed_sessions'=>(int)$sessions->failed_sessions,'interrupted_sessions'=>(int)$sessions->interrupted_sessions,'initial_sessions'=>(int)$sessions->initial_sessions,'resume_sessions'=>(int)$sessions->resume_sessions,'rotation_sessions'=>(int)$sessions->rotation_sessions,'unknown_purpose_sessions'=>(int)$sessions->unknown_purpose_sessions,'call_seconds_estimate'=>(float)$sessions->seconds,'official_records'=>$official,'unknown_usage_records'=>$unknown,'duplicate_records_ignored'=>$rawCount-$uniqueCount,'tokens'=>$tokens],
            'costs'=>['estimate'=>true,'official_bill'=>false,'complete'=>$allComplete&&$uniqueCount>0,'no_pricing_configured'=>$prices===[],'totals'=>array_values($totals),'unpriced'=>array_values($unpriced)]];
    }

    private static function limits(array $limits):array
    {
        $out=[];foreach(['max_lesson_seconds','max_daily_audio_seconds']as $key){$n=$limits[$key]??null;if(!is_int($n)||$n<1||$n>31536000)throw new Failure('usage_configuration_invalid',500);$out[$key]=$n;}
        $ratio=$limits['warning_ratio']??null;if((!is_float($ratio)&&!is_int($ratio)&&!is_string($ratio))||!is_numeric($ratio)||(float)$ratio<=0||(float)$ratio>=1)throw new Failure('usage_configuration_invalid',500);$out['warning_ratio']=number_format((float)$ratio,6,'.','');return $out;
    }
    private static function daily(string $day):object{DB::table('daily_audio_usage')->insertOrIgnore(['day'=>$day]);return DB::table('daily_audio_usage')->where('day',$day)->lockForUpdate()->first();}
    private static function day():string{return now()->utc()->format('Y-m-d');}
    private static function resetAt(?string $day=null):string{return(new DateTimeImmutable(($day??self::day()).'T00:00:00Z'))->modify('+1 day')->format(DATE_ATOM);}
    private static function token(mixed $n):?int{return is_int($n)&&$n>=0&&$n<=1000000000000?$n:null;}
    private static function missing(array &$list,?string $region,string $item,?int $tokens,string $reason):void{$key=($region??'unknown').'|'.$item.'|'.$reason;if(!isset($list[$key]))$list[$key]=['region'=>$region,'item'=>$item,'tokens'=>$tokens,'reason'=>$reason];elseif($tokens!==null&&$list[$key]['tokens']!==null)$list[$key]['tokens']+=$tokens;}
    private static function filterDate(mixed $value,bool $end):string{if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new Failure('usage_filter_invalid',422);try{$d=new DateTimeImmutable($value,new DateTimeZone('UTC'));if($d->format('Y-m-d')!==$value)throw new \RuntimeException;}catch(\Throwable){throw new Failure('usage_filter_invalid',422);}return $d->format('Y-m-d').($end?' 23:59:59.999999+00':' 00:00:00+00');}
}
