<?php
require_once __DIR__.'/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\{Chart,DataSeries,DataSeriesValues,Layout,Legend,PlotArea,Title};
use PhpOffice\PhpSpreadsheet\Style\{Alignment,Border,Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

function statisticalWorkbook(array $report): Spreadsheet
{
    $book=new Spreadsheet();$table=$book->getActiveSheet();$table->setTitle('Statistical Table');$chartSheet=$book->createSheet();$chartSheet->setTitle('Circulation Chart');
    // Explicit text cells ensure even signatories beginning with '=' remain inert, never formulas.
    $text=static function($sheet,string $cell,string $value):void{$sheet->setCellValueExplicit($cell,$value,DataType::TYPE_STRING);};
    foreach([$table,$chartSheet] as $sheet){
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(1);
        $sheet->getPageSetup()->setHorizontalCentered(true);
        $sheet->getPageMargins()->setLeft(.3)->setRight(.3)->setTop(.4)->setBottom(.4);
    }
    $table->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
    foreach(['STATISTICAL REPORT',$report['monthLabel'].' (BOOKS)','S.Y. '.$report['schoolYear']] as $i=>$value){$row=$i+1;$table->mergeCells('A'.$row.':C'.$row);$text($table,'A'.$row,$value);}
    $table->getStyle('A1:C3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);$table->getStyle('A1:C3')->getFont()->setBold(true);
    $table->fromArray(['CLASSIFICATION','QUANTITY','RANK'],null,'A5');
    foreach($report['rows'] as $i=>$row){$n=$i+6;$text($table,'A'.$n,$row['classification']);$table->setCellValue('B'.$n,$row['quantity']);$text($table,'C'.$n,$row['rank']===null?'—':(string)$row['rank']);$table->getRowDimension($n)->setRowHeight(27);if($row['key']==='SIP')$table->getStyle('A'.$n.':C'.$n)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');}
    $total=count($report['rows'])+6;$text($table,'A'.$total,'TOTAL');$table->setCellValue('B'.$total,$report['total']);
    $table->getStyle('A5:C'.$total)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
    foreach(['A5:C5','A'.$total.':C'.$total] as $range){$table->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7E6E6');$table->getStyle($range)->getFont()->setBold(true);}
    $table->getColumnDimension('A')->setWidth(82);$table->getColumnDimension('B')->setWidth(17);$table->getColumnDimension('C')->setWidth(17);$table->getStyle('A5:C'.$total)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);$table->getStyle('B5:C'.$total)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    foreach(['STATISTICAL REPORT IN CHART',$report['monthLabel'].' (BOOKS)','S.Y. '.$report['schoolYear']] as $i=>$value){$n=$i+1;$chartSheet->mergeCells('A'.$n.':N'.$n);$text($chartSheet,'A'.$n,$value);}
    $chartSheet->getStyle('A1:N3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);$chartSheet->getStyle('A1:N3')->getFont()->setBold(true);
    $chartSheet->setCellValue('A46','Classification');foreach($report['labels'] as $i=>$label)$text($chartSheet,PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+2).'46',$label);
    foreach($report['rows'] as $i=>$row){$n=$i+47;$text($chartSheet,'A'.$n,$row['chart_label']);foreach($row['months'] as $j=>$count)$chartSheet->setCellValue([$j+2,$n],$count);}
    $last=count($report['rows'])+46;$labels=[];$categories=[];$values=[];
    foreach($report['labels'] as $i=>$label){$column=PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+2);$labels[]=new DataSeriesValues('String',"'Circulation Chart'!\$".$column.'$46',null,1);$categories[]=new DataSeriesValues('String',"'Circulation Chart'!\$A\$47:\$A\$".$last,null,count($report['rows']));$values[]=new DataSeriesValues('Number',"'Circulation Chart'!\$".$column.'$47:$'.$column.'$'.$last,null,count($report['rows']));}
    $series=new DataSeries(DataSeries::TYPE_BARCHART,DataSeries::GROUPING_CLUSTERED,range(0,count($labels)-1),$labels,$categories,$values);$series->setPlotDirection(DataSeries::DIRECTION_COL);
    $chart=new Chart('CirculatedBooks',new Title('CIRCULATED BOOKS'),new Legend(Legend::POSITION_BOTTOM),new PlotArea(new Layout(),[$series]),true,0,new Title('Classification'),new Title('Checkout transactions'));
    $maximum=max(1,...array_merge(...array_column($report['rows'],'months')));
    $chart->getChartAxisY()->setAxisOptionsProperties('nextTo', minimum:0, majorUnit:max(1,(int)ceil($maximum/5)));
    $chart->setTopLeftPosition('A5')->setBottomRightPosition('N34');$chartSheet->addChart($chart);$chartSheet->getColumnDimension('A')->setWidth(14);for($i=2;$i<=14;$i++)$chartSheet->getColumnDimension(PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(12);
    // Data table and full labels remain visible; the native chart is on its own printed page.
    $chartSheet->getPageSetup()->setFitToHeight(1);
    foreach([[$table,$total+3,'C'],[$chartSheet,36,'N']] as [$sheet,$start,$end]){
        foreach(['prepared'=>'Prepared by:','noted'=>'Noted by:','approved'=>'Approved by:'] as $key=>$label){$sheet->mergeCells('A'.$start.':'.$end.$start);$text($sheet,'A'.$start,$label.' '.$report['settings'][$key.'_name'].' — '.$report['settings'][$key.'_title']);$sheet->getStyle('A'.$start)->getAlignment()->setWrapText(true);$sheet->getRowDimension($start)->setRowHeight(32);$start+=2;}
        $sheet->getPageSetup()->setPrintArea('A1:'.$end.($start-1));
    }
    $text($table,'A'.($total+10),$report['metric']);$table->mergeCells('A'.($total+10).':C'.($total+12));$table->getStyle('A'.($total+10))->getAlignment()->setWrapText(true);$table->getPageSetup()->setPrintArea('A1:C'.($total+12));
    return $book;
}
