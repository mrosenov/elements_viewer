<?php

$configName = "SUITE_ESSENCE";

$fields = "ID;Name;Max_Equips;Equips_ID_1;Equips_ID_2;Equips_ID_3;Equips_ID_4;Equips_ID_5;Equips_ID_6;Equips_ID_7;Equips_ID_8;Equips_ID_9;Equips_ID_10;Equips_ID_11;Equips_ID_12;Equips_ID_13;Equips_ID_14;Addons_ID_1;Addons_ID_2;Addons_ID_3;Addons_ID_4;Addons_ID_5;Addons_ID_6;Addons_ID_7;Addons_ID_8;Addons_ID_9;Addons_ID_10;Addons_ID_11;Addons_ID_12;Addons_ID_13;File_GFX;HH_Type;Equip_Soul_Suite";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;wstring:128;int32;int32";

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

file_put_contents('list_59.json', $json);