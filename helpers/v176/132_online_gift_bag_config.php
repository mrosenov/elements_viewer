<?php

$configName = "ONLINE_GIFT_BAG_CONFIG";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Award_Type;Start_Time_Per_Day;End_Time_Per_Day;Start_Time_Per_Interval;End_Time_Per_Interval;Expire_Time;Renascence_Count;Require_Min_Level;Require_Max_Level;Require_Gender;Character_Combo_ID;Character_Combo_ID_2;Require_Race;Small_Gift_Baqs_1_Gift_Baq_ID;Small_Gift_Baqs_1_Deliver_Interval;Small_Gift_Baqs_2_Gift_Baq_ID;Small_Gift_Baqs_2_Deliver_Interval;Small_Gift_Baqs_3_Gift_Baq_ID;Small_Gift_Baqs_3_Deliver_Interval;Small_Gift_Baqs_4_Gift_Baq_ID;Small_Gift_Baqs_4_Deliver_Interval;Small_Gift_Baqs_5_Gift_Baq_ID;Small_Gift_Baqs_5_Deliver_Interval;Small_Gift_Baqs_6_Gift_Baq_ID;Small_Gift_Baqs_6_Deliver_Interval;Small_Gift_Baqs_7_Gift_Baq_ID;Small_Gift_Baqs_7_Deliver_Interval;Small_Gift_Baqs_8_Gift_Baq_ID;Small_Gift_Baqs_8_Deliver_Interval;Small_Gift_Baqs_9_Gift_Baq_ID;Small_Gift_Baqs_9_Deliver_Interval;Small_Gift_Baqs_10_Gift_Baq_ID;Small_Gift_Baqs_10_Deliver_Interval;Small_Gift_Baqs_11_Gift_Baq_ID;Small_Gift_Baqs_11_Deliver_Interval;Small_Gift_Baqs_12_Gift_Baq_ID;Small_Gift_Baqs_12_Deliver_Interval;Small_Gift_Baqs_13_Gift_Baq_ID;Small_Gift_Baqs_13_Deliver_Interval;Small_Gift_Baqs_14_Gift_Baq_ID;Small_Gift_Baqs_14_Deliver_Interval;Small_Gift_Baqs_15_Gift_Baq_ID;Small_Gift_Baqs_15_Deliver_Interval;Small_Gift_Baqs_16_Gift_Baq_ID;Small_Gift_Baqs_16_Deliver_Interval";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int64;int64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_132.json', $json);