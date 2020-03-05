<? require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

/*
 *
 *  Служебный скрипт для дополнительной
 *  обработки товаров после обмена с 1С
 *  Добавление цен и остатков на складах из ТП
 *
 * */

define("CATALOG_IBLOCK_ID", "25"); // ID инфоблока основного каталога товаров
define("IBLOCK_ID_1C", "44"); // ID инфоблока, в который грузятся товары из 1С

$arStorageMask = array( // массив со складами, которые необходимо учитывать
    "83d9ae61-1b39-11e1-84da-001517610914" => "SKLAD1", // Склад ИСТК-Ярославль сервис запчасти
    "e0442465-d396-11e4-897f-00505693408f" => "SKLAD2", // Склад Череповец
    "6a844d12-424f-11e5-9512-00505693408f" => "SKLAD3", // Склад ИСТК-Пятигорск сервис запчасти
    "4e68654a-4729-11e5-9512-00505693408f" => "SKLAD4", // Склад ИСТК-Волгоград сервис запчасти
    "796de715-07eb-11e8-a2c1-0050569302c3" => "SKLAD5", // Склад СТК-Симферополь сервис запчасти
    "3f377058-6498-11e8-a2c5-0050569302c3" => "SKLAD6", // Склад ИСТК-Владивосток
    "538f14e0-ba74-11df-a433-001517610914" => "SKLAD7", // Склад сервисной службы в Обухово (Осн.Центральный)
    "d1caa1df-7c3b-11e3-a7a3-00505693408f" => "SKLAD8", // Склад ИСТК-Ростов-на-Дону сервис запчасти
    "a6d5b7cf-806e-11e0-b304-001517610914" => "SKLAD9", // Склад Уфа сервис запчасти
    "cf6af2e8-8d17-11e0-b310-001517610914" => "SKLAD10", // Склад ИСТК-Краснодар сервис запчасти
);
$storagePriority = array( // массив со складами, которые необходимо учитывать
    "83d9ae61-1b39-11e1-84da-001517610914" => 100, // Склад ИСТК-Ярославль сервис запчасти
    "cf6af2e8-8d17-11e0-b310-001517610914" => 99, // Склад ИСТК-Краснодар сервис запчасти
    "d1caa1df-7c3b-11e3-a7a3-00505693408f" => 98, // Склад ИСТК-Ростов-на-Дону сервис запчасти
    "6a844d12-424f-11e5-9512-00505693408f" => 97, // Склад ИСТК-Пятигорск сервис запчасти
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
    array("ID", "IBLOCK_ID", "CATALOG_GROUP_1", "PROPERTY_CML2_ARTICLE", "PROPERTY_SKLAD_VPUTI", "PROPERTY_RETAIL_PRICE")
);
$products = [];
while ($element = $requestElements -> GetNextElement()) {
    $item = $element->GetFields();
    $products[$item["PROPERTY_CML2_ARTICLE_VALUE"]] = array(
        "ID" => $item["ID"],
        "PRICE" => $item["CATALOG_PRICE_1"],
        "QUANTITY" => $item["CATALOG_QUANTITY"],
        "IN_WAY" => $item["PROPERTY_SKLAD_VPUTI_VALUE"],
        "RETAIL_PRICE" => $item["PROPERTY_RETAIL_PRICE_VALUE"],
    );
}

/* создадим массив $arrOffers с торговыми предложениями */

