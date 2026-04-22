<?php

$configName = "TALISMAN_MAINPART_ESSENCE";

$fields = "ID;Name;Model_Path_ID_1;Model_Path_ID_2;Model_Path_ID_3;Model_Path_ID_4;Model_Path_ID_5;Model_Path_ID_6;Model_Path_ID_7;Model_Path_ID;Icon_Path_ID;Color_Change;Type;Level;Fixed_Level;Character_Combo_ID;Character_Combo_ID_2;Char_Level_1;Char_Level_1_2;Char_Level_2;Char_Level_2_2;Char_Level_3;Char_Level_3_2;Reguired_Gender;Reguired_Level;Reguired_Faction;Reguired_Last_Faction;Reguired_LastLast_Faction;Reguired_LastLastLastFaction;Reguired_Race;Reguired_Tier;Reguired_Ascended;God_Devil_Mask;Required_Title;Max_Level;Max_Level_2;Energy_recover_speed;Energy_recover_factor;Energy_drop_speed;Level_Up_Price;EXPFood_Price;Reset_Price;Is_Flight_Esper;Base_Flight_Speed;Fly_Mode;Fly_energy_drop_speed;Fly_EXP_added;Unknown;Unknown;Unknown;Unknown;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;wstring:32;int32;int32;int64;int64;int64;int64;int64;int64;int64;int64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;int32;int32;int32;float;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_73.json', $json);