<?php

$configName = "VEHICLE_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Model_Path_ID;Icon_Path_ID;Req_Level;Req_Ascened;Coronation_Pos;Only_Psychea;War_Only;Is_Multi_Ride;Multi_Ride_Mode;Multi_Ride_Limit;Speed;Max_Speed;Height;Auto_Train;Attribute_Count_1_Chance;Attribute_Count_2_Chance;Attribute_Count_3_Chance;Attribute_Count_4_Chance;Attribute_Count_5_Chance;Attribute_1_ID;Attribute_2_Chance;Attribute_3_ID;Attribute_4_Chance;Attribute_5_ID;Attribute_6_Chance;Attribute_7_ID;Attribute_8_Chance;Attribute_9_ID;Attribute_10_Chance;Attribute_11_ID;Attribute_12_Chance;Attribute_13_ID;Attribute_14_Chance;Attribute_15_ID;Attribute_16_Chance;Attribute_17_ID;Attribute_18_Chance;Attribute_19_ID;Attribute_20_Chance;Attribute_21_ID;Attribute_22_Chance;Attribute_23_ID;Attribute_24_Chance;Attribute_25_ID;Attribute_26_Chance;Attribute_27_ID;Attribute_28_Chance;Attribute_29_ID;Attribute_30_Chance;Attribute_31_ID;Attribute_32_Chance;Attribute_33_ID;Attribute_34_Chance;Attribute_35_ID;Attribute_36_Chance;Attribute_37_ID;Attribute_38_Chance;Attribute_39_ID;Attribute_40_Chance;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;float;float;float;float;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;float;int32;int32;int32;int32";

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

file_put_contents('list_66.json', $json);