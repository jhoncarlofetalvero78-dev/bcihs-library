<?php
require_once __DIR__.'/../includes/session.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/reporting.php';
require_once __DIR__.'/../includes/statistical-report.php';
header('Cache-Control: no-store');
try{
    requireBookAccess($pdo,true);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireBookCsrf();$settings=statisticalSettings(bookRequestInput());header('Content-Type: application/json');echo json_encode(['settings'=>$settings]);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')throw new DomainException('method_not_allowed',405);
    $month=$_GET['month']??date('Y-m');$months=$_GET['months']??'3';$schoolYear=$_GET['school_year']??date('Y').'-'.(date('Y')+1);
    if(!is_string($month)||!is_string($schoolYear)||!is_string($months)||!preg_match('/^(?:[1-9]|1[0-2])$/D',$months))throw new DomainException('Choose valid report options.',422);
    $pdo->beginTransaction();$report=statisticalReport(reportInventory($pdo),reportTransactions($pdo),$month,(int)$months,$schoolYear);$pdo->commit();
    $report['settings']=statisticalSettings();$report['csrfToken']=bookCsrfToken();
    $format=$_GET['format']??'json';
    if($format==='xlsx'){
        require_once __DIR__.'/../includes/statistical-xlsx.php';$workbook=statisticalWorkbook($report);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="statistical-'.$month.'.xlsx"');
        $writer=new PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook);$writer->setIncludeCharts(true);$writer->save('php://output');exit;
    }
    if($format!=='json')throw new DomainException('Choose JSON or Excel.',422);
    header('Content-Type: application/json');echo json_encode($report,JSON_THROW_ON_ERROR);
}catch(Throwable $exception){borrowingFailure($pdo,$exception);}
