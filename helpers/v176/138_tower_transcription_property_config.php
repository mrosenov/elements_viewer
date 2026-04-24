<?php

$configName = "TOWER_TRANSCRIPTION_PROPERTY_CONFIG";

$fields = "ID;Name;Tower_Layer;Monster_Gen_Prob_1;Monster_Gen_Prob_2;Monster_Gen_Prob_3;Monster_Gen_Prob_4;Monster_Range_Center_1;Monster_Range_Center_2;Monster_Range_Center_3;Monster_Range_Radius;Renascence_Pos_1;Renascence_Pos_2;Renascence_Pos_3;Success_Controller_ID;Monster_Addon_Property_1_HP;Monster_Addon_Property_1_MP;Monster_Addon_Property_1_Attack;Monster_Addon_Property_1_Defence;Monster_Addon_Property_1_Addon_Damage;Monster_Addon_Property_1_Damage_Resistance;Monster_Addon_Property_1_Hit;Monster_Addon_Property_1_Evade;Monster_Addon_Property_1_Critical_Rate;Monster_Addon_Property_1_Critical_Damage;Monster_Addon_Property_1_Resist_1;Monster_Addon_Property_1_Resist_2;Monster_Addon_Property_1_Resist_3;Monster_Addon_Property_1_Resist_4;Monster_Addon_Property_1_Resist_5;Monster_Addon_Property_1_Resist_6;Monster_Addon_Property_1_Anti_Critical_Rate;Monster_Addon_Property_1_Anti_Critical_Damage;Monster_Addon_Property_1_Skill_Armor_Rate;Monster_Addon_Property_1_Skill_Attack_Rate;Monster_Addon_Property_2_HP;Monster_Addon_Property_2_MP;Monster_Addon_Property_2_Attack;Monster_Addon_Property_2_Defence;Monster_Addon_Property_2_Addon_Damage;Monster_Addon_Property_2_Damage_Resistance;Monster_Addon_Property_2_Hit;Monster_Addon_Property_2_Evade;Monster_Addon_Property_2_Critical_Rate;Monster_Addon_Property_2_Critical_Damage;Monster_Addon_Property_2_Resist_1;Monster_Addon_Property_2_Resist_2;Monster_Addon_Property_2_Resist_3;Monster_Addon_Property_2_Resist_4;Monster_Addon_Property_2_Resist_5;Monster_Addon_Property_2_Resist_6;Monster_Addon_Property_2_Anti_Critical_Rate;Monster_Addon_Property_2_Anti_Critical_Damage;Monster_Addon_Property_2_Skill_Armor_Rate;Monster_Addon_Property_2_Skill_Attack_Rate;Monster_Addon_Property_3_HP;Monster_Addon_Property_3_MP;Monster_Addon_Property_3_Attack;Monster_Addon_Property_3_Defence;Monster_Addon_Property_3_Addon_Damage;Monster_Addon_Property_3_Damage_Resistance;Monster_Addon_Property_3_Hit;Monster_Addon_Property_3_Evade;Monster_Addon_Property_3_Critical_Rate;Monster_Addon_Property_3_Critical_Damage;Monster_Addon_Property_3_Resist_1;Monster_Addon_Property_3_Resist_2;Monster_Addon_Property_3_Resist_3;Monster_Addon_Property_3_Resist_4;Monster_Addon_Property_3_Resist_5;Monster_Addon_Property_3_Resist_6;Monster_Addon_Property_3_Anti_Critical_Rate;Monster_Addon_Property_3_Anti_Critical_Damage;Monster_Addon_Property_3_Skill_Armor_Rate;Monster_Addon_Property_3_Skill_Attack_Rate;Monster_ID_List_1;Monster_ID_List_2;Monster_ID_List_3;Monster_ID_List_4;Monster_ID_List_5;Monster_ID_List_6;Monster_ID_List_7;Monster_ID_List_8;Monster_ID_List_9;Monster_ID_List_10;Monster_ID_List_11;Monster_ID_List_12;Monster_ID_List_13;Monster_ID_List_14;Monster_ID_List_15;Monster_ID_List_16;Monster_ID_List_17;Monster_ID_List_18;Monster_ID_List_19;Monster_ID_List_20;Monster_ID_List_21;Monster_ID_List_22;Monster_ID_List_23;Monster_ID_List_24;Monster_ID_List_25;Monster_ID_List_26;Monster_ID_List_27;Monster_ID_List_28;Monster_ID_List_29;Monster_ID_List_30;Monster_ID_List_31;Monster_ID_List_32;Life_Time_Award_1_Item_ID;Life_Time_Award_1_Item_Count;Life_Time_Award_2_Item_ID;Life_Time_Award_2_Item_Count;Life_Time_Award_3_Item_ID;Life_Time_Award_3_Item_Count;Life_Time_Award_4_Item_ID;Life_Time_Award_4_Item_Count;Life_Time_Award_5_Item_ID;Life_Time_Award_5_Item_Count;Single_Time_Award_1_Item_ID;Single_Time_Award_1_Item_Count;Single_Time_Award_2_Item_ID;Single_Time_Award_2_Item_Count;Single_Time_Award_3_Item_ID;Single_Time_Award_3_Item_Count;Single_Time_Award_4_Item_ID;Single_Time_Award_4_Item_Count;Single_Time_Award_5_Item_ID;Single_Time_Award_5_Item_Count";
$types = "int32;wstring:64;int32;float;float;float;float;float;float;float;int32;float;float;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;float;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32;int32";

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

file_put_contents('list_138.json', $json);