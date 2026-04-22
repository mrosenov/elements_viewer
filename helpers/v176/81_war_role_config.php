<?php

$configName = "WAR_ROLE_CONFIG";

$fields = "ID;Name;Attack_Level_Max;Attack_Level_1_Value;Attack_Level_1_Extra;Attack_Level_2_Value;Attack_Level_2_Extra;Attack_Level_3_Value;Attack_Level_3_Extra;Attack_Level_4_Value;Attack_Level_4_Extra;Attack_Level_5_Value;Attack_Level_5_Extra;Attack_Level_6_Value;Attack_Level_6_Extra;Attack_Level_7_Value;Attack_Level_7_Extra;Attack_Level_8_Value;Attack_Level_8_Extra;Attack_Level_9_Value;Attack_Level_9_Extra;Attack_Level_10_Value;Attack_Level_10_Extra;Attack_Level_11_Value;Attack_Level_11_Extra;Attack_Level_12_Value;Attack_Level_12_Extra;Attack_Level_13_Value;Attack_Level_13_Extra;Attack_Level_14_Value;Attack_Level_14_Extra;Attack_Level_15_Value;Attack_Level_15_Extra;Attack_Level_16_Value;Attack_Level_16_Extra;Attack_Level_17_Value;Attack_Level_17_Extra;Attack_Level_18_Value;Attack_Level_18_Extra;Attack_Level_19_Value;Attack_Level_19_Extra;Attack_Level_20_Value;Attack_Level_20_Extra;Attack_War_Material;Attack_Co_1;Attack_Co_2;Attack_Co_3;Defense_Level_Max;Defense_Level_1_Value;Defense_Level_1_Extra;Defense_Level_2_Value;Defense_Level_2_Extra;Defense_Level_3_Value;Defense_Level_3_Extra;Defense_Level_4_Value;Defense_Level_4_Extra;Defense_Level_5_Value;Defense_Level_5_Extra;Defense_Level_6_Value;Defense_Level_6_Extra;Defense_Level_7_Value;Defense_Level_7_Extra;Defense_Level_8_Value;Defense_Level_8_Extra;Defense_Level_9_Value;Defense_Level_9_Extra;Defense_Level_10_Value;Defense_Level_10_Extra;Defense_Level_11_Value;Defense_Level_11_Extra;Defense_Level_12_Value;Defense_Level_12_Extra;Defense_Level_13_Value;Defense_Level_13_Extra;Defense_Level_14_Value;Defense_Level_14_Extra;Defense_Level_15_Value;Defense_Level_15_Extra;Defense_Level_16_Value;Defense_Level_16_Extra;Defense_Level_17_Value;Defense_Level_17_Extra;Defense_Level_18_Value;Defense_Level_18_Extra;Defense_Level_19_Value;Defense_Level_19_Extra;Defense_Level_20_Value;Defense_Level_20_Extra;Defense_War_Material;Defence_Co_1;Defence_Co_2;Defence_Co_3;Range_Max_Lev;Range_Values_1;Range_Values_2;Range_Values_3;Range_Values_4;Range_Values_5;Range_War_Material;Range_Co_1;Range_Co_2;Range_Co_3;Strategy_Max_Lev;Strategy_ID_1;Strategy_ID_2;Strategy_ID_3;Strategy_ID_4;Strategy_ID_5;Strategy_War_Material;Strategy_Co_1;Strategy_Co_2;Strategy_Co_3;InitHP;HP_War_Material;Lvlup_HP;HP_Material_Num";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;int32;int32;int32;int32;int32;int32;float;float;float;int32;int32;int32;int32";

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

file_put_contents('list_81.json', $json);