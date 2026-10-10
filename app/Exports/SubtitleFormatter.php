<?php
declare(strict_types=1);

namespace App\Exports;

use InvalidArgumentException;

/** Pure formatting of an immutable database snapshot; never invokes a model. */
final class SubtitleFormatter
{
    public function render(iterable $rows, array $options, array $metadata): array
    {
        $format = $options['format'];
        $layout = $options['layout'];
        if (!in_array($format, ['txt','md','srt','vtt'], true) || !in_array($layout, ['source','translation','bilingual'], true)) {
            throw new InvalidArgumentException('export_options_invalid');
        }
        $groups = [];
        $report = ['snapshot_units'=>0,'selected_units'=>0,'exported_segments'=>0,'skipped_timing'=>0,
            'missing_timing'=>0,'invalid_timing'=>0,'unpaired_segments'=>0,'missing_source'=>0,'missing_translation'=>0,
            'paragraph_timing_segments'=>0,'normalized_blank_lines'=>0,'skipped_incomplete'=>(int)($metadata['skipped_incomplete']??0),'skipped_empty'=>(int)($metadata['skipped_empty']??0)];
        foreach ($rows as $index => $row) {
            $unit = is_array($row) ? $row : (array)$row;
            ++$report['snapshot_units'];
            if (!\App\Content\SubtitleText::hasContent($unit['text'] ?? '')) { ++$report['skipped_empty']; continue; }
            $source = $unit['kind'] === 'source' ? ($unit['item_id'] ?? null) : ($unit['source_item_id'] ?? null);
            $associated = $unit['kind'] === 'source' || ($unit['mapping'] ?? '') === 'explicit_previous_item_id';
            $key = $source !== null && $associated
                ? json_encode([$unit['model_session_id'], $source], JSON_THROW_ON_ERROR)
                : 'unpaired:'.$unit['id'];
            $groups[$key] ??= ['units'=>[], 'order'=>(int)$index];
            $groups[$key]['units'][] = $unit;
        }
        $segments = [];
        foreach ($groups as $group) {
            $source = array_values(array_filter($group['units'], fn($u) => $u['kind'] === 'source'));
            $translated = array_values(array_filter($group['units'], fn($u) => $u['kind'] === 'translation'));
            if ($layout === 'bilingual') {
                if (!$source) ++$report['missing_source'];
                if (!$translated) ++$report['missing_translation'];
                if (!$source || !$translated) ++$report['unpaired_segments'];
            }
            $selected = match ($layout) { 'source'=>$source, 'translation'=>$translated, default=>array_merge($source,$translated) };
            if (!$selected) continue;
            usort($selected, static fn($a,$b) => ($a['kind'] === $b['kind'] ? ($a['content_index'] <=> $b['content_index']) ?: ($a['id'] <=> $b['id']) : ($a['kind'] === 'source' ? -1 : 1)));
            $report['selected_units'] += count($selected);
            $missing = false;
            $invalid = false;
            $starts = $ends = [];
            foreach ($selected as $unit) {
                if ($unit['start_sample'] === null || $unit['end_sample'] === null) { $missing=true; continue; }
                $start=(int)$unit['start_sample']; $end=(int)$unit['end_sample'];
                if (($unit['timing_precision']??'unavailable')!=='server_segment' || $start < 0 || $end <= $start || $end > (int)$metadata['media_samples']) { $invalid=true; continue; }
                $starts[]=$start; $ends[]=$end;
            }
            $timed = !$missing && !$invalid && count($starts) === count($selected);
            if ($missing) ++$report['missing_timing'];
            if ($invalid) ++$report['invalid_timing'];
            if ($timed) ++$report['paragraph_timing_segments'];
            $segments[] = ['units'=>$selected,'start'=>$timed ? intdiv(min($starts),16) : null,
                'end'=>$timed ? intdiv(max($ends),16) : null,'order'=>$group['order'], 'sort'=>$starts ? min($starts) : PHP_INT_MAX];
        }
        usort($segments, static fn($a,$b) => ($a['sort'] <=> $b['sort']) ?: ($a['order'] <=> $b['order']));
        $header = $this->metadataLines($metadata, $options);
        $output = match($format) { 'txt'=>implode("\n",$header)."\n\n", 'md'=>implode("\n",array_map(fn($line)=>$this->markdown($line),$header))."\n\n", 'vtt'=>"WEBVTT\n\nNOTE\n".implode("\n",array_map(fn($line)=>str_replace('-->', '→', $line),$header))."\n\n", default=>'' };
        foreach ($segments as $segment) {
            if (in_array($format,['srt','vtt'],true) && ($segment['start'] === null || $segment['end'] <= $segment['start'])) { ++$report['skipped_timing']; continue; }
            ++$report['exported_segments'];
            $body=implode("\n",array_column($segment['units'],'text'));
            if ($format === 'txt' || $format === 'md') {
                $time=$segment['start'] === null ? '[time unavailable]' : '['.$this->time($segment['start'],'.').' – '.$this->time($segment['end'],'.').']';
                $output .= $format === 'txt' ? $time."\n".$body."\n\n" : '### '.$time."\n\n".$this->markdown($body)."\n\n";
            } else {
                $clean=preg_replace('/\R[\h]*\R+/u',"\n",str_replace(["\r\n","\r"],"\n",$body));
                if ($clean !== $body) ++$report['normalized_blank_lines'];
                // Escape executable subtitle markup in both player-facing formats.
                $clean=htmlspecialchars($clean,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8');
                $output .= $report['exported_segments']."\n".$this->time($segment['start'],$format==='srt'?',':'.').' --> '.$this->time($segment['end'],$format==='srt'?',':'.')."\n".$clean."\n\n";
            }
        }
        $warnings=[];
        foreach (['skipped_incomplete','skipped_empty','missing_source','missing_translation','missing_timing','invalid_timing','skipped_timing','normalized_blank_lines'] as $code) if($report[$code]) $warnings[]=['code'=>$code,'count'=>$report[$code]];
        if ($report['paragraph_timing_segments']) $warnings[]=['code'=>'paragraph_timing_approximate','count'=>$report['paragraph_timing_segments']];
        return ['contents'=>$output,'counts'=>$report,'warnings'=>$warnings];
    }

    private function metadataLines(array $m,array $o):array
    {
        $single=static fn($s)=>preg_replace('/[\r\n\x00-\x1F]+/u',' ',(string)$s);
        return ['Course: '.$single($m['course_name']), 'Lesson: '.$single($m['lesson_name']),
            'Created: '.$single($m['lesson_created_at']), 'Source language: '.$single($m['source_language']??'unknown'),
            'Target language: '.$single($m['target_language']), 'Exported: '.$single($m['exported_at']),
            'Text version: '.$o['text_version'], 'Layout: '.$o['layout'], 'Timing: approximate paragraph offsets; unavailable offsets are never invented'];
    }

    private function time(int $ms,string $separator):string
    {
        return sprintf('%02d:%02d:%02d%s%03d',intdiv($ms,3600000),intdiv($ms,60000)%60,intdiv($ms,1000)%60,$separator,$ms%1000);
    }
    private function markdown(string $text):string
    {
        return str_replace(['\\','*','_','[',']','#','`'],['\\\\','\\*','\\_','\\[','\\]','\\#','\\`'],htmlspecialchars($text,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8'));
    }
}
