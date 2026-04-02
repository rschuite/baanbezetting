<?php
/**
 * Baanbezetting Anderstein - GitHub Actions Compatible Versie
 */

// Forceer tijdzone
date_default_timezone_set('Europe/Amsterdam');

/* 1. DATUM LOGICA (Aangepast voor GitHub Actions) */
// Als we via de browser kijken met ?d=... gebruiken we die, anders altijd VANDAAG
$currentDateStr = isset($_GET['d']) ? $_GET['d'] : date("d.m.Y");
$currentTimestamp = strtotime($currentDateStr);

if (!$currentTimestamp) { 
    $currentTimestamp = time(); 
    $currentDateStr = date("d.m.Y"); 
}

// Vertaal tabel voor de dagen
$dagen = ["Mon" => "Ma", "Tue" => "Di", "Wed" => "Wo", "Thu" => "Do", "Fri" => "Vr", "Sat" => "Za", "Sun" => "Zo"];
$dagKort = $dagen[date("D", $currentTimestamp)] ?? date("D", $currentTimestamp);

$prevDay = date("d.m.Y", strtotime("-1 day", $currentTimestamp));
$nextDay = date("d.m.Y", strtotime("+1 day", $currentTimestamp));
$isToday = ($currentDateStr === date("d.m.Y"));
$isScrollen = (isset($_GET['scrollen']) && $_GET['scrollen'] == "1");

/* 2. CONFIG & HELPERS */
$slug = "golfclub-anderstein";
$issuer = "699577f8cbff270001d958eb";
$sheetUrl = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR_xmfrJnWlt06cY5-wwfxf49z2G2j8MFgg1E7m2jOPn2GYY6FQpeUr7gZdgzJdZDAWBTZznATu3aOF/pub?gid=0&single=true&output=csv";
$rot = ["A"=>"B","B"=>"C","C"=>"A"];

if (!function_exists('t2m')) { function t2m($t){ if(!$t) return 0; $parts=explode(":",$t); return ($parts[0]*60)+($parts[1]??0); } }
if (!function_exists('m2t')) { function m2t($m){ return str_pad(floor($m/60),2,"0",STR_PAD_LEFT).":".str_pad($m%60,2,"0",STR_PAD_LEFT); } }

/* 3. DATA LADEN (Met Error Handling voor GitHub) */
$sheetData = [];
$context = stream_context_create(["http" => ["timeout" => 10, "header" => "User-Agent: Mozilla/5.0"]]);

// Laad Google Sheet
$fileContent = @file($sheetUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES, $context);
if ($fileContent) {
    array_shift($fileContent); // header row
    foreach($fileContent as $line){
        $r = str_getcsv($line);
        if(count($r) >= 6) {
            $sheetData[] = [
                "date"  => str_replace(".","-",$r[0]), 
                "lus"   => strtoupper($r[1]), 
                "start" => t2m($r[2]), 
                "end"   => t2m($r[3]), 
                "short" => $r[4], 
                "prio"  => intval($r[5])
            ];
        }
    }
}

/* 4. API DATA LADEN */
$apiUrl = "https://www.nexxchange.com/api/widget/teeTimes/nl/$slug?format=json&date=$currentDateStr&issuerId=$issuer";
$json = @file_get_contents($apiUrl, false, $context);

if ($json) {
    if(preg_match('/^callback\((.*)\);?$/s',$json,$m)) $json=$m[1];
    $data = json_decode($json,true);
} else {
    $data = null;
}

/* LOGICA FUNCTIES */
if (!function_exists('getSpecial')) {
    function getSpecial($date,$lus,$time,$sheetData){
        $m = t2m($time); $d = str_replace(".","-",$date);
        $matches = array_filter($sheetData,function($r) use ($d,$lus,$m){ 
            return $r["date"]==$d && $r["lus"]==$lus && ($m >= $r["start"] && $m <= $r["end"]); 
        });
        if(empty($matches)) return null;
        usort($matches,function($a,$b){ return $b["prio"] - $a["prio"]; });
        return array_values($matches)[0];
    }
}
function isWedstrijd($s){ return $s && $s["prio"]>=10; }
function isInloop($s){ return $s && $s["prio"]==5; }

