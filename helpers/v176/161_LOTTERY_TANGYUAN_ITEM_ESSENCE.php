<?php

$configName = "LOTTERY_TANGYUAN_ITEM_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Unknown;Open_Item;Open_Item_Num;Exp_Get;Exp_Level_1;Exp_Level_2;Exp_Level_3;Exp_Level_4;Exp_Level_5;Exp_Level_6;Exp_Level_7;Group_Gifts_1_Gifts_1_ID;Group_Gifts_1_Gifts_1_Num;Group_Gifts_1_Gifts_2_ID;Group_Gifts_1_Gifts_2_Num;Group_Gifts_1_Gifts_3_ID;Group_Gifts_1_Gifts_3_Num;Group_Gifts_1_Gifts_4_ID;Group_Gifts_1_Gifts_4_Num;Group_Gifts_2_Gifts_1_ID;Group_Gifts_2_Gifts_1_Num;Group_Gifts_2_Gifts_2_ID;Group_Gifts_2_Gifts_2_Num;Group_Gifts_2_Gifts_3_ID;Group_Gifts_2_Gifts_3_Num;Group_Gifts_2_Gifts_4_ID;Group_Gifts_2_Gifts_4_Num;Group_Gifts_3_Gifts_1_ID;Group_Gifts_3_Gifts_1_Num;Group_Gifts_3_Gifts_2_ID;Group_Gifts_3_Gifts_2_Num;Group_Gifts_3_Gifts_3_ID;Group_Gifts_3_Gifts_3_Num;Group_Gifts_3_Gifts_4_ID;Group_Gifts_3_Gifts_4_Num;Group_Gifts_4_Gifts_1_ID;Group_Gifts_4_Gifts_1_Num;Group_Gifts_4_Gifts_2_ID;Group_Gifts_4_Gifts_2_Num;Group_Gifts_4_Gifts_3_ID;Group_Gifts_4_Gifts_3_Num;Group_Gifts_4_Gifts_4_ID;Group_Gifts_4_Gifts_4_Num;Group_Gifts_5_Gifts_1_ID;Group_Gifts_5_Gifts_1_Num;Group_Gifts_5_Gifts_2_ID;Group_Gifts_5_Gifts_2_Num;Group_Gifts_5_Gifts_3_ID;Group_Gifts_5_Gifts_3_Num;Group_Gifts_5_Gifts_4_ID;Group_Gifts_5_Gifts_4_Num;Group_Gifts_6_Gifts_1_ID;Group_Gifts_6_Gifts_1_Num;Group_Gifts_6_Gifts_2_ID;Group_Gifts_6_Gifts_2_Num;Group_Gifts_6_Gifts_3_ID;Group_Gifts_6_Gifts_3_Num;Group_Gifts_6_Gifts_4_ID;Group_Gifts_6_Gifts_4_Num;Group_Gifts_7_Gifts_1_ID;Group_Gifts_7_Gifts_1_Num;Group_Gifts_7_Gifts_2_ID;Group_Gifts_7_Gifts_2_Num;Group_Gifts_7_Gifts_3_ID;Group_Gifts_7_Gifts_3_Num;Group_Gifts_7_Gifts_4_ID;Group_Gifts_7_Gifts_4_Num;Group_Gifts_8_Gifts_1_ID;Group_Gifts_8_Gifts_1_Num;Group_Gifts_8_Gifts_2_ID;Group_Gifts_8_Gifts_2_Num;Group_Gifts_8_Gifts_3_ID;Group_Gifts_8_Gifts_3_Num;Group_Gifts_8_Gifts_4_ID;Group_Gifts_8_Gifts_4_Num;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_161.json', $json);