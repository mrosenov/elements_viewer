<?php

$configName = "SPECIAL_ID_CONFIG";

$fields = "ID;Name;Version;ID_Tmpl_Jinfashen_Confiq;Monster_Drop_Prob;ID_Money_Matter;ID_Speaker;ID_Destroying_Matter;ID_Townscroll;ID_Attacker_Droptable;ID_Defender_Droptable;ID_Talisman_Reset_Matter;Fee_Talisman_Merge;Fee_Talisman_Enchant;ID_War_Material_1;ID_War_Material_2;Fee_Pet_Gained;Fee_Pet_Free;Fee_Pet_Refine;Fee_Pet_Rename;ID_Male_War_Statue;ID_Female_War_Statue;ID_Vehicle_Upgrade_Item;Fee_Lock;Fee_Unlock;Unlock_Item_ID;Unlocking_Item_ID;ID_Damaged_Item;ID_Repair_Damaged_Item;ID_Bleed_Identity_Host_Item;Fee_Restore_Soul;ID_Lowgrade_Soul_Stone;ID_Highgrade_Soul_Stone;ID_Enter_Arena_Item;ID_Enter_Arena_Reborn_Item;ID_Change_Face_Item;ID_Speaker_2;ID_Unigue_Bid_Item;Fee_Gem_Refine;Fee_Gem_Extract;Fee_Gem_Tessellation;Fee_Gem_Single_Dismantle;Fee_Gem_Smelt;Fee_Gem_Slot_Identify;Fee_Gem_Slot_Customize;Fee_Gem_Slot_Rebuild;Gem_Upgrade_Upper_Limit;ID_Gem_Smelt_Article;ID_Gem_Smelt_Article_1;ID_Gem_Smelt_Article_2;ID_Gem_Refine_Article;ID_Gem_Refine_Article_1;ID_Gem_Refine_Article_2;ID_Gem_Slot_Lock_Article;ID_Gem_Slot_Lock_Article_1;ID_Gem_Slot_Lock_Article_2;ID_Gem_Slot_Rebuild_Article_1;ID_Gem_Slot_Rebuild_Article_2;ID_Gem_Slot_Rebuild_Article_3;ID_Consign_Role_Item;Consign_Role_Item_Count;Consign_Role_Type;ID_Treasure_Region_Upgrade_Item;ID_Speaker_Special_1;ID_Speaker_Special_2;ID_Speaker_Special_3;ID_Speaker_Special_Anony_1;ID_Speaker_Special_Anony_2;ID_Speaker_Special_Anony_3;ID_Cross_Speaker_Special_1;ID_Cross_Speaker_Special_2;ID_Cross_Speaker_Special_3;ID_Cross_Speaker_Special_Anony_1;ID_Cross_Speaker_Special_Anony_2;ID_Cross_Speaker_Special_Anony_3;ID_Change_Name_1;ID_Change_Name_2;ID_Change_Name_3;ID_Change_Name_Family_1;ID_Change_Name_Family_2;ID_Change_Name_Family_3;ID_Change_Name_Guild_1;ID_Change_Name_Guild_2;ID_Change_Name_Guild_3;ID_Eguip_Hole_1;ID_Eguip_Hole_2;ID_Eguip_Hole_3;ID_Xingzuo_Levelup_1;IDXingzuo_Levelup_2;ID_Xingzuo_Levelup_3;Fee_Xingzuo_Add;Fee_Xingzuo_Remove;Fee_Xingzuo_Identify;ID_Fix_Prop_Lose;ID_Rose_Free;ID_Rose_Money;ID_Rename_Equip_Props_1;ID_Rename_Equip_Props_2;ID_Rune_2013_Fraqment_1;ID_Rune_2013_Fraqment_2;ID_Rune_2013_Erase_1;ID_Rune_2013_Erase_2;ID_Rune_2013_Merqe_Extra_Num_1;ID_Rune_2013_Merqe_Extra_Num_2;ID_Matrix_Card_Break;Fee_Vehicle_Enhance;Unknown;Unknown;Unknown";
$types = "int32;wstring:64;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_71.json', $json);