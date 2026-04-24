<?php

$configName = "RUNE_COMB_PROPERTY";

$fields = "ID;Name;Max_IDs;IDs_1;IDs_2;IDs_3;IDs_4;IDs_5;IDs_6;IDs_7;IDs_8;IDs_9;IDs_10;Addons_1;Addons_2;Addons_3;Addons_4;Addons_5;Addons_6;Addons_7;Addons_8;Addons_9";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_140.json', $json);