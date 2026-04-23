<?php

$configName = "TRANSCRIPTION_CONFIG";

$fields = "ID;Name;Max_Finish_Count;Map_ID;Open_Room_Item_ID;Open_Room_Item_Count;Room_Active_Time;Player_Min_Level;Player_Max_Level;Character_Combo_ID;Character_Combo_ID_2;God_Devil_Mask;Renascence_Count;Required_Race;Required_Money;Required_Reputation_1_Reputation_Type;Required_Reputation_1_Reputation_Value;Required_Reputation_2_Reputation_Type;Required_Reputation_2_Reputation_Value;Required_Reputation_3_Reputation_Type;Required_Reputation_3_Reputation_Value;Required_Reputation_4_Reputation_Type;Required_Reputation_4_Reputation_Value;Required_Item_ID;Required_Item_Count;Is_Item_Need_Consumed;Min_Player_Num;Max_Player_Num;Invincible_Time;Wait_Time_Before_Leave;Total_Exist_Time;Controller_ID;Award_Task_ID;Forbiddon_Items_ID_1;Forbiddon_Items_ID_2;Forbiddon_Items_ID_3;Forbiddon_Items_ID_4;Forbiddon_Items_ID_5;Forbiddon_Items_ID_6;Forbiddon_Items_ID_7;Forbiddon_Items_ID_8;Forbiddon_Items_ID_9;Forbiddon_Items_ID_10;Forbiddon_Skill_ID_1;Forbiddon_Skill_ID_2;Forbiddon_Skill_ID_3;Forbiddon_Skill_ID_4;Forbiddon_Skill_ID_5;Forbiddon_Skill_ID_6;Forbiddon_Skill_ID_7;Forbiddon_Skill_ID_8;Forbiddon_Skill_ID_9;Forbiddon_Skill_ID_10;Map_Variable_ID_1;Map_Variable_ID_2;Map_Variable_ID_3;Map_Variable_ID_4;Map_Variable_ID_5;Map_Variable_ID_6;Map_Variable_ID_7;Map_Variable_ID_8;Map_Variable_ID_9;Map_Variable_ID_10;Map_Variable_ID_11;Map_Variable_ID_12;Map_Variable_ID_13;Map_Variable_ID_14;Map_Variable_ID_15;Map_Variable_ID_16;Map_Variable_ID_17;Map_Variable_ID_18;Map_Variable_ID_19;Map_Variable_ID_20;Level_Controller_ID_1;Level_Controller_ID_2;Level_Controller_ID_3;Level_Controller_ID_4;Level_Controller_ID_5;Level_Controller_ID_6;Level_Controller_ID_7;Level_Controller_ID_8;Level_Controller_ID_9;Level_Controller_ID_10;Strategy_1;Strategy_2;Strategy_3;Strategy_4;Strategy_5;Strategy_6;Strategy_7;Strategy_8;Strategy_9;Strategy_10;Difficulty";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int64;int64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_131.json', $json);