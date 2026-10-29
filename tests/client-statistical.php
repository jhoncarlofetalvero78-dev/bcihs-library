<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/statistical-report.php';require __DIR__.'/../includes/statistical-xlsx.php';
$checks=0;function verify($condition,$name){global $checks;if(!$condition)throw new RuntimeException($name);$checks++;}
$books=[['id'=>1,'ddc'=>'500.1'],['id'=>2,'ddc'=>'100','category'=>'TEXTBOOK'],['id'=>3,'ddc'=>'','category'=>'SIP & WIP'],['id'=>4,'ddc'=>'bad'],['id'=>5,'category'=>'RIZALIANA']];
$loans=[];foreach([[1,'2026-07-01'],[2,'2026-08-05'],[1,'2026-09-03'],[2,'2026-09-06'],[3,'2026-09-07'],[3,'2026-09-08'],[4,'2026-09-09']] as [$id,$date])$loans[]=['book_id'=>$id,'is_loan'=>true,'borrowed_at'=>$date.' 12:00:00','status'=>'returned','legacy_checkout'=>false];
$r=statisticalReport($books,$loans,'2026-09',3,'2026-2027');$by=array_column($r['rows'],null,'key');verify($r['total']===5,'monthly circulation reconciles');verify($by['SIP']['rank']===1 && $by['TEXTBOOK']['rank']===2 && $by['500']['rank']===2,'competition ranks 1 2 2 2');verify($by['000']['rank']===null,'zero has no rank');verify($by['500']['months']===[1,0,1],'chronological months');verify($by['UNMAPPED']['quantity']===1,'unmapped retained');verify($by['TEXTBOOK']['quantity']===1,'special classification takes priority');verify(statisticalCategory($books[4])==='RIZ','Rizaliana mapped');
$tied=$loans;$tied[]=['book_id'=>2,'is_loan'=>true,'borrowed_at'=>'2026-09-10 12:00:00','status'=>'checked_out','legacy_checkout'=>false];$ranked=array_column(statisticalReport($books,$tied,'2026-09',3,'2026-2027')['rows'],null,'key');verify($ranked['SIP']['rank']===1 && $ranked['TEXTBOOK']['rank']===1 && $ranked['500']['rank']===3,'tied leaders use competition ranks 1 1 3');
$empty=statisticalReport([],[],'2026-09',3,'2026-2027');verify(count($empty['rows'])===13 && $empty['total']===0,'empty complete categories');
foreach([['bad',3,'2026-2027'],['2026-09',0,'2026-2027'],['2026-09',13,'2026-2027'],['2026-09',3,'2026-2026']] as $args){try{statisticalReport([],[],...$args);verify(false,'reject bad inputs');}catch(DomainException $e){verify($e->getCode()===422,'invalid period rejected');}}
verify(statisticalReport([],[],'2026-01',3,'2025-2026')['periods']===['2025-11','2025-12','2026-01'],'comparison crosses calendar year chronologically');
verify(count(statisticalReport([],[],'2026-09',12,'2026-2027')['periods'])===12,'twelve-month comparison supported');
$r['settings']=['prepared_name'=>'=HYPERLINK("bad")','prepared_title'=>'Long title','noted_name'=>'','noted_title'=>'','approved_name'=>'','approved_title'=>''];
$book=statisticalWorkbook($r);verify($book->getSheetCount()===2,'two official sheets');verify(count($book->getSheet(1)->getChartCollection())===1,'native chart included');verify($book->getSheet(0)->getCell('A23')->getDataType()!=='f','text remains inert');
$path=($argv[1]??sys_get_temp_dir().'/bcihs-client-statistical.xlsx');$writer=new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setIncludeCharts(true);$writer->save($path);
$zip=new ZipArchive();verify($zip->open($path)===true,'valid xlsx zip');$chart=$zip->getFromName('xl/charts/chart1.xml');verify($chart!==false && str_contains($chart,'barChart'),'clustered native bars');verify(str_contains($zip->getFromName('xl/sharedStrings.xml'),'HYPERLINK') && !str_contains($zip->getFromName('xl/worksheets/sheet1.xml'),'<f>'),'signatory formula injection inert');
foreach(range(0,$zip->numFiles-1) as $i){$name=$zip->getNameIndex($i);if(str_ends_with($name,'.xml')){verify(simplexml_load_string($zip->getFromIndex($i))!==false,'well formed '.$name);}}
verify(str_contains($chart,'Checkout transactions') && str_contains($chart,'Classification') && str_contains($chart,'majorUnit'),'chart axes are labeled and whole-count ticks configured');

$chartXml=simplexml_load_string($chart);$categoryTitle=$chartXml->xpath('//*[local-name()="catAx"]//*[local-name()="t"]');$valueTitle=$chartXml->xpath('//*[local-name()="valAx"]//*[local-name()="t"]');
verify((string)($categoryTitle[0]??'')==='Classification' && (string)($valueTitle[0]??'')==='Checkout transactions','native vertical chart axes match category and count');
$units=$chartXml->xpath('//*[local-name()="valAx"]/*[local-name()="majorUnit"]');verify((int)($units[0]['val']??0)>=1,'numeric axis uses whole-count spacing');
$directions=$chartXml->xpath('//*[local-name()="barDir"]');verify((string)($directions[0]['val']??'')==='col','reference requires grouped vertical columns');
verify($by['000']['chart_label']==='000-099' && $by['SIP']['chart_label']==='SIP & WIP','chart codes match official category rows');
verify($book->getSheet(1)->getCell('A2')->getValue()==='September 2026 (BOOKS)','chart heading uses selected reporting month');
$zip->close();echo "Passed $checks database-free calculation/Excel checks.\n";
