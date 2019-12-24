<? require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

/*
 *
 *  Служебный скрипт для дополнительной
 *  обработки товаров после обмена с 1С
 *
 * */

define("CATALOG_IBLOCK_ID", "1"); // ID инфоблока основного каталога товаров
define("IBLOCK_ID_1C", "2"); // ID инфоблока, в который грузятся товары из 1С

CModule::IncludeModule("iblock");
Cmodule::IncludeModule("catalog");
$blockSection = new CIBlockSection;
$blockElement = new CIBlockElement;
$scriptTimeStart = microtime(true);

/* получим список разделов, из торгового каталога, которые уже существуют  */

$requestSections = CIBlockSection::GetList(
    array("DEPTH_LEVEL"=>"ASC", "SORT"=>"ASC"),
    array("ACTIVE" => "Y", "IBLOCK_ID" => CATALOG_IBLOCK_ID, "GLOBAL_ACTIVE"=>"Y",), 
    false,
    array("IBLOCK_ID", "ID", "NAME", "DEPTH_LEVEL", "IBLOCK_SECTION_ID", "CODE")
);

$arrSections = array(); // массив с разделами
$arrSectionsMap = array(); // карта разделов имя => id

while($arSection = $requestSections->GetNext()) {
    if($arSection["DEPTH_LEVEL"] == 1) { // бренд
        $arrSections[$arSection["ID"]] = $arSection;
        $arrSectionsMap["BRANDS"][$arSection["CODE"]] = $arSection["ID"];
    }
    else { // модель
        $arrSections[$arSection["IBLOCK_SECTION_ID"]]["ITEMS"][] = $arSection;
        $arrSectionsMap["MODELS"][$arSection["CODE"]] = $arSection["ID"];
    }
}

/* получим все элементы из каталога $arrElementsCatalog , чтобы знать: какие обновлять, какие добавлять */

$requestElementCatalog  = CIBlockElement::GetList(
    array("SORT" => "ASC"),
    array("IBLOCK_ID" => CATALOG_IBLOCK_ID), // проверка по артикулу
    false,
    false,
    array("ID","IBLOCK_ID",/*"PROPERTY_CML2_ARTICLE",*/"TIMESTAMP_X","PROPERTY_*")
);
$arrElementsCatalog = [];
while ($elementCatalog = $requestElementCatalog -> GetNextElement()) {
    $item = $elementCatalog->GetFields();
    $property = $elementCatalog->GetProperties();
    $arrElementsCatalog[$property["CML2_ARTICLE"]["VALUE"]] = array(
        "ID" => $item["ID"],
        "ARTICUL" => $property["CML2_ARTICLE"]["VALUE"],
        "TIMESTAMP_X" => $item["TIMESTAMP_X"],
        "PROPERTIES" => $property
    );
}

//p($arrElementsCatalog);

/* обойдем все элементы из 1С */

$arr1CElements = []; //Массив с элементами

$request1CElements  = CIBlockElement::GetList(
    array("SORT" => "ASC"),
    array("IBLOCK_ID" => IBLOCK_ID_1C),
    false,
    false,
    array("ID","DATE_CREATE","CREATED_BY","IBLOCK_ID","ACTIVE","NAME","PREVIEW_TEXT","USER_NAME","CREATED_USER_NAME")
);

$resultCount = 0;
$resultSection = 0;
$resultProduct = 0;
$resultMissed = 0;
$resultUpdated = 0;