function check18($time,$course,$courses,$ds,$sheetData,$rot,$isInloop=false){
    $lus = $course[0]; $next = $rot[$lus] ?? null; if(!$next) return false;
    $target = m2t(t2m($time)+126); 
    $special = getSpecial($ds,$next,$target,$sheetData);
    if($special){ 
        if(isWedstrijd($special)) return false; 
        if($isInloop && isInloop($special)) return true; 
        return false; 
    }
    if(isset($courses)){ 
        foreach($courses as $c){ 
            if(isset($c["courseName"]) && strpos($c["courseName"],$next)===0){ 
                foreach($c["teeTimes"] as $tt){ 
                    $t = substr($tt["ttTime"] ?? $tt["time"],0,5); 
                    if($t==$target) return ($tt["countAvail"] == 4); 
                } 
            } 
        } 
    }
    return false;
}

$times=[];
if (isset($data["golfCourse"])) { 
    foreach($data["golfCourse"] as $c){ 
        foreach($c["teeTimes"] as $t) { $times[] = substr($t["ttTime"] ?? $t["time"],0,5); } 
    } 
}
$times = array_unique($times); sort($times);

$nowDT = new DateTime("now", new DateTimeZone("Europe/Amsterdam"));
$now = intval($nowDT->format("H")) * 60 + intval($nowDT->format("i"));

$pastLimit = $isToday ? max(0, $now - 30) : 0;
$futureThreshold = $isToday ? ($now + 9) : -1;
$displayTimes = array_filter($times, function($t) use ($pastLimit) { return t2m($t) >= $pastLimit; });
?>

