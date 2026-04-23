<?php

$configName = "BATTLE_DEPRIVE_CONFIG";

$fields = "ID;Name;Map_ID;Deprive_1_ID_Obj;Deprive_1_Max_Num;Deprive_2_ID_Obj;Deprive_2_Max_Num;Deprive_3_ID_Obj;Deprive_3_Max_Num;Deprive_4_ID_Obj;Deprive_4_Max_Num;Deprive_5_ID_Obj;Deprive_5_Max_Num;Deprive_6_ID_Obj;Deprive_6_Max_Num;Deprive_7_ID_Obj;Deprive_7_Max_Num;Deprive_8_ID_Obj;Deprive_8_Max_Num;Deprive_9_ID_Obj;Deprive_9_Max_Num;Deprive_10_ID_Obj;Deprive_10_Max_Num";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_103.json', $json);