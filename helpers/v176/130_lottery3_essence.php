<?php

$configName = "LOTTERY3_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Award_Item;Dice_Count;Type;Candidate_Items_1_Item_ID;Candidate_Items_1_Item_Count;Candidate_Items_2_Item_ID;Candidate_Items_2_Item_Count;Candidate_Items_3_Item_ID;Candidate_Items_3_Item_Count;Candidate_Items_4_Item_ID;Candidate_Items_4_Item_Count;Candidate_Items_5_Item_ID;Candidate_Items_5_Item_Count;Candidate_Items_6_Item_ID;Candidate_Items_6_Item_Count;Candidate_Items_7_Item_ID;Candidate_Items_7_Item_Count;Candidate_Items_8_Item_ID;Candidate_Items_8_Item_Count;Candidate_Items_9_Item_ID;Candidate_Items_9_Item_Count;Candidate_Items_10_Item_ID;Candidate_Items_10_Item_Count;Candidate_Items_11_Item_ID;Candidate_Items_11_Item_Count;Candidate_Items_12_Item_ID;Candidate_Items_12_Item_Count;Candidate_Items_13_Item_ID;Candidate_Items_13_Item_Count;Candidate_Items_14_Item_ID;Candidate_Items_14_Item_Count;Candidate_Items_15_Item_ID;Candidate_Items_15_Item_Count;Candidate_Items_16_Item_ID;Candidate_Items_16_Item_Count;Candidate_Items_17_Item_ID;Candidate_Items_17_Item_Count;Candidate_Items_18_Item_ID;Candidate_Items_18_Item_Count;Candidate_Items_19_Item_ID;Candidate_Items_19_Item_Count;Candidate_Items_20_Item_ID;Candidate_Items_20_Item_Count;Candidate_Items_21_Item_ID;Candidate_Items_21_Item_Count;Candidate_Items_22_Item_ID;Candidate_Items_22_Item_Count;Candidate_Items_23_Item_ID;Candidate_Items_23_Item_Count;Candidate_Items_24_Item_ID;Candidate_Items_24_Item_Count;Candidate_Items_25_Item_ID;Candidate_Items_25_Item_Count;Candidate_Items_26_Item_ID;Candidate_Items_26_Item_Count;Candidate_Items_27_Item_ID;Candidate_Items_27_Item_Count;Candidate_Items_28_Item_ID;Candidate_Items_28_Item_Count;Candidate_Items_29_Item_ID;Candidate_Items_29_Item_Count;Candidate_Items_30_Item_ID;Candidate_Items_30_Item_Count;Candidate_Items_31_Item_ID;Candidate_Items_31_Item_Count;Candidate_Items_32_Item_ID;Candidate_Items_32_Item_Count;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_130.json', $json);