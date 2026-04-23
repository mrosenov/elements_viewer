<?php

$configName = "LOTTERY2_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Dice_Count;Type;Canididates_1_Desc;Canididates_1_Icon;Canididates_2_Desc;Canididates_2_Icon;Canididates_3_Desc;Canididates_3_Icon;Canididates_4_Desc;Canididates_4_Icon;Canididates_5_Desc;Canididates_5_Icon;Canididates_6_Desc;Canididates_6_Icon;Canididates_7_Desc;Canididates_7_Icon;Canididates_8_Desc;Canididates_8_Icon;Canididates_9_Desc;Canididates_9_Icon;Canididates_10_Desc;Canididates_10_Icon;Canididates_11_Desc;Canididates_11_Icon;Canididates_12_Desc;Canididates_12_Icon;Canididates_13_Desc;Canididates_13_Icon;Canididates_14_Desc;Canididates_14_Icon;Canididates_15_Desc;Canididates_15_Icon;Canididates_16_Desc;Canididates_16_Icon;Canididates_17_Desc;Canididates_17_Icon;Canididates_18_Desc;Canididates_18_Icon;Canididates_19_Desc;Canididates_19_Icon;Canididates_20_Desc;Canididates_20_Icon;Canididates_21_Desc;Canididates_21_Icon;Canididates_22_Desc;Canididates_22_Icon;Canididates_23_Desc;Canididates_23_Icon;Canididates_24_Desc;Canididates_24_Icon;Canididates_25_Desc;Canididates_25_Icon;Canididates_26_Desc;Canididates_26_Icon;Canididates_27_Desc;Canididates_27_Icon;Canididates_28_Desc;Canididates_28_Icon;Canididates_29_Desc;Canididates_29_Icon;Canididates_30_Desc;Canididates_30_Icon;Canididates_31_Desc;Canididates_31_Icon;Canididates_32_Desc;Canididates_32_Icon;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;wstring:32;int32;int32;int32;int32;int32";

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

file_put_contents('list_123.json', $json);