<?php
function generate_time_slots($start="09:00",$end="21:00",$interval=30){
    $slots=[]; $cur=strtotime($start); $endT=strtotime($end);
    while($cur<=$endT){ $slots[]=date("H:i",$cur); $cur+=$interval*60; }
    return $slots;
}
