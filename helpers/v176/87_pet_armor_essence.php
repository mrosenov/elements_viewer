<?php

$configName = "PET_ARMOR_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Description;Pet_Type_Mask;Required_Pet_Level;Required_Pet_Star;Required_Pet_Grade;Equipment_Location;Enhance_Price;Max_Health;Min_Health;Max_Defense;Min_Defense;Max_Attack_Power;Min_Attack_Power;Max_Stun_Resistance;Min_Stun_Resistance;Max_Weaken_Resistance;Min_Weaken_Resistance;Max_Paralyze_Resistance;Min_Paralyze_Resistance;Max_Silence_Resistance;Min_Silence_Resistance;Max_Sleep_Resistance;Min_Sleep_Resistance;Max_Slow_Resistance;Min_Slow_Resistance;Max_Accuracy;Min_Accuracy;Max_Evasion;Min_Evasion;Max_Spirit;Min_Spirit;Max_Critstrike_Rate;Min_Critstrike_Rate;Max_Critstrike_Bonus;Min_Critstrike_Bonus;Attribute_ID;Attribute_Level;Attribute_ID;Attribute_Level;Attribute_ID;Attribute_Level;Attribute_ID;Attribute_Level;Attribute_ID;Attribute_Level;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;wstring:32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;float;float;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_87.json', $json);