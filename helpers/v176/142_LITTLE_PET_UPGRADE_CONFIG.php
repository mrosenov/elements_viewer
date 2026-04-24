<?php

$configName = "LITTLE_PET_UPGRADE_CONFIG";

$fields = "ID;Name;Feed_Pet_1_Feed_Pet_Item_ID;Feed_Pet_l_Gain_Exp;Feed_Pet_2_Feed_Pet_Item_ID;Feed_Pet_2_Gain_Exp;Default_File_Model;Pet_Upgrade_Info_List_1_File_Model;Pet_Upgrade_Info_List_1_Reguired_Exp;Pet_Upgrade_Info_List_1_Award_Item;Pet_Upgrade_Info_List_2_File_Model;Pet_Upgrade_Info_List_2_Reguired_Exp;Pet_Upgrade_Info_List_2_Award_Item;Pet_Upgrade_Info_List_3_File_Model;Pet_Upgrade_Info_List_3_Reguired_Exp;Pet_Upgrade_Info_List_3_Award_Item;Pet_Upgrade_Info_List_4_File_Model;Pet_Upgrade_Info_List_4_Reguired_Exp;Pet_Upgrade_Info_List_4_Award_Item;Pet_Upgrade_Info_List_5_File_Model;Pet_Upgrade_Info_List_5_Reguired_Exp;Pet_Upgrade_Info_List_5_Award_Item";
$types = "int32;wstring:64;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_142.json', $json);