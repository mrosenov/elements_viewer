<?php

$configName = "SMALL_ONLINE_GIFT_BAG_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Open_Time;Item_Num;Item_Configs_1_Item_ID;Item_Configs_1_Item_Count;Item_Configs_1_Is_Bind;Item_Configs_1_Effect_Time;Item_Configs_2_Item_ID;Item_Configs_2_Item_Count;Item_Configs_2_Is_Bind;Item_Configs_2_Effect_Time;Item_Configs_3_Item_ID;Item_Configs_3_Item_Count;Item_Configs_3_Is_Bind;Item_Configs_3_Effect_Time;Item_Configs_4_Item_ID;Item_Configs_4_Item_Count;Item_Configs_4_Is_Bind;Item_Configs_4_Effect_Time;Item_Configs_5_Item_ID;Item_Configs_5_Item_Count;Item_Configs_5_Is_Bind;Item_Configs_5_Effect_Time;Item_Configs_6_Item_ID;Item_Configs_6_Item_Count;Item_Configs_6_Is_Bind;Item_Configs_6_Effect_Time;Item_Configs_7_Item_ID;Item_Configs_7_Item_Count;Item_Configs_7_Is_Bind;Item_Configs_7_Effect_Time;Item_Configs_8_Item_ID;Item_Configs_8_Item_Count;Item_Configs_8_Is_Bind;Item_Configs_8_Effect_Time;Item_Configs_9_Item_ID;Item_Configs_9_Item_Count;Item_Configs_9_Is_Bind;Item_Configs_9_Effect_Time;Item_Configs_10_Item_ID;Item_Configs_10_Item_Count;Item_Configs_10_Is_Bind;Item_Configs_10_Effect_Time;Item_Configs_11_Item_ID;Item_Configs_11_Item_Count;Item_Configs_11_Is_Bind;Item_Configs_11_Effect_Time;Item_Configs_12_Item_ID;Item_Configs_12_Item_Count;Item_Configs_12_Is_Bind;Item_Configs_12_Effect_Time;Item_Configs_13_Item_ID;Item_Configs_13_Item_Count;Item_Configs_13_Is_Bind;Item_Configs_13_Effect_Time;Item_Configs_14_Item_ID;Item_Configs_14_Item_Count;Item_Configs_14_Is_Bind;Item_Configs_14_Effect_Time;Item_Configs_15_Item_ID;Item_Configs_15_Item_Count;Item_Configs_15_Is_Bind;Item_Configs_15_Effect_Time;Item_Configs_16_Item_ID;Item_Configs_16_Item_Count;Item_Configs_16_Is_Bind;Item_Configs_16_Effect_Time;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_133.json', $json);