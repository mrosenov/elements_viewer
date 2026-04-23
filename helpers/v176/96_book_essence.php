<?php

$configName = "BOOK_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Book_Type;File_Content_1;File_Content_2;File_Content_3;File_Content_4;File_Content_5;File_Content_6;File_Content_7;File_Content_8;File_Content_9;File_Content_10;File_Content_11;File_Content_12;File_Content_13;File_Content_14;File_Content_15;File_Content_16;File_Content_17;File_Content_18;File_Content_19;File_Content_20;File_Content_21;File_Content_22;File_Content_23;File_Content_24;File_Content_25;File_Content_26;File_Content_27;File_Content_28;File_Content_29;File_Content_30;File_Content_31;File_Content_32;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_96.json', $json);