<style>
    /* ... (De rest van je CSS blijft hetzelfde) ... */
    .bb-wrapper { width: 100%; max-width: 100%; margin: 0 auto; font-family: Arial, sans-serif; }
    .bb-scroll-container { width: 100%; overflow-x: auto; <?php if($isScrollen): ?> height: 85vh; overflow-y: auto; border: 1px solid #ccc; <?php endif; ?> }
    .bb-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 30px; }
    .bb-table tr.bb-title-row td { height: 92px; position: sticky; top: 0; z-index: 110; background: #95bc23 !important; color: white; font-size: 42px; padding: 25px 50px; border-bottom: 2px solid #7a9a1d; }
    .title-flex-container { display: flex; justify-content: space-between; align-items: center; width: 100%; }
    .title-text { flex-grow: 1; text-align: center; font-size: 42px; display: flex; justify-content: center; align-items: center; }
    #clock-bb { position: absolute; right: 30px; font-weight: normal; }
    .wedstrijd { color: #ffa500; font-weight: bold; font-size: 28px; }
    .inloop { color: #3cb371; font-weight: bold; font-size: 28px; }
    .bb-table tr.bb-header-row th { position: sticky; top: 90px; z-index: 90; background: #95bc23 !important; color: black !important; font-size: 42px; border-bottom: 1px solid #ccc; }
    .bb-table tr.bb-header-row th.h-A { background: #ffeb3b !important; color: #000 !important; }
    .bb-table tr.bb-header-row th.h-B { background: #E7565B !important; color: #fff !important; }
    .bb-table tr.bb-header-row th.h-C { background: #2980b9 !important; color: #fff !important; }
    .bb-table td, .bb-table th { border: 1px solid #ccc; padding: 12px 8px; text-align: center; }
    .bb-time-col { background: #f4f4f4; font-weight: bold; width: 85px; font-size: 40px; }
    .dot { width: 16px; height: 16px; border-radius: 50%; display: inline-block; margin: 2px; }
    .free { background: #3498db; }
    .occ { background: #f7555E; }
    #current-time-row td { border-top: 5px solid #95bc23 !important; }
    #current-time-row { background-color: rgba(149, 188, 35, 0.05); }
    @media (max-width: 768px) {
        .bb-table { font-size: 16px; }
        .bb-table tr.bb-title-row td { font-size: 18px !important; padding: 10px 5px !important; }
        .title-text { font-size: 18px !important; }
        #clock-bb { font-size: 18px !important; right: 10px !important; }
        .bb-time-col { font-size: 16px; width: 55px; }
        .dot { width: 8px !important; height: 8px !important; }
    }
</style>

<div class="bb-wrapper">
    <div class="bb-scroll-container <?php echo $isScrollen ? 'is-scrolling' : ''; ?>" id="main-scroll-box">
        <table class="bb-table">
            <thead>
                <tr class="bb-title-row">
                    <td colspan="<?php echo (isset($data["golfCourse"]) ? count($data["golfCourse"]) * 2 + 1 : 7); ?>">
                        <div class="title-flex-container">
                            <?php if($isScrollen): ?>
                                <a href="?d=<?php echo $prevDay; ?>&scrollen=1" class="nav-arrow">❮</a>
                            <?php else: ?>
                                <div style="width:40px;"></div> 
                            <?php endif; ?>

                            <div class="title-text">
                                Baanbezetting - <?php echo $dagKort . " " . date("d-m-Y", $currentTimestamp); ?>
                                <span id="clock-bb"></span>
                            </div>

                            <?php if($isScrollen): ?>
                                <a href="?d=<?php echo $nextDay; ?>&scrollen=1" class="nav-arrow">❯</a>
                            <?php else: ?>
                                <div style="width:40px;"></div> 
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <tr class="bb-header-row">
                    <th class="bb-time-col">TIJD</th>
                    <?php if(isset($data["golfCourse"])): foreach($data["golfCourse"] as $c): $lus = substr($c["courseName"],0,1); ?>
                        <th class="h-<?php echo $lus; ?>"><?php echo $c["courseName"]; ?></th>
                        <th class="h-<?php echo $lus; ?>">#</th>
                    <?php endforeach; endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $lineDrawn = false;
                if (!empty($displayTimes)) {
                    foreach($displayTimes as $slot):
                        $slotMinutes = t2m($slot);
                        $isPastWin = $isToday && ($slotMinutes >= $pastLimit && $slotMinutes < $futureThreshold);
                        $isFut = !$isToday || ($slotMinutes >= $futureThreshold);
                        $rowId = (!$lineDrawn && $isFut && $isToday) ? "id='current-time-row'" : "";
                        if (!$lineDrawn && $isFut && $isToday) $lineDrawn = true;
                ?>
                    <tr <?php echo $rowId; ?>>
                        <td class="bb-time-col"><?php echo $slot; ?></td>
                        <?php if(isset($data["golfCourse"])): foreach($data["golfCourse"] as $course):
                            $tt=null; foreach($course["teeTimes"] as $t){ if(substr($t["ttTime"] ?? $t["time"],0,5)==$slot){ $tt=$t; break; } }
                            $spec = getSpecial($currentDateStr,$course["courseName"][0],$slot,$sheetData);
                            if(isWedstrijd($spec)): ?>
                                <td class="wedstrijd"><?php echo htmlspecialchars($spec["short"]); ?></td><td>-</td>
                            <?php elseif(isInloop($spec)): ?>
                                <td class="inloop">Inloop</td>
                                <td><?php echo $isPastWin ? "-" : (check18($slot,$course["courseName"],($data["golfCourse"]??[]),$currentDateStr,$sheetData,$rot,true) ? "9/18" : "9"); ?></td>
                            <?php elseif($tt): ?>
                                <td><?php for($i=0;$i<4;$i++): ?><span class="dot <?php echo ($i<$tt["countAvail"])?"free":"occ"; ?>"></span><?php endfor; ?></td>
                                <td><?php echo ($isPastWin || $tt["countAvail"] < 4) ? "-" : (check18($slot,$course["courseName"],($data["golfCourse"]??[]),$currentDateStr,$sheetData,$rot,false) ? "9/18" : "9"); ?></td>
                            <?php else: ?>
                                <td></td><td></td>
                            <?php endif; ?>
                        <?php endforeach; endif; ?>
                    </tr>
                <?php endforeach; 
                } else {
                    echo "<tr><td colspan='7'>Geen data beschikbaar voor deze dag.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <?php if (empty($displayTimes) && $isToday): ?>
            <div style="text-align: center; padding: 50px 20px; background: #f9f9f9; border-top: 1px solid #ccc;">
                <h2 style="color: #31473A; font-family: Arial, sans-serif; font-size: 28px;">Vandaag geen starttijden meer, prettige avond!</h2>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    function updateClockBB(){
        const now = new Date();
        const h = String(now.getHours()).padStart(2,'0');
        const m = String(now.getMinutes()).padStart(2,'0');
        const el = document.getElementById('clock-bb');
        if(el && <?php echo $isToday ? 'true' : 'false'; ?>) el.innerText = h + ":" + m;
    }
    if(<?php echo $isToday ? 'true' : 'false'; ?>) {
        setInterval(updateClockBB, 1000); updateClockBB();
    }
})();
</script>
