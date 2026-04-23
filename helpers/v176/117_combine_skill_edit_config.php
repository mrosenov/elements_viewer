<?php

$configName = "COMBINE_SKILL_EDIT_CONFIG";

$fields = "ID;Name;Edit_Config_1_Skill_ID;Edit_Config_1_Cool_Index;Edit_Config_1_Cool_Time;Edit_Config_2_Skill_ID;Edit_Config_2_Cool_Index;Edit_Config_2_Cool_Time;Edit_Config_3_Skill_ID;Edit_Config_3_Cool_Index;Edit_Config_3_Cool_Time;Edit_Config_4_Skill_ID;Edit_Config_4_Cool_Index;Edit_Config_4_Cool_Time;Edit_Config_5_Skill_ID;Edit_Config_5_Cool_Index;Edit_Config_5_Cool_Time;Edit_Config_6_Skill_ID;Edit_Config_6_Cool_Index;Edit_Config_6_Cool_Time;Edit_Config_7_Skill_ID;Edit_Config_7_Cool_Index;Edit_Config_7_Cool_Time;Edit_Config_8_Skill_ID;Edit_Config_8_Cool_Index;Edit_Config_8_Cool_Time;Edit_Config_9_Skill_ID;Edit_Config_9_Cool_Index;Edit_Config_9_Cool_Time;Edit_Config_10_Skill_ID;Edit_Config_10_Cool_Index;Edit_Config_10_Cool_Time;Edit_Config_11_Skill_ID;Edit_Config_11_Cool_Index;Edit_Config_11_Cool_Time;Edit_Config_12_Skill_ID;Edit_Config_12_Cool_Index;Edit_Config_12_Cool_Time;Edit_Config_13_Skill_ID;Edit_Config_13_Cool_Index;Edit_Config_13_Cool_Time;Edit_Config_14_Skill_ID;Edit_Config_14_Cool_Index;Edit_Config_14_Cool_Time;Edit_Config_15_Skill_ID;Edit_Config_15_Cool_Index;Edit_Config_15_Cool_Time;Edit_Config_16_Skill_ID;Edit_Config_16_Cool_Index;Edit_Config_16_Cool_Time;Edit_Config_17_Skill_ID;Edit_Config_17_Cool_Index;Edit_Config_17_Cool_Time;Edit_Config_18_Skill_ID;Edit_Config_18_Cool_Index;Edit_Config_18_Cool_Time;Edit_Config_19_Skill_ID;Edit_Config_19_Cool_Index;Edit_Config_19_Cool_Time;Edit_Config_20_Skill_ID;Edit_Config_20_Cool_Index;Edit_Config_20_Cool_Time;Edit_Config_21_Skill_ID;Edit_Config_21_Cool_Index;Edit_Config_21_Cool_Time;Edit_Config_22_Skill_ID;Edit_Config_22_Cool_Index;Edit_Config_22_Cool_Time;Edit_Config_23_Skill_ID;Edit_Config_23_Cool_Index;Edit_Config_23_Cool_Time;Edit_Config_24_Skill_ID;Edit_Config_24_Cool_Index;Edit_Config_24_Cool_Time;Edit_Config_25_Skill_ID;Edit_Config_25_Cool_Index;Edit_Config_25_Cool_Time;Edit_Config_26_Skill_ID;Edit_Config_26_Cool_Index;Edit_Config_26_Cool_Time;Edit_Config_27_Skill_ID;Edit_Config_27_Cool_Index;Edit_Config_27_Cool_Time;Edit_Config_28_Skill_ID;Edit_Config_28_Cool_Index;Edit_Config_28_Cool_Time;Edit_Config_29_Skill_ID;Edit_Config_29_Cool_Index;Edit_Config_29_Cool_Time;Edit_Config_30_Skill_ID;Edit_Config_30_Cool_Index;Edit_Config_30_Cool_Time";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_117.json', $json);