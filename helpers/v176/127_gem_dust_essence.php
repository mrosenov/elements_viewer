<?php

$configName = "GEM_DUST_ESSENCE";

$fields = "ID;Name;Model_Path_ID;Icon_Path_ID;Grade;Upgrade_Prob_1_Ordinary_Upgrade_Prob;Upgrade_Prob_1_Perfect_Upgrade_Prob;Upgrade_Prob_2_Ordinary_Upgrade_Prob;Upgrade_Prob_2_Perfect_Upgrade_Prob;Upgrade_Prob_3_Ordinary_Upgrade_Prob;Upgrade_Prob_3_Perfect_Upgrade_Prob;Upgrade_Prob_4_Ordinary_Upgrade_Prob;Upgrade_Prob_4_Perfect_Upgrade_Prob;Upgrade_Prob_5_Ordinary_Upgrade_Prob;Upgrade_Prob_5_Perfect_Upgrade_Prob;Upgrade_Prob_6_Ordinary_Upgrade_Prob;Upgrade_Prob_6_Perfect_Upgrade_Prob;Upgrade_Prob_7_Ordinary_Upgrade_Prob;Upgrade_Prob_7_Perfect_Upgrade_Prob;Upgrade_Prob_8_Ordinary_Upgrade_Prob;Upgrade_Prob_8_Perfect_Upgrade_Prob;Upgrade_Prob_9_Ordinary_Upgrade_Prob;Upgrade_Prob_9_Perfect_Upgrade_Prob;Sell_Price;Buy_Price;Stack_Amt;Trade_Behavior";
$types = "int32;wstring:64;int32;int32;int32;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;float;int32;int32;int32;int32";

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

file_put_contents('list_127.json', $json);