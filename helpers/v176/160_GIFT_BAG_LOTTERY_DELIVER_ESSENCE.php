<?php

$configName = "GIFT_BAG_LOTTERY_DELIVER_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Level_Everlimit;Level_Required;Level_Upperlimit;Require_Gender;Renascence_Count;Character_Combo_ID;Character_Combo_ID_2;God_Devil_Mask;Open_Time;Probability;Success_Disappear;Faliure_Disappear;ID_1_Object_Need;ID_1_Object_Num;ID_2_Object_Need;ID_2_Object_Num;Money_Need;Num_Object;Gifts_1_ID_Object;Gifts_1_Probability;Gifts_1_Num_min;Gifts_1_Num_max;Gifts_1_Is_Bind;Gifts_1_Effect_Time;Gifts_2_ID_Object;Gifts_2_Probability;Gifts_2_Num_min;Gifts_2_Num_max;Gifts_2_Is_Bind;Gifts_2_Effect_Time;Gifts_3_ID_Object;Gifts_3_Probability;Gifts_3_Num_min;Gifts_3_Num_max;Gifts_3_Is_Bind;Gifts_3_Effect_Time;Gifts_4_ID_Object;Gifts_4_Probability;Gifts_4_Num_min;Gifts_4_Num_max;Gifts_4_Is_Bind;Gifts_4_Effect_Time;Gifts_5_ID_Object;Gifts_5_Probability;Gifts_5_Num_min;Gifts_5_Num_max;Gifts_5_Is_Bind;Gifts_5_Effect_Time;Gifts_6_ID_Object;Gifts_6_Probability;Gifts_6_Num_min;Gifts_6_Num_max;Gifts_6_Is_Bind;Gifts_6_Effect_Time;Gifts_7_ID_Object;Gifts_7_Probability;Gifts_7_Num_min;Gifts_7_Num_max;Gifts_7_Is_Bind;Gifts_7_Effect_Time;Gifts_8_ID_Object;Gifts_8_Probability;Gifts_8_Num_min;Gifts_8_Num_max;Gifts_8_Is_Bind;Gifts_8_Effect_Time;Gifts_9_ID_Object;Gifts_9_Probability;Gifts_9_Num_min;Gifts_9_Num_max;Gifts_9_Is_Bind;Gifts_9_Effect_Time;Gifts_10_ID_Object;Gifts_10_Probability;Gifts_10_Num_min;Gifts_10_Num_max;Gifts_10_Is_Bind;Gifts_10_Effect_Time;Gifts_11_ID_Object;Gifts_11_Probability;Gifts_11_Num_min;Gifts_11_Num_max;Gifts_11_Is_Bind;Gifts_11_Effect_Time;Gifts_12_ID_Object;Gifts_12_Probability;Gifts_12_Num_min;Gifts_12_Num_max;Gifts_12_Is_Bind;Gifts_12_Effect_Time;Gifts_13_ID_Object;Gifts_13_Probability;Gifts_13_Num_min;Gifts_13_Num_max;Gifts_13_Is_Bind;Gifts_13_Effect_Time;Gifts_14_ID_Object;Gifts_14_Probability;Gifts_14_Num_min;Gifts_14_Num_max;Gifts_14_Is_Bind;Gifts_14_Effect_Time;Gifts_15_ID_Object;Gifts_15_Probability;Gifts_15_Num_min;Gifts_15_Num_max;Gifts_15_Is_Bind;Gifts_15_Effect_Time;Gifts_16_ID_Object;Gifts_16_Probability;Gifts_16_Num_min;Gifts_16_Num_max;Gifts_16_Is_Bind;Gifts_16_Effect_Time;Normalize_Group_1;Normalize_Group_2;Normalize_Group_3;Normalize_Group_4;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int64;int64;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_160.json', $json);