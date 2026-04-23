<?php

$configName = "GEM_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;File_Shape_Icon;Shape_Type;Grade;Quality;Gem_Config_ID;Addon_Property_1_Addon_Type;Addon_Property_1_Addon_ID;Addon_Property_2_Addon_Type;Addon_Property_2_Addon_ID;Addon_Property_3_Addon_Type;Addon_Property_3_Addon_ID;Addon_Property_4_Addon_Type;Addon_Property_4_Addon_ID;Addon_Property_5_Addon_Type;Addon_Property_5_Addon_ID;Addon_Property_6_Addon_Type;Addon_Property_6_Addon_ID;Fee_Upgrade;Gem_Extract_Config_ID;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_125.json', $json);