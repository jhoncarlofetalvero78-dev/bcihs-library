<?php
// Shared calculations for HTML, CSV and Excel. No inventory counts are substituted for loans.
function statisticalCategories(): array
{
    return ['000'=>'000-099 Computer science, information and general works','100'=>'100-199 Philosophy and psychology',
        '200'=>'200-299 Religion','300'=>'300-399 Social Sciences','400'=>'400-499 Language','500'=>'500-599 Natural Science',
        '600'=>'600-699 Technology/Applied Science','700'=>'700-799 Arts and Recreation','800'=>'800-899 Literature (Fiction and Non-Fiction)',
        '900'=>'900-999 History, geography, travel and biography','TEXTBOOK'=>'TEXTBOOK','SIP'=>'SIP & WIP','RIZ'=>'RIZ - Rizaliana'];
}
function statisticalCategory(array $book): string
{
    $category = strtoupper(trim((string)($book['category'] ?? '')));
    if (in_array($category,['TEXTBOOK','TEXTBOOKS'],true)) return 'TEXTBOOK';
    if (in_array($category,['SIP & WIP','SIP','WIP'],true)) return 'SIP';
    if (in_array($category,['RIZ','RIZALIANA','RIZ - RIZALIANA'],true)) return 'RIZ';
    if (preg_match('/^(\d{3})(?:\.\d+)?$/D',trim((string)($book['ddc'] ?? '')),$match)) return str_pad((string)(intdiv((int)$match[1],100)*100),3,'0',STR_PAD_LEFT);
    return 'UNMAPPED';
}
function statisticalReport(array $books, array $transactions, string $month, int $months, string $schoolYear): array
{
    $end = DateTimeImmutable::createFromFormat('!Y-m',$month);
    if (!$end || $end->format('Y-m') !== $month || $month<'1001-01' || $month>'9998-12' || $months<1 || $months>12
        || !preg_match('/^([1-9]\d{3})-([1-9]\d{3})$/D',$schoolYear,$sy) || (int)$sy[2] !== (int)$sy[1]+1) throw new DomainException('Choose a valid report month, 1–12 months in the comparison chart, and consecutive school years (YYYY-YYYY).',422);
    $periods=[];for($i=$months-1;$i>=0;$i--) $periods[]=$end->modify('-'.$i.' months')->format('Y-m');
    $categories=statisticalCategories();$categories['UNMAPPED']='Unmapped classification — review catalog';
    $values=[];foreach($categories as $key=>$label)$values[$key]=array_fill_keys($periods,0);
    $byId=[];foreach($books as $book)$byId[$book['id']]=$book;
    $legacy=0;$undated=0;
    foreach($transactions as $row){
        if (!$row['is_loan']) {if(in_array($row['status'],['approved','checked_out','fulfilled','returned'],true) && !$row['borrowed_at'])$undated++;continue;}
        $period=substr($row['borrowed_at'],0,7);if(!in_array($period,$periods,true))continue;
        $key=statisticalCategory($byId[$row['book_id']]??[]);$values[$key][$period]++;
        if(!empty($row['legacy_checkout']))$legacy++;
    }
    $quantities=array_column(array_values($values),$month);rsort($quantities,SORT_NUMERIC);
    $rows=[];foreach($categories as $key=>$label){$quantity=$values[$key][$month];$rows[]=['key'=>$key,'classification'=>$label,'chart_label'=>ctype_digit((string)$key) ? $key.'-'.str_pad((string)((int)$key+99),3,'0',STR_PAD_LEFT) : match($key){'SIP'=>'SIP & WIP','UNMAPPED'=>'Unmapped',default=>$key},'quantity'=>$quantity,'rank'=>$quantity>0?array_search($quantity,$quantities,true)+1:null,'months'=>array_values($values[$key])];}
    // Preserve reconciliation: an extra row appears only when unmapped circulating books exist.
    if(array_sum($values['UNMAPPED'])===0)array_pop($rows);
    return ['month'=>$month,'monthLabel'=>$end->format('F Y'),'schoolYear'=>$schoolYear,'periods'=>$periods,
        'labels'=>array_map(static fn($m)=>(new DateTimeImmutable($m.'-01'))->format('F Y'),$periods),'rows'=>$rows,
        'total'=>array_sum(array_column($rows,'quantity')),'legacy'=>$legacy,'undated'=>$undated,
        'metric'=>'Checkout transactions (one copy per loan), including fulfilled reservations; returned loans count in their checkout month. Legacy approved/returned loans use approved_at only when checked_out_at is missing. Current catalog classification is used; historical reclassification is not reconstructed.',
        'ranking'=>'Competition ranks, descending quantity: ties share a rank (1, 1, 3); zero quantities have no rank.'];
}
function statisticalSettings(?array $save = null): array
{
    $defaults=['prepared_name'=>'','prepared_title'=>'','noted_name'=>'','noted_title'=>'','approved_name'=>'','approved_title'=>''];
    $validated=[];
    if($save!==null)foreach($defaults as $key=>$unused){$value=$save[$key]??'';if(!is_string($value)||mb_strlen($value)>80||preg_match('/[\x00-\x1F\x7F]/u',$value))throw new DomainException('Signatories must be plain text, up to 80 characters each.',422);$validated[$key]=trim($value);}
    $path=__DIR__.'/report-local.json';
    if(!is_file($path) && $save===null)return $defaults;
    if(is_link($path))throw new RuntimeException('Report settings unavailable.');
    $file=fopen($path,'c+');if(!$file)throw new RuntimeException('Report settings unavailable.');
    try{
        if(!flock($file,LOCK_EX))throw new RuntimeException('Report settings unavailable.');
        if(PHP_OS_FAMILY!=='Windows' && !chmod($path,0600))throw new RuntimeException('Cannot protect report settings.');
        if($save!==null){
            $values=$validated;
            $json=json_encode($values,JSON_THROW_ON_ERROR);rewind($file);if(!ftruncate($file,0)||fwrite($file,$json)!==strlen($json)||!fflush($file))throw new RuntimeException('Cannot save report settings.');return $values;
        }
        $raw=stream_get_contents($file);$values=json_decode($raw,true);if(!is_array($values))throw new RuntimeException('Invalid report settings.');return array_intersect_key($values,$defaults)+$defaults;
    }finally{flock($file,LOCK_UN);fclose($file);}
}
