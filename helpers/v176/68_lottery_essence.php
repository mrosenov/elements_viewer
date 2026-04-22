<?php

$configName = "LOTTERY_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Dice_Count;Type;Candidates_1_Desc;Candidates_1_Icon;Candidates_2_Desc;Candidates_2_Icon;Candidates_3_Desc;Candidates_3_Icon;Candidates_4_Desc;Candidates_4_Icon;Candidates_5_Desc;Candidates_5_Icon;Candidates_6_Desc;Candidates_6_Icon;Candidates_7_Desc;Candidates_7_Icon;Candidates_8_Desc;Candidates_8_Icon;Candidates_9_Desc;Candidates_9_Icon;Candidates_10_Desc;Candidates_10_Icon;Candidates_11_Desc;Candidates_11_Icon;Candidates_12_Desc;Candidates_12_Icon;Candidates_13_Desc;Candidates_13_Icon;Candidates_14_Desc;Candidates_14_Icon;Candidates_15_Desc;Candidates_15_Icon;Candidates_16_Desc;Candidates_16_Icon;Candidates_17_Desc;Candidates_17_Icon;Candidates_18_Desc;Candidates_18_Icon;Candidates_19_Desc;Candidates_19_Icon;Candidates_20_Desc;Candidates_20_Icon;Candidates_21_Desc;Candidates_21_Icon;Candidates_22_Desc;Candidates_22_Icon;Candidates_23_Desc;Candidates_23_Icon;Candidates_24_Desc;Candidates_24_Icon;Candidates_25_Desc;Candidates_25_Icon;Candidates_26_Desc;Candidates_26_Icon;Candidates_27_Desc;Candidates_27_Icon;Candidates_28_Desc;Candidates_28_Icon;Candidates_29_Desc;Candidates_29_Icon;Candidates_30_Desc;Candidates_30_Icon;Candidates_31_Desc;Candidates_31_Icon;Candidates_32_Desc;Candidates_32_Icon;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
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

file_put_contents('list_68.json', $json);