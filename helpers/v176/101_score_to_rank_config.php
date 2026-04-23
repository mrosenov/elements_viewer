<?php

$configName = "SCORE_TO_RANK_CONFIG";

$fields = "ID;Name;Map_ID;Controller;Rank_1_Score;Rank_1_Task_ID;Rank_1_Name;Rank_2_Score;Rank_2_Task_ID;Rank_2_Name;Rank_3_Score;Rank_3_Task_ID;Rank_3_Name;Rank_4_Score;Rank_4_Task_ID;Rank_4_Name;Rank_5_Score;Rank_5_Task_ID;Rank_5_Name;Rank_6_Score;Rank_6_Task_ID;Rank_6_Name;Rank_7_Score;Rank_7_Task_ID;Rank_7_Name;Rank_8_Score;Rank_8_Task_ID;Rank_8_Name;Rank_9_Score;Rank_9_Task_ID;Rank_9_Name;Rank_10_Score;Rank_10_Task_ID;Rank_10_Name";
$types = "int32;wstring:64;int32;int32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32;int32;int32;wstring:32";

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

file_put_contents('list_101.json', $json);