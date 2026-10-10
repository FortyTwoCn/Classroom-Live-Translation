<?php
namespace App\Imports;
use App\Domain\Failure;
use OpenSpout\Common\Entity\{Row,Cell\StringCell,Cell\FormulaCell};
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;
final class XlsxUsers {
 public function template(string $locale):string {
  $h=match($locale){'en'=>['Name','Student ID'],'uk'=>['Ім’я','Номер студента'],default=>['名字','学号']};
  $dir=storage_path('app/imports');if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/template-'.bin2hex(random_bytes(12)).'.xlsx';
  $writer=new Writer(new \OpenSpout\Writer\XLSX\Options(tempFolder:$dir));$writer->openToFile($path);$text=(new Style())->withFormat('@');
  $writer->addRow(new Row([new StringCell($h[0]),new StringCell($h[1]) ]));
  $writer->addRow(new Row([new StringCell('Іван 示例'),new StringCell('000123456',$text)]));$writer->close();return $path;
 }
 public function parse(string $file):array {
  $limits=\App\Settings\Settings::policies();$this->preflight($file,(int)$limits['max_import_bytes']);$maxRows=(int)$limits['max_import_rows'];$dir=storage_path('app/imports');if(!is_dir($dir))mkdir($dir,0700,true);$reader=new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS:true,tempFolder:$dir));$rows=[];$seen=[];$columns=null;$n=0;
  try{$reader->open($file);foreach($reader->getSheetIterator()as$sheet){foreach($sheet->getRowIterator()as$row){if(++$n>$maxRows+1)throw new Failure('import_row_limit',422,['max'=>$maxRows]);$cells=$row->cells;
   if($row->getNumCells()>20)throw new Failure('import_column_limit',422);
   if($n===1){$columns=$this->headers($cells);continue;}if($row->isEmpty())continue;
   $nameCell=$cells[$columns['name']]??null;$idCell=$cells[$columns['student_id']]??null;
   $name=$nameCell instanceof StringCell||$nameCell instanceof FormulaCell?mb_trim($nameCell->getValue()):'';$sid=$idCell instanceof StringCell||$idCell instanceof FormulaCell?mb_trim($idCell->getValue()):'';$errors=[];
   if($nameCell instanceof FormulaCell||$idCell instanceof FormulaCell)$errors[]=['code'=>'import_formula_not_allowed','params'=>[]];
   if(!$nameCell instanceof StringCell||$name===''||mb_strlen($name)>200||preg_match('/\p{C}/u',$name))$errors[]=['code'=>'import_name_invalid','params'=>[]];
   if(!$idCell instanceof StringCell)$errors[]=['code'=>'import_student_id_must_be_text','params'=>[]];
   elseif(mb_strlen($sid)<6||mb_strlen($sid)>100||preg_match('/[\p{C}\p{Z}\s]/u',$sid))$errors[]=['code'=>'import_student_id_invalid','params'=>['min'=>6]];
   if($sid!==''&&isset($seen[$sid]))$errors[]=['code'=>'import_duplicate_in_file','params'=>['first_row'=>$seen[$sid]]];if($sid!=='')$seen[$sid]=$n;
   $rows[]=['row'=>$n,'name'=>$name,'student_id'=>$sid,'action'=>$errors?'error':'new','errors'=>$errors];
  }break;}}catch(Failure $e){throw $e;}catch(\Throwable){throw new Failure('import_xlsx_invalid',422);}finally{$reader->close();unset($sheet,$row,$cells,$nameCell,$idCell,$reader);gc_collect_cycles();}
  if(!$columns||!$rows)throw new Failure('import_no_rows',422);return $rows;
 }
 private function headers(array $cells):array {
  $aliases=['名字'=>'name','姓名'=>'name','name'=>'name','full name'=>'name','ім’я'=>'name',"ім'я"=>'name','学号'=>'student_id','student id'=>'student_id','student_id'=>'student_id','номер студента'=>'student_id'];$found=[];
  foreach($cells as$i=>$cell){if(!$cell instanceof StringCell)continue;$key=$aliases[mb_strtolower(mb_trim($cell->getValue()))]??null;if($key){if(isset($found[$key]))throw new Failure('import_header_duplicate',422);$found[$key]=$i;}}
  if(!isset($found['name'],$found['student_id']))throw new Failure('import_headers_missing',422);return $found;
 }
 private function preflight(string $file,int $maxBytes):void {
  if(!is_file($file)||filesize($file)>$maxBytes)throw new Failure('import_file_size',422,['max_bytes'=>$maxBytes]);
  $zip=new ZipArchive();if($zip->open($file)!==true)throw new Failure('import_xlsx_invalid',422);
  try{if($zip->numFiles>2000||$zip->locateName('[Content_Types].xml')===false||$zip->locateName('xl/workbook.xml')===false)throw new Failure('import_xlsx_invalid',422);$total=0;
   for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=$s['name'];$total+=$s['size'];if($s['size']>8388608||$total>33554432||($s['size']>1048576&&$s['size']/max(1,$s['comp_size'])>100))throw new Failure('import_zip_limits',422);
    if(str_contains($name,'..')||str_starts_with($name,'/')||preg_match('/vbaProject|externalLinks|\.bin$/i',$name))throw new Failure('import_active_content',422);
    if(str_ends_with($name,'.xml')||str_ends_with($name,'.rels')){$xml=$zip->getFromIndex($i);if($xml===false||preg_match('/<!DOCTYPE|<!ENTITY|TargetMode\s*=\s*["\x27]External/i',$xml))throw new Failure('import_active_content',422);if($name==='xl/workbook.xml'&&preg_match_all('/<sheet\s/',$xml)!==1)throw new Failure('import_single_sheet_required',422);}
   }
  }finally{$zip->close();}
 }
}
