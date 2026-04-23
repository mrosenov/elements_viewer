<?php

$configName = "RENASCENCE_PROP_CONFIG";

$fields = "ID;Name;Occup_Level_0;Occup_Level_0_2_;Occup_Level_1_;Occup_Level_1_2_;Occup_Level_2_;Occup_Level_2_2_;Occup_Level_3_;Occup_Level_3_2;HP_1;HP_2;HP_3;HP_4;HP_5;HP_6;HP_7;HP_8;HP_9;HP_10;HP_11;HP_12;HP_13;HP_14;HP_15;MP_1;MP_2;MP_3;MP_4;MP_5;MP_6;MP_7;MP_8;MP_9;MP_10;MP_11;MP_12;MP_13;MP_14;MP_15;Dmq_1;Dmq_2;Dmq_3;Dmq_4;Dmq_5;Dmq_6;Dmq_7;Dmq_8;Dmq_9;Dmq_10;Dmq_11;Dmq_12;Dmq_13;Dmq_14;Dmq_15;Def_1;Def_2;Def_3;Def_4;Def_5;Def_6;Def_7;Def_8;Def_9;Def_10;Def_11;Def_12;Def_13;Def_14;Def_15;Attack_1;Attack_2;Attack_3;Attack_4;Attack_5;Attack_6;Attack_7;Attack_8;Attack_9;Attack_10;Attack_11;Attack_12;Attack_13;Attack_14;Attack_15;Armor_1;Armor_2;Armor_3;Armor_4;Armor_5;Armor_6;Armor_7;Armor_8;Armor_9;Armor_10;Armor_11;Armor_12;Armor_13;Armor_14;Armor_15;Criti_Rate_1;Criti_Rate_2;Criti_Rate_3;Criti_Rate_4;Criti_Rate_5;Criti_Rate_6;Criti_Rate_7;Criti_Rate_8;Criti_Rate_9;Criti_Rate_10;Criti_Rate_11;Criti_Rate_12;Criti_Rate_13;Criti_Rate_14;Criti_Rate_15;Criti_Damage_1;Criti_Damage_2;Criti_Damage_3;Criti_Damage_4;Criti_Damage_5;Criti_Damage_6;Criti_Damage_7;Criti_Damage_8;Criti_Damage_9;Criti_Damage_10;Criti_Damage_11;Criti_Damage_12;Criti_Damage_13;Criti_Damage_14;Criti_Damage_15;Anti_1_1;Anti_1_2;Anti_1_3;Anti_1_4;Anti_1_5;Anti_1_6;Anti_1_7;Anti_1_8;Anti_1_9;Anti_1_10;Anti_1_11;Anti_1_12;Anti_1_13;Anti_1_14;Anti_1_15;Anti_2_1;Anti_2_2;Anti_2_3;Anti_2_4;Anti_2_5;Anti_2_6;Anti_2_7;Anti_2_8;Anti_2_9;Anti_2_10;Anti_2_11;Anti_2_12;Anti_2_13;Anti_2_14;Anti_2_15;Anti_3_1;Anti_3_2;Anti_3_3;Anti_3_4;Anti_3_5;Anti_3_6;Anti_3_7;Anti_3_8;Anti_3_9;Anti_3_10;Anti_3_11;Anti_3_12;Anti_3_13;Anti_3_14;Anti_3_15;Anti_4_1;Anti_4_2;Anti_4_3;Anti_4_4;Anti_4_5;Anti_4_6;Anti_4_7;Anti_4_8;Anti_4_9;Anti_4_10;Anti_4_11;Anti_4_12;Anti_4_13;Anti_4_14;Anti_4_15;Anti_5_1;Anti_5_2;Anti_5_3;Anti_5_4;Anti_5_5;Anti_5_6;Anti_5_7;Anti_5_8;Anti_5_9;Anti_5_10;Anti_5_11;Anti_5_12;Anti_5_13;Anti_5_14;Anti_5_15;Anti_6_1;Anti_6_2;Anti_6_3;Anti_6_4;Anti_6_5;Anti_6_6;Anti_6_7;Anti_6_8;Anti_6_9;Anti_6_10;Anti_6_11;Anti_6_12;Anti_6_13;Anti_6_14;Anti_6_15";
$types = "int32;wstring:64;int64;int64;int64;int64;int64;int64;int64;int64;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float";

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

file_put_contents('list_92.json', $json);