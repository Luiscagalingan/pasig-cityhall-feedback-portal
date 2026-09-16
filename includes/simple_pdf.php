<?php
declare(strict_types=1);

final class SimplePdf
{
    private const WIDTH = 842;
    private const HEIGHT = 595;
    private array $pages = [];
    private string $content = '';

    public function addPage(): void
    {
        if ($this->content !== '') $this->pages[] = $this->content;
        $this->content = '';
    }

    private function safe(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $converted === false ? $text : $converted);
    }

    public function text(float $x, float $top, string $text, float $size = 10, array $rgb = [0.06, 0.14, 0.23]): void
    {
        $y = self::HEIGHT - $top;
        $this->content .= sprintf("BT /F1 %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n", $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $this->safe($text));
    }

    public function fillRect(float $x, float $top, float $width, float $height, array $rgb): void
    {
        $y = self::HEIGHT - $top - $height;
        $this->content .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
    }

    public function line(float $x1, float $top1, float $x2, float $top2, array $rgb = [.82, .87, .92]): void
    {
        $this->content .= sprintf("%.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $x1, self::HEIGHT-$top1, $x2, self::HEIGHT-$top2);
    }

    public function output(string $filename): never
    {
        if ($this->content !== '' || !$this->pages) $this->pages[] = $this->content;
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];$objectId = 4;
        foreach ($this->pages as $stream) {
            $contentId=$objectId++;$pageId=$objectId++;
            $objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream";
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $kids[]=$pageId.' 0 R';
        }
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objects);
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="{$id} 0 obj\n{$object}\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
        $pdf.="trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','-',$filename).'"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
    }
}

function download_feedback_report_pdf(array $metrics, array $ages, array $services, array $rows, string $scope, string $range): never
{
    $viewer=current_user();
    if(($viewer['role']??'')==='office_staff'){
        http_response_code(403);
        exit('Walang pahintulot ang Office Staff na mag-export ng report.');
    }
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'dashboard.php') {
        global $trend, $indicators;
        $title = str_contains(strtolower($scope), 'active offices') ? 'ADMINISTRATOR OVERVIEW' : 'OFFICE OVERVIEW';
        download_dashboard_pdf($metrics, is_array($trend) ? $trend : [], is_array($indicators) ? $indicators : [], $ages, $services, $rows, $title, $scope);
    }
    $pdf=new SimplePdf();$pdf->addPage();
    $pdf->fillRect(0,0,842,64,[.03,.29,.55]);$pdf->text(32,31,'PASIG CITY HALL - SERVICE SATISFACTION REPORT',18,[1,1,1]);$pdf->text(32,51,$scope.' | '.$range,9,[.82,.92,1]);
    $cards=[['Responses',(string)count($rows)],['Average Rating',number_format((float)$metrics['avg_rating'],2).'/4'],['Final Score',number_format((float)$metrics['avg_final'],2).'%'],['Positive Rate',number_format((float)$metrics['positive_rate'],1).'%'],['Action Completion',number_format((float)$metrics['completion_rate'],1).'%']];
    foreach($cards as $i=>$card){$x=32+$i*156;$pdf->fillRect($x,82,144,55,[.94,.97,1]);$pdf->text($x+10,101,$card[0],8,[.35,.44,.54]);$pdf->text($x+10,128,$card[1],17,[.03,.29,.55]);}
    $pdf->text(32,170,'AGE GROUP DISTRIBUTION',12,[.03,.29,.55]);$max=max(1,...array_map(fn($r)=>(int)$r['count'],$ages));$y=188;
    foreach($ages as $age){$pdf->text(32,$y+10,(string)$age['label'],8);$width=220*((int)$age['count']/$max);$pdf->fillRect(100,$y,$width,12,[.08,.55,.83]);$pdf->text(326,$y+10,(string)$age['count'],8);$y+=21;}
    $pdf->text(390,170,'MOST USED SERVICES',12,[.03,.29,.55]);$pdf->text(390,191,'Service',8);$pdf->text(650,191,'Responses',8);$pdf->text(730,191,'Score',8);$pdf->line(390,197,810,197);$y=213;
    foreach(array_slice($services,0,10) as $service){$pdf->text(390,$y,mb_strimwidth((string)$service['label'],0,42,'...'),8);$pdf->text(670,$y,(string)$service['responses'],8);$pdf->text(730,$y,number_format((float)$service['avg_score'],1).'%',8);$y+=20;}
    $pdf->text(32,415,'FILTERED FEEDBACK RECORDS',12,[.03,.29,.55]);$headers=['Date','Office','Service','Rating','Sentiment','Final'];$xs=[32,105,175,470,545,650];foreach($headers as $i=>$header)$pdf->text($xs[$i],438,$header,8,[.35,.44,.54]);$pdf->line(32,445,810,445);$y=461;
    foreach($rows as $row){if($y>560){$pdf->addPage();$pdf->fillRect(0,0,842,45,[.03,.29,.55]);$pdf->text(32,29,'FILTERED FEEDBACK RECORDS - CONTINUED',14,[1,1,1]);$y=72;foreach($headers as $i=>$header)$pdf->text($xs[$i],$y,$header,8,[.35,.44,.54]);$pdf->line(32,$y+7,810,$y+7);$y+=24;}$pdf->text(32,$y,(string)$row['visit_date'],7);$pdf->text(105,$y,(string)$row['office_code'],7);$pdf->text(175,$y,mb_strimwidth((string)$row['service_received'],0,42,'...'),7);$pdf->text(470,$y,number_format((float)$row['average_rating'],2).'/4',7);$pdf->text(545,$y,status_label((string)$row['sentiment']),7);$pdf->text(650,$y,number_format((float)$row['final_score'],2).'%',7);$y+=17;}
    $pdf->output('pasig-feedback-report-'.date('Y-m-d').'.pdf');
}

