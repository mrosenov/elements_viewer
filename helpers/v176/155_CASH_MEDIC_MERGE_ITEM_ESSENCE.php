<?php

$configName = "CASH_MEDIC_MERGE_ITEM_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Require_Level;Renascence_Count;Type;Cool_Time;Item_IDs_1;Item_IDs_2;Item_IDs_3;Item_IDs_4;Item_IDs_5;Item_IDs_6;Item_IDs_7;Item_IDs_8;Item_IDs_9;Item_IDs_10;Item_IDs_11;Item_IDs_12;Item_IDs_13;Item_IDs_14;Item_IDs_15;Item_IDs_16;Item_IDs_17;Item_IDs_18;Item_IDs_19;Item_IDs_20;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_155.json', $json);