$arrOffers = [];
foreach ($xml->ПакетПредложений->Предложения->Предложение as $arOffer) {

    /*  получим цену товара и количество на складах
        Цена - максимальная себестоимость на одном из складов */
    $storage = [];
    $priority = 0;
    $mainPrice = 0;
    foreach ($arOffer->Остатки->Склад as $Storage){

        $storageId = strval($Storage->ИдСклада);
        $storage[$storageId] = strval($Storage->Остаток);
        $costprice = strval($Storage->Себестоимость);

        if( $storagePriority[$storageId] > $priority ) {
            $mainPrice = $costprice;
            $priority = $storagePriority[$storageId];
        }

    }

    /* запишем в массив */

    $arrOffers[strval($arOffer->Ид)] = Array(
        "price" => $mainPrice,
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

    /* Проставляем цену */

    if(empty($products[$articul]["ID"])) {
        $priceResult = "Товар не найден";
        continue;
    }
    else if($products[$articul]["PRICE"] != $arrOffers[$id]["price"] && !empty($arrOffers[$id]["price"])){

        $arFields = Array(
            "PRODUCT_ID" => $products[$articul]["ID"],
            "CATALOG_GROUP_ID" => 1, // Базовая цена
            "PRICE" => $arrOffers[$id]["price"],
            "CURRENCY" => "RUB",
        );

        /* получим код ценового предложения */
        $requestPrice = CPrice::GetList(array(), array("PRODUCT_ID" => $products[$articul]["ID"], "CATALOG_GROUP_ID" => 1));
        if ($price = $requestPrice->Fetch()) {
            /* скроем на время отладки */
            CPrice::Update($price["ID"], $arFields);
            $priceResult = "Обновлено";
        }
        else {
            /* скроем на время отладки */
            CPrice::Add($arFields);
            $priceResult = "Добавлено";
        }
    }
    else {
        $priceResult = "Пропущен";
    }

    /* Розничная цена. Если не 0 - запишем в свойства. */

    $retailPrice = strval($arProduct->ЦенаФикс);
    if($retailPrice > 0 && $retailPrice != $products[$articul]["RETAIL_PRICE"]) {
        $blockElement->SetPropertyValuesEx($products[$articul]["ID"], CATALOG_IBLOCK_ID, array("RETAIL_PRICE" => $retailPrice));
    }

    /* проставляем склады */

    /* посчитаем количество товара на складах */

    $storageCount = 0;
    $propertyElement = []; // массив свойство => значение для записи в доп поля
    $priperty = [];
    foreach($arrOffers[$id]["storage"] as $keyStorage => $arStorage) { /* подсчитаем общее количество */
        if(!empty($arStorageMask[$keyStorage]))  {
            $storageCount = $storageCount + $arStorage; // общая сумма на складах

            /* добавим количество в массив $priperty для записи в свойства */
            $priperty[$arStorageMask[$keyStorage]] = $arStorage;

        }
    }

    foreach ($arStorageMask as $item) {
        if(!empty($priperty[$item])) continue;
        $priperty[$item] = 0;
    }

    if(!empty($priperty)) $blockElement->SetPropertyValuesEx($products[$articul]["ID"], CATALOG_IBLOCK_ID, $priperty); // запишем в массив TODO: оптимизировать: проверять, нужно обновлять или нет


    /* надо приплюсовать количество из свойства "склад в пути", перед тем как добавить товар */
    $storageCount = $storageCount + $products[$articul]["IN_WAY"];

    if($storageCount != $products[$articul]["QUANTITY"]) {

        /* Добавим количество в товар */
        $storageID = false;
        $requestStorage = CCatalogStoreProduct::GetList( array(), array( "PRODUCT_ID" => $products[$articul]["ID"], "STORE_ID" => 1 ) );
        if ($arrStorage = $requestStorage->Fetch()) $storageID = $arrStorage["ID"];

        $arFieldsStorage = Array(
            "PRODUCT_ID" => $products[$articul]["ID"],
            "STORE_ID" => 1,
            "AMOUNT" => $storageCount,
        );
        if ( $storageID ) {
            /* скроем на время отладки */
            CCatalogStoreProduct::Update($storageID, $arFieldsStorage);
            CCatalogProduct::add(array("ID" => $products[$articul]["ID"], "QUANTITY" => $storageCount));
            $storageResult = "Обновлено";
        }
        else {
            /* скроем на время отладки */
            CCatalogStoreProduct::Add($arFieldsStorage);
            CCatalogProduct::add(array("ID" => $products[$articul]["ID"], "QUANTITY" => $storageCount));
            $storageResult = "Добавлено";
        }

    }
    else $storageResult = "Пропущено";

    $content[] = $id;

    echo "<tr>
                <td>Артикул: ".$articul."</td>
                <td>Цена: нов. ".$arrOffers[$id]["price"].", стар.: ".$products[$articul]["PRICE"]." [".$priceResult."]</td>
                <td>На складе: нов. ".$storageCount." шт., стар.: ".$products[$articul]["QUANTITY"]." [".$storageResult."]</td>
          </tr>";
    $counter++;
}
echo "</table>";