<? require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

/*
 *
 *  Служебный скрипт для дополнительной
 *  обработки товаров после обмена с 1С
 *  Добавление цен и остатков на складах из ТП
 *
 * */

define("CATALOG_IBLOCK_ID", "1"); // ID инфоблока основного каталога товаров
define("IBLOCK_ID_1C", "2"); // ID инфоблока, в который грузятся товары из 1С

$arStorageMask = array( // массив со складами, которые необходимо учитывать
    "b18c099c-ac20-11e8-a2ca-0050569302c3", // Склад НША-Ростов-на-Дону (Рос-Дон М)
    "a6065ab6-cf92-11e3-9182-00505693408f", // Склад НША-Уфа
    "08e1651a-d7eb-11e6-9d37-005056931833", // Склад НША-Новосибирск (ШинКарго)
    "1760cf9f-e9fe-11e6-91a1-005056931833", // Склад НША-Липецк (АСШ)
    "8396453b-297c-11e6-ab05-00505693408f", // Склад НША-Пермь (ИСТК)
    "a6065ab7-cf92-11e3-9182-00505693408f", // Склад НША-Обухово 2
);

CModule::IncludeModule("iblock");
Cmodule::IncludeModule("catalog");
$blockElement = new CIBlockElement;

/* получим содержимое файла импорта */

if (file_exists($_SERVER["DOCUMENT_ROOT"].'/upload/1c_catalog/import.xml')) {
    $xml = simplexml_load_file($_SERVER["DOCUMENT_ROOT"].'/upload/1c_catalog/import.xml');
} else die ();

/* получим массив $products с товарами, где ключ - это артикул */

$requestElements  = $blockElement::GetList(
    array("SORT" => "ASC"),
    array("IBLOCK_ID" => CATALOG_IBLOCK_ID),
    false,
    false,
    array("ID", "CATALOG_GROUP_1", "PROPERTY_CML2_ARTICLE")
);
$products = [];
while ($element = $requestElements -> GetNextElement()) {
    $item = $element->GetFields();
    $products[$item["PROPERTY_CML2_ARTICLE_VALUE"]] = array(
        "ID" => $item["ID"],
        "PRICE" => $item["CATALOG_PRICE_1"],
    );
}

/* создадим массив $arrOffers с торговыми предложениями */

$arrOffers = [];
foreach ($xml->ПакетПредложений->Предложения->Предложение as $arOffer) {

    $storage = array();
    foreach ($arOffer->Остатки->Склад as $Storage){
        $storage[strval($Storage->ИдСклада)] = strval($Storage->Остаток);
    }

    $arrOffers[strval($arOffer->Ид)] = Array(
        "price" => strval($arOffer->Цены->Цена->ЦенаЗаЕдиницу),
        "storage" => $storage
    );
}

/* Обойдем товары из файла 1С */

echo "<table width='100%'>";
$counter = 0;
foreach ($xml->Каталог->Товары->Товар as $arProduct) {

    if(empty($arProduct->Артикул)) {
        $counter++;
        continue;
    }

    $articul = strval($arProduct->Артикул);
    $id = strval($arProduct->Ид);

    if(empty($products[$articul]["ID"])) {
        $priceResult = "Товар не найден";
    }
    else if(empty($products[$articul]["PRICE"])) {

        $arFields = Array(
            "PRODUCT_ID" => $products[$articul]["ID"],
            "CATALOG_GROUP_ID" => 1, // Базовая цена
            "PRICE" => $arrOffers[$id]["price"],
            "CURRENCY" => "RUB",
        );

        if (CPrice::Add($arFields)) $priceResult = "Добавлено";
        else $priceResult = "Ошибка ID" . $products[$articul]["ID"] . ", цена " . $arrOffers[$id]["price"];
    }
    else if($products[$articul]["PRICE"] != $arrOffers[$id]["price"]){
        $arFields = Array(
            "PRODUCT_ID" => $products[$articul]["ID"],
            "CATALOG_GROUP_ID" => 1, // Базовая цена
            "PRICE" => $arrOffers[$id]["price"],
            "CURRENCY" => "RUB",
        );

        /* получим код ценового предложения */
        $requestPrice = CPrice::GetList(array(), array("PRODUCT_ID" => $products[$articul]["ID"], "CATALOG_GROUP_ID" => 1));
        if ($price = $requestPrice->Fetch()) {
            CPrice::Update($price["ID"], $arFields);
            $priceResult = "Обновлено";
        }
        else {
            CPrice::Add($arFields);
            $priceResult = "Добавлено";
        }
    }
    else {
        $priceResult = "Пропущен";
    }

    echo "<tr>
                <td>ID: ".$id."</td>
                <td>Артикул: ".$articul."</td>
                <td>Цена: ".$products[$articul]["ID"]." [".$priceResult."]</td>
          </tr>";
    $counter++;
}
echo "</table>";