<?php

$configName = "FLAG_BUFF_ITEM_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Npc_ID;Require_Level;Exit_Time;Cool_Time;Dmg;Defence;HP;MP;Extra_Defence;Crit_Rate;Crit_Damage;Anti_Stunt;Anti_Weak;Anti_Slow;Anti_Silence;Anti_Sleep;Anti_Twist;Skill_Attack_Rate;Skill_Armor_Rate;Cult_Defense_1;Cult_Defense_2;Cult_Defense_3;Cult_Attack_1;Cult_Attack_2;Cult_Attack_3;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

// Remove trailing empty item caused by the last ;
$fieldsArray = array_values(array_filter(explode(';', $fields), 'strlen'));
$typesArray  = array_values(array_filter(explode(';', $types), 'strlen'));

if (count($fieldsArray) !== count($typesArray)) {
    die("Fields count does not match types count");
}

$result = [
    "name" => $configName,
    "fields" => []
];

foreach ($fieldsArray as $i => $fieldName) {
    $result["fields"][] = [
        "name" => $fieldName,
        "type" => $typesArray[$i]
    ];
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

file_put_contents('list_148.json', $json);