while ($elements1C = $request1CElements -> GetNextElement()){


    $item = $elements1C->GetFields();
    $prop["PROPERTIES"] = $elements1C->GetProperties();

    $articul = $prop["PROPERTIES"]["CML2_ARTICLE"]["VALUE"];
    $elementBrand = createCode($prop["PROPERTIES"]["BREND"]["VALUE"]);
    $elementModel = createCode($prop["PROPERTIES"]["MODEL"]["VALUE"]);
    if(empty($elementBrand) || empty($elementModel)) continue; // Если не указан бренд или модель - пропускам

    /* проверим, есть ли такой элемент в основном каталоге */

    if(empty($arrElementsCatalog[$articul])) { // если товар НЕ существует - создаем

        /* определим раздел и создадим новый, если ранее такого не было */

        if (empty($arrSectionsMap["BRANDS"][$elementBrand])) {
            $id = $blockSection->Add(
                Array(
                    "ACTIVE" => "Y",
                    "IBLOCK_SECTION_ID" => "0",
                    "IBLOCK_ID" => CATALOG_IBLOCK_ID,
                    "NAME" => $prop["PROPERTIES"]["BREND"]["VALUE"],
                    "CODE" => $elementBrand,
                )
            );
            $arrSectionsMap["BRANDS"][$elementBrand] = $id;
            $resultSection++;
        }

        if (empty($arrSectionsMap["MODELS"][$elementModel])){
            $id = $blockSection->Add(
                Array(
                    "ACTIVE" => "Y",
                    "IBLOCK_SECTION_ID" => $arrSectionsMap["BRANDS"][$elementBrand],
                    "IBLOCK_ID" => CATALOG_IBLOCK_ID,
                    "NAME" => $prop["PROPERTIES"]["MODEL"]["VALUE"],
                    "CODE" => $elementModel,
                )
            );

            $arrSectionsMap["MODELS"][$elementModel] = $id;
            $resultSection++;
            $curentSection = $id;
        }
        else {
            $curentSection = $arrSectionsMap["MODELS"][$elementModel];
        }

        //echo "<div>".$item["NAME"]." - ".$curentSection."</div>";

        /* добавим новый элемент  */

        $arrProperty = array(
            "TIPORAZMER_2_US" => $prop["PROPERTIES"]["TIPORAZMER_2_US"]["VALUE"], // Типоразмер
            "TIP_TT_TL" => $prop["PROPERTIES"]["TIP_TT_TL"]["VALUE"], // Тип (TT/TL)
            "CML2_ARTICLE" => $prop["PROPERTIES"]["CML2_ARTICLE"]["VALUE"], // Артикул
            "CML2_MANUFACTURER" => $prop["PROPERTIES"]["CML2_MANUFACTURER"]["VALUE"], // Производитель
            "KONSTRUKTSIYA" => $prop["PROPERTIES"]["KONSTRUKTSIYA"]["VALUE"], // Конструкция
            "GLUBINA" => $prop["PROPERTIES"]["GLUBINA"]["VALUE"], // Глубина
            "TIPORAZMER_1_INT" => $prop["PROPERTIES"]["TIPORAZMER_1_INT"]["VALUE"], // Типоразмер 1 (INT)
            "TIPORAZMER_3" => $prop["PROPERTIES"]["TIPORAZMER_1_INT"]["VALUE"], // Типоразмер 3
            "SLOYNOST" => $prop["PROPERTIES"]["SLOYNOST"]["VALUE"], // Слойность
            "BREND" => $prop["PROPERTIES"]["BREND"]["VALUE"], // Бренд
            "DIAMETR_POSADOCHNYY_DYUYM" => $prop["PROPERTIES"]["DIAMETR_POSADOCHNYY_DYUYM"]["VALUE"], // Диаметр посадочный (дюйм)
            "DIAMETR_NARUZHNYY_MM" => $prop["PROPERTIES"]["DIAMETR_NARUZHNYY_MM"]["VALUE"], // Диаметр наружный (мм)
            "SHIRINA_MM" => $prop["PROPERTIES"]["SHIRINA_MM"]["VALUE"], // Ширина (мм)
            "MODEL" => $prop["PROPERTIES"]["MODEL"]["VALUE"], // Модель
        );

        $arrElementProperty = array(
            "MODIFIED_BY" => 2, // элемент изменен пользователем obmen1c
            "IBLOCK_SECTION_ID" => $curentSection,
            "IBLOCK_ID" => CATALOG_IBLOCK_ID,
            "NAME" => $item["NAME"],
            "CODE" => createCode($item["NAME"]),
            "ACTIVE" => "Y",
            "PREVIEW_TEXT" => $item["PREVIEW_TEXT"],
            "PROPERTY_VALUES" => $arrProperty,
        );
        $product = $blockElement->Add($arrElementProperty);

        $productsFields = array(
            "ID" => $product,
            "VAT_ID" => 1, //тип ндс
            "VAT_INCLUDED" => "Y" //НДС входит в стоимость
        );
        if(CCatalogProduct::Add($productsFields)) $resultProduct++;

        $resultCount++;

    }
    else { // Товар уже существует, проверим, надо ли обновить свойства

        $arrProperty = array( // массив со свойствами, который проверяем
            "TIPORAZMER_2_US", // Типоразмер
            "TIP_TT_TL", // Тип (TT/TL)
            "CML2_ARTICLE", // Артикул
            "CML2_MANUFACTURER", // Производитель
            "KONSTRUKTSIYA", // Конструкция
            "GLUBINA", // Глубина
            "TIPORAZMER_1_INT", // Типоразмер 1 (INT)
            "TIPORAZMER_3", // Типоразмер 3
            "SLOYNOST", // Слойность
            "BREND", // Бренд
            "DIAMETR_POSADOCHNYY_DYUYM", // Диаметр посадочный (дюйм)
            "DIAMETR_NARUZHNYY_MM", // Диаметр наружный (мм)
            "SHIRINA_MM", // Ширина (мм)
            "MODEL", // Модель
        );

        $property = [];
        foreach ($arrProperty as $propItem) {
            if($arrElementsCatalog[$articul]["PROPERTIES"][$propItem]["VALUE"] == $prop["PROPERTIES"][$propItem]["VALUE"]) continue;
            else $property[$propItem] = $prop["PROPERTIES"][$propItem]["VALUE"];
        }

        if(!empty($property)) {
            $blockElement->SetPropertyValuesEx($arrElementsCatalog[$articul]["ID"], CATALOG_IBLOCK_ID, $property);
            $resultUpdated++;
        }
        else $resultMissed++;

    }

}

$scriptTime = microtime(true) - $scriptTimeStart;

echo "<div>Элементов всего: ".$resultCount."</div>";
echo "<div>Товаров создано: ".$resultProduct."</div>";
echo "<div>Товаров пропущено: ".$resultMissed."</div>";
echo "<div>Товаров обновлено: ".$resultUpdated."</div>";
echo "<div>Создано разделов: ".$resultSection."</div>";
echo "<div>Время выполнения: ".$scriptTime." сек.</div>";

//p($arr1CElements);