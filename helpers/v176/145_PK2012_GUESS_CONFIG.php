<?php

$configName = "PK2012_GUESS_CONFIG";

$fields = "ID;Name;First_ID;Second_ID;Third_ID;Guess_Start_Time;Guess_End_Time;Accept_Award_Start_Time;Accept_Award_End_Time;Champion_Guess_Item;Champion_Guess_Itemnum;Champion_Guess_Award;Award_Back_Ratio;Guess_Item;Guess_Item_Num;Guess_Award_Item;Guess_Award_Item_3;Guess_Award_Item_2;Guess_Award_Item_1";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_145.json', $json);