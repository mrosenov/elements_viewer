<?php

$configName = "RENASCENCE_SKILL_CONFIG";

$fields = "ID;Name;Occup_Level_0;Occup_Level_0_2;Occup_Level_1;Occup_Level_1_2;Occup_Level_2;Occup_Level_2_2;Occup_Level_3;Occup_Level_3_2;Skills_1_ID;Skills_1_Level;Skills_2_ID;Skills_2_Level;Skills_3_ID;Skills_3_Level;Skills_4_ID;Skills_4_Level;Skills_5_ID;Skills_5_Level;Skills_6_ID;Skills_6_Level;Skills_7_ID;Skills_7_Level;Skills_8_ID;Skills_8_Level;Skills_9_ID;Skills_9_Level;Skills_10_ID;Skills_10_Level;Skills_11_ID;Skills_11_Level;Skills_12_ID;Skills_12_Level";
$types = "int32;wstring:64;int64;int64;int64;int64;int64;int64;int64;int64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_91.json', $json);