function download_dashboard_pdf(array $m,array $trend,array $indicators,array $ages,array $services,array $rows,string $title,string $scope): never
{
    $pdf=new SimplePdf();$bg=[.027,.067,.122];$panel=[.051,.106,.18];$panel2=[.071,.137,.231];$grid=[.13,.25,.38];$white=[.93,.97,1];$muted=[.56,.68,.80];$blue=[.06,.48,.86];$cyan=[.18,.66,.94];
    $page=function(string $heading)use($pdf,$bg,$panel,$white,$muted,$scope){$pdf->addPage();$pdf->fillRect(0,0,842,595,$bg);$pdf->fillRect(0,0,842,66,$panel);$pdf->text(28,27,'CITY GOVERNMENT OF PASIG',9,$muted);$pdf->text(28,50,$heading,19,$white);$pdf->text(680,28,$scope,8,$muted);$pdf->text(680,48,date('M d, Y h:i A'),8,$muted);};
    $page($title);$cards=[['Total Responses',$m['total']],['Final Satisfaction',number_format((float)$m['avg_final'],1).'%'],['Positive Sentiment',number_format((float)$m['positive_rate'],1).'%'],['Needs Review',$m['review_count']],['Pending Approval',$m['pending_approval']]];
    foreach($cards as $i=>$card){$x=28+$i*158;$pdf->fillRect($x,84,146,62,$panel2);$pdf->text($x+10,104,(string)$card[0],8,$muted);$pdf->text($x+10,132,(string)$card[1],18,$white);}
    $pdf->fillRect(28,164,496,204,$panel);$pdf->text(43,188,'SIX-MONTH SATISFACTION TREND',12,$white);$pdf->text(43,204,'Average final weighted score',8,$muted);$x0=58;$y0=226;$w=438;$h=112;foreach([0,25,50,75,100] as $tick){$yy=$y0+$h-$tick/100*$h;$pdf->line($x0,$yy,$x0+$w,$yy,$grid);$pdf->text(38,$yy+3,(string)$tick,7,$muted);}$den=max(1,count($trend)-1);$prev=null;foreach($trend as $i=>$p){$x=$x0+$i/$den*$w;$y=$y0+$h-(float)($p['score']??0)/100*$h;if($prev)$pdf->line($prev[0],$prev[1],$x,$y,$blue);$pdf->fillRect($x-2,$y-2,4,4,$cyan);$pdf->text($x-13,354,mb_strimwidth((string)$p['label'],0,7,''),7,$muted);$prev=[$x,$y];}
    $pdf->fillRect(540,164,274,204,$panel);$pdf->text(555,188,'SENTIMENT DISTRIBUTION',12,$white);$sent=[['Positive',$m['positive'],[.20,.77,.55]],['Neutral',$m['neutral'],[.95,.72,.25]],['Negative',$m['negative'],[1,.42,.42]]];foreach($sent as $i=>$s){$x=555+$i*82;$pdf->fillRect($x,215,72,58,$panel2);$pdf->fillRect($x,215,72,3,$s[2]);$pdf->text($x+8,237,$s[0],7,$muted);$pdf->text($x+8,261,(string)$s[1],17,$white);}$pdf->text(555,299,'INDICATOR AVERAGES',10,$white);$y=319;foreach($indicators as $label=>$value){$pdf->text(555,$y,mb_strimwidth((string)$label,0,22,'...'),7,$muted);$pdf->fillRect(665,$y-8,105,7,$panel2);$pdf->fillRect(665,$y-8,105*min(1,(float)$value/4),7,$blue);$pdf->text(779,$y,number_format((float)$value,2),7,$white);$y+=15;}
    $pdf->fillRect(28,386,300,174,$panel);$pdf->text(43,410,'AGE DISTRIBUTION',11,$white);$max=max(1,...array_map(fn($a)=>(int)$a['count'],$ages));$y=430;foreach(array_slice($ages,0,6) as $a){$pdf->text(43,$y,(string)$a['label'],7,$muted);$pdf->fillRect(103,$y-8,160*(int)$a['count']/$max,8,$blue);$pdf->text(274,$y,(string)$a['count'],7,$white);$y+=19;}
    $pdf->fillRect(344,386,470,174,$panel);$pdf->text(359,410,'SERVICE PERFORMANCE',11,$white);$y=435;foreach(array_slice($services,0,6) as $s){$pdf->text(359,$y,mb_strimwidth((string)$s['label'],0,45,'...'),7,$white);$pdf->text(675,$y,(string)$s['responses'].' responses',7,$muted);$pdf->text(755,$y,number_format((float)$s['avg_score'],1).'%',7,$white);$y+=19;}
    if($rows){$page('COMPLETE FEEDBACK RESPONSES');$y=95;foreach($rows as $r){if($y>560){$page('FEEDBACK RESPONSES - CONTINUED');$y=95;}$pdf->fillRect(28,$y-15,786,25,$panel);$pdf->text(38,$y,(string)$r['visit_date'],7,$white);$pdf->text(110,$y,(string)($r['office_code']??''),7,$muted);$pdf->text(170,$y,mb_strimwidth((string)$r['service_received'],0,48,'...'),7,$white);$pdf->text(490,$y,number_format((float)$r['average_rating'],2).'/4',7,$white);$pdf->text(560,$y,status_label((string)$r['sentiment']),7,$white);$pdf->text(670,$y,number_format((float)$r['final_score'],2).'%',7,$white);$y+=31;}}
    $pdf->output('pasig-dashboard-'.date('Y-m-d').'.pdf');
}
