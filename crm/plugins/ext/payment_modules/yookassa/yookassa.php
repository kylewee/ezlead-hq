<?php

//$logfile = __DIR__ . '/webhook_log.txt';
// Запись обычного лога webhook
//file_put_contents($logfile, date('c') . "\n" . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n" . file_get_contents('php://input') . "\n", FILE_APPEND);


class yookassa
{
    public $title;
    public $site;
    public $api;
    public $version;
    public $country;

    function __construct()
    {
        $this->title = 'ЮKassa';
        $this->site = 'https://yookassa.ru';
        $this->api = 'https://yookassa.ru/developers/api';
        $this->version = '1.0.1';
        $this->country = 'RU';
    }

    public function configuration()
    {
        $cfg = array();

        $cfg[] = array(
            'key' => 'shop_id',
            'type' => 'input',
            'default' => '',
            'title' => 'shopId',
            'description' => 'shop_id из личного кабинета ЮKassa',
            'params' => array(
                'class' => 'form-control required'
            )
        );

        $cfg[] = array(
            'key' => 'secret_key',
            'type' => 'input',
            'default' => '',
            'title' => 'Секретный ключ',
            'description' => 'Ключ API из личного кабинета ЮKassa',
            'params' => array(
                'class' => 'form-control required'
            )
        );
        $cfg[] = array(
            'key' => 'confirm_url',
            'type' => 'text',
            'default' => '<input type="text" value="' . url_for_file('api/ipn.php?module_id=' . ($_GET['id']??0)) . '" class="form-control select-all" readonly>',
            'title' => 'URL для уведомлений',
            'description' => 'Адрес для получения уведомлений от ЮKassa. В личном кабинете ЮKassa включите уведомление на данный адрес',            
        );
        $cfg[] = array(
            'key' => 'item_name',
            'type' => 'input',
            'default' => 'Оплата заказа [id]',
            'title' => 'Описание платежа (шаблон)',
        );
        
        $cfg[] = array(
            'key' => 'amount',
            'type' => 'input',
            'default' => '',
            'title' => 'Поле "Сумма"',
            'description' => 'ID поля, в котором содержится сумма к оплате',
            'params' => array(
                'class' => 'form-control input-small required',
                'type' => 'number',
            ) ,
        );

        $cfg[] = array(
            'key' => 'currency',
            'type' => 'dorpdown',
            'choices' => array('RUB' => 'RUB', 'USD' => 'USD', 'EUR' => 'EUR'),
            'default' => 'RUB',
            'title' => 'Валюта',
        );

        $cfg[] = array(
            'key' => 'email',
            'type' => 'input',
            'default' => '',
            'title' => 'Поле "E-mail"',
            'description' => 'ID поля, в котором содержится email для отправки чека',
            'params' => array(
                'class' => 'form-control input-small required',
                'type' => 'number',
            ) ,
        );
        
        $cfg[] = array(
            'key' => 'vat_code',
            'type' => 'dorpdown',
            'choices' => array(
                '1' => '1 — Без НДС',
                '2' => '2 — НДС по ставке 0%',
                '3' => '3 — НДС по ставке 10%',
                '4' => '4 — НДС по ставке 20%',
                '5' => '5 — НДС по расчетной ставке 10/110',
                '6' => '6 — НДС по расчетной ставке 20/120',
                '7' => '7 — НДС по ставке 5%',
                '8' => '8 — НДС по ставке 7%',
                '9' => '9 — НДС по расчетной ставке 5/105',
                '10' => '10 — НДС по расчетной ставке 7/107'
            ),
            'default' => '1',
            'title' => 'Код ставки НДС для передачи в ЮKassa (vat_code)',
            'description' => 'Код ставки НДС, передается как vat_code',
            'params' => array(
                'class' => 'form-control'
            ) ,
        );
        
        $cfg[] = array(
            'key' => 'payment_subject',
            'type' => 'dorpdown',
            'choices' => array(
                'commodity' => 'commodity — Товар',
                'job' => 'job — Работа',
                'service' => 'service — Услуга',
                'payment' => 'payment — Платеж',
                'casino' => 'casino — Платеж казино',
                'gambling_bet' => 'gambling_bet — Ставка в азартной игре',
                'gambling_prize' => 'gambling_prize — Выигрыш азартной игры',
                'lottery' => 'lottery — Лотерейный билет',
                'lottery_prize' => 'lottery_prize — Выигрыш в лотерею',
                'intellectual_activity' => 'intellectual_activity — Результаты интеллектуальной деятельности',
                'agent_commission' => 'agent_commission — Агентское вознаграждение',
                'property_right' => 'property_right — Имущественное право',
                'non_operating_gain' => 'non_operating_gain — Внереализационный доход',
                'insurance_premium' => 'insurance_premium — Страховой сбор',
                'sales_tax' => 'sales_tax — Торговый сбор',
                'resort_fee' => 'resort_fee — Курортный сбор',
                'marked' => 'marked — Товар, подлежащий маркировке (ТМ)',
                'non_marked' => 'non_marked — Товар, подлежащий маркировке (ТНМ)',
                'fine' => 'fine — Выплата',
                'tax' => 'tax — Страховые взносы',
                'lien' => 'lien — Залог',
                'cost' => 'cost — Расход',
                'agent_withdrawals' => 'agent_withdrawals — Выдача денежных средств',
                'pension_insurance_without_payouts' => 'pension_insurance_without_payouts — Взносы ОПС ИП',
                'pension_insurance_with_payouts' => 'pension_insurance_with_payouts — Взносы ОПС',
                'health_insurance_without_payouts' => 'health_insurance_without_payouts — Взносы ОМС ИП',
                'health_insurance_with_payouts' => 'health_insurance_with_payouts — Взносы ОМС',
                'health_insurance' => 'health_insurance — Взносы ОСС',
                'another' => 'another — Другое'
            ),
            'default' => 'commodity',
            'title' => 'Признак предмета расчета (payment_subject)',
            'description' => 'Передается в ЮKassa как параметр payment_subject',
            'params' => array(
                'class' => 'form-control'
            ) ,
        );        
        
        $cfg[] = array(
            'key' => 'payment_mode',
            'type' => 'dorpdown',
            'choices' => array(
                'full_prepayment' => 'full_prepayment — Полная предоплата',
                'full_payment' => 'full_payment — Полный расчет',
            ),
            'default' => 'full_payment',
            'title' => 'Признак способа расчета (payment_mode)',
            'description' => 'Передается в ЮKassa как параметр payment_mode',
            'params' => array(
                'class' => 'form-control'
            ) ,
        );


        $cfg[] = array(
            'key' => 'use_widget',
            'type' => 'dorpdown',
            'choices' => array('1' => 'Да (Embedded виджет)', '0' => 'Нет (Redirect)'),
            'default' => '1',
            'title' => 'Использовать виджет в модальном окне',
        );

        $cfg[] = array(
            'key' => 'locale',
            'type' => 'dorpdown',
            'choices' => array('ru_RU' => 'ru_RU', 'en_US' => 'en_US'),
            'default' => 'ru_RU',
            'title' => 'Локаль виджета',
        );

        $cfg[] = array(
            'key' => 'theme',
            'type' => 'dorpdown',
            'choices' => array('light' => 'Светлая', 'dark' => 'Тёмная'),
            'default' => 'light',
            'title' => 'Тема виджета',
        );

        $cfg[] = array(
            'key' => 'save_payment_method',
            'type' => 'dorpdown',
            'choices' => array('0' => 'Нет', '1' => 'Да'),
            'default' => '0',
            'title' => 'Разрешить сохранение способа оплаты (save_payment_method)',
        );

        return $cfg;
    }

    // Форма подтверждения и генерация ссылки на оплату
    public function confirmation($module_id, $process_id)
    {
        global $app_path, $current_item_id, $current_entity_id, $app_redirect_to;

        $html = '';
        $cfg = modules::get_configuration($this->configuration(), $module_id);

        // Получим данные записи
        $item_info_query = db_query(
            "select e.* " . fieldtype_formula::prepare_query_select($current_entity_id, '') .
            " from app_entity_" . (int)$current_entity_id . " e where e.id='" . (int)$current_item_id . "'"
        );

        if ($item_info = db_fetch_array($item_info_query)) {
            // E-mail плательщика
            $payer_email = '';
            if (!empty($cfg['email'])) {
                $payer_email = $item_info['field_' . $cfg['email']] ?? '';
            }

            // Сумма
            $amount_raw = $item_info['field_' . $cfg['amount']] ?? 0;
            $amount = number_format((float)$amount_raw, 2, '.', '');

            // Название
            $fieldtype_text_pattern = new fieldtype_text_pattern();
            $item_name = $fieldtype_text_pattern->output_singe_text($cfg['item_name'], $current_entity_id, $item_info);

            // Параметры магазина
            $order_id = (string)$current_item_id;
            $shop_id = trim($cfg['shop_id']);
            $secret_key = trim($cfg['secret_key']);
            $currency = $cfg['currency'] ?: 'RUB';
            $use_widget = (int)($cfg['use_widget'] ?? 1);
            $vat_code = (int)($cfg['vat_code'] ?? 1);
            $payment_subject = $cfg['payment_subject'] ?? 'commodity';
            $payment_mode = $cfg['payment_mode'] ?? 'full_payment';
            
            $success_url = url_for('items/info', 'path=' . $app_path);
            $fail_url = url_for('items/info', 'path=' . $app_path);

            $yookassa_api_url = "https://api.yookassa.ru/v3/payments";

            // Базовые данные платежа
            $payment_data = array(
                "amount" => array(
                    "value" => $amount,
                    "currency" => $currency
                ),
                "capture" => true,
                "description" => $item_name,
                "metadata" => array(
                    "module_id" => $module_id,
                    "process_id" => $process_id,
                    "entity_id" => $current_entity_id,
                    "item_id" => $current_item_id,
                ),
                "receipt" => array(
                    "customer" => array(
                        "email" => $payer_email,
                    ),
                    "items" => array(
                        array(
                            "description" => $item_name,
                            "quantity" => 1.0,
                            "amount" => array(
                                "value" => $amount,
                                "currency" => $currency,
                            ),
                            "vat_code" => $vat_code,
                            "payment_subject" => $payment_subject,
                            "payment_mode" => $payment_mode
                        )
                    )
                )
            );


            // Режим embedded или redirect
            if ($use_widget === 1) {
                $payment_data["confirmation"] = array("type" => "embedded");
                // По желанию включить сохранение способа оплаты
                if (!empty($cfg['save_payment_method']) && (int)$cfg['save_payment_method'] === 1) {
                    $payment_data["save_payment_method"] = true;
                }
            } else {
                $payment_data["confirmation"] = array(
                    "type" => "redirect",
                    "return_url" => $success_url
                );
            }

            // Вызов API
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $yookassa_api_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Idempotence-Key: ' . uniqid($order_id . '_'),
            ));
            curl_setopt($curl, CURLOPT_USERPWD, $shop_id . ':' . $secret_key);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payment_data, JSON_UNESCAPED_UNICODE));
            $response = curl_exec($curl);

            $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($curl);
            curl_close($curl);

            $data = json_decode($response, true);

            if ($http_status == 200 || $http_status == 201) {
                $amount_formatted = $amount . ' ' . $currency;

                if ($use_widget === 1) {
                    // Embedded: confirmation_token
                    if (empty($data['confirmation']['confirmation_token'])) {
                        $msg = htmlspecialchars($data['description'] ?? 'Не удалось получить confirmation_token');
                        $html .= '<div class="alert alert-danger">Ошибка: ' . $msg . '</div>';
                        return $html;
                    }

                    $payment_id = $data['id'];
                    $confirmation_token = $data['confirmation']['confirmation_token'];

                    $html .= '<div class="yookassa-area">';
                    $html .= '<p class="to-pay">К оплате: ' . $amount_formatted . '</p>';
                    $html .= '<div id="yookassa-checkout" style=""></div>';
                    $html .= '<div id="yookassa-errors" class="alert alert-danger" style="display:none;margin-top:10px"></div>';
                    $html .= '</div>';

                    // URL статуса для поллинга
                    $status_url = url_for('ext/modules/yookassa/status', 'payment_id=' . urlencode($payment_id) . '&module_id=' . (int)$module_id, true);
                    $redirect_url = url_for('items/info', 'path=' . $app_path, true);

                    // Подключение и запуск виджета
                    $locale = !empty($cfg['locale']) ? $cfg['locale'] : 'ru_RU';
                    $theme = !empty($cfg['theme']) ? $cfg['theme'] : 'light';

$html .= '<script>
(function(){
    // Проверяем, загружен ли скрипт ЮKassa
    function loadYooKassaWidgetScript(callback) {
        if (window.YooMoneyCheckoutWidget || window.YooKassaCheckoutWidget) {
            callback();
        } else if (!document.getElementById("yookassa-widget-script")) {
            var script = document.createElement("script");
            script.id = "yookassa-widget-script";
            script.src = "https://yookassa.ru/checkout-widget/v1/checkout-widget.js";
            script.onload = callback;
            document.head.appendChild(script);
        } else {
            // Если скрипт уже подгружается, ждём его
            document.getElementById("yookassa-widget-script").onload = callback;
        }
    }

    var token = ' . json_encode($confirmation_token) . ';
    var containerId = "yookassa-checkout";
    var statusUrl = ' . json_encode($status_url) . ';
    var redirectUrl = ' . json_encode($redirect_url) . ';
    var theme = ' . json_encode($theme) . ';
    var locale = ' . json_encode($locale) . ';

    function showError(msg){
        var el = document.getElementById("yookassa-errors");
        if(!el) return;
        el.style.display = "block";
        el.textContent = msg || "Ошибка оплаты";
    }

function makeWidget() {
    var modalCollection = document.getElementsByClassName("modal-body");
    if (modalCollection.length == 0) {
        showError("Контейнер .modal-body не найден");
        return;
    }
    var modal = modalCollection[0];

    var containerId = "yookassa-checkout";
    var oldContainer = document.getElementById(containerId);
    if (oldContainer) oldContainer.parentNode.removeChild(oldContainer);

    var container = document.createElement("div");
    container.id = containerId;
    modal.appendChild(container);

    var Checkout = window.YooMoneyCheckoutWidget || window.YooKassaCheckoutWidget;
    if(!Checkout){
        showError("Ошибка загрузки виджета ЮKassa");
        return null;
    }
    try{
        var widget = new Checkout({
            confirmation_token: token,
            return_url: redirectUrl,
            customization: {
                modal: false,
                theme: theme,
            },
            locale: locale,
            error_callback: function(error){
                var msg = (error && (error.message || error.code)) ? (error.message || error.code) : "Ошибка оплаты";
                showError(msg);
            }
        });
        widget.render(containerId);
        return widget;
    }catch(e){
        showError("Инициализация виджета: " + (e && e.message ? e.message : e));
        return null;
    }
}

    // Поллинг статуса платежа
    var tries = 0, maxTries = 60, delay = 2000;
    function poll(){
        tries++;
        if(tries > maxTries) return;
        fetch(statusUrl, { credentials: "same-origin" })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if(j && j.status === "succeeded"){
                window.location.href = redirectUrl;
            }else if(j && (j.status === "canceled")){
                showError("Платеж отменён");
            }else{
                setTimeout(poll, delay);
            }
        })
        .catch(function(){
            setTimeout(poll, delay);
        });
    }

    // Главная точка запуска
    loadYooKassaWidgetScript(function(){
        makeWidget();
        poll();
    });
})();
</script>';

                } else {
                    // Redirect: confirmation_url
                    $confirm_url = $data['confirmation']['confirmation_url'] ?? '';
                    if (!$confirm_url) {
                        $msg = htmlspecialchars($data['description'] ?? 'Не удалось получить confirmation_url');
                        $html .= '<div class="alert alert-danger">Ошибка: ' . $msg . '</div>';
                        return $html;
                    }
                    $html .= '<p class="to-pay">К оплате: ' . $amount_formatted . '</p>';
                    $html .= '<a href="' . htmlspecialchars($confirm_url) . '" class="btn btn-primary btn-pay">Оплатить через ЮKassa</a>';
                }
            } else {
                $desc = $data['description'] ?? $curl_err ?? 'Unknown error';
                $html .= '<div class="alert alert-danger">Ошибка создания платежа: ' . htmlspecialchars($desc) . '</div>';
            }
        }
        return $html;
    }

public function ipn($module_id)
{
    $cfg = modules::get_configuration($this->configuration(), $module_id);

    // Проверка IP-адреса источника — разрешённые адреса ЮKassa
    $allowed_ips = [
        '185.71.76.0/27', '185.71.77.0/27', '77.75.153.0/25',
        '77.75.156.11', '77.75.156.35', '77.75.154.128/25', '2a02:5180::/32'
    ];

    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!self::isYooKassaIpAllowed($remote_ip, $allowed_ips)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied by IP';
        exit;
    }

    $input = file_get_contents('php://input');
    $json = json_decode($input, true);

    if (!isset($json['event']) || !isset($json['object'])) {
        header('HTTP/1.1 400 Bad Request');
        echo 'No event/object';
        exit;
    }

    $event = $json['event'];
    $object = $json['object'];

    $metadata = $object['metadata'] ?? [];
    $current_entity_id = (int)($metadata['entity_id'] ?? 0);
    $current_item_id = (int)($metadata['item_id'] ?? 0);
    $process_id = (int)($metadata['process_id'] ?? 0);
    $amount = $object['amount']['value'] ?? '0.00';
    $currency = $object['amount']['currency'] ?? '';
    $transaction_id = $object['id'] ?? '';

    $event_map = [
        'payment.succeeded' => 'Успешный платеж',
        'payment.waiting_for_capture' => 'Ожидает подтверждения',
        'payment.canceled' => 'Платеж отменен или не удался',
        'refund.succeeded' => 'Успешный возврат средств',
    ];
    $event_message = $event_map[$event] ?? $event;

    $comment = '<b>ЮKassa: ' . $event_message . '</b><br />'
        . 'Сумма: ' . number_format((float)$amount, 2, '.', '') . ' ' . $currency . '<br />'
        . 'transaction_id: ' . htmlspecialchars($transaction_id) . '<br />'
        . 'Статус: ' . htmlspecialchars($event);

    $sql_data = array(
        'description' => $comment,
        'entities_id' => $current_entity_id,
        'items_id' => $current_item_id,
        'date_added' => time(),
        'created_by' => 0,
    );

    db_perform('app_comments', $sql_data);

    if ($event == 'payment.succeeded') {
        $processes = new processes($current_entity_id);
        $processes->items_id = $current_item_id;
        $processes->run(['id' => $process_id], false, true);
    }

    header("HTTP/1.1 200 OK");
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Проверка IP в CIDR или match
public static function isYooKassaIpAllowed($ip, $allowed)
{
    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        foreach($allowed as $subnet) {
            if (strpos($subnet, ':') !== false) continue; // IPv6, пропускаем
            if (self::ipInCidr($ip, $subnet)) return true;
            if ($ip === $subnet) return true; // точный
        }
        return false;
    }
    // IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        foreach($allowed as $subnet) {
            if (strpos($subnet, ':') === false) continue; // IPv4, пропускаем
            if (self::ipInCidr6($ip, $subnet)) return true;
        }
        return false;
    }
    return false;
}

// Простая проверка IPv4 в подсети CIDR
public static function ipInCidr($ip, $cidr)
{
    list($subnet, $mask) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask = ~( (1 << (32 - $mask)) - 1 );
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

// Проверка IPv6 в подсети CIDR
public static function ipInCidr6($ip, $cidr) {
    list($subnet, $mask) = explode('/', $cidr);
    $ip_bin = inet_pton($ip);
    $subnet_bin = inet_pton($subnet);
    $mask = intval($mask);
    $bytes = $mask >> 3;
    $bits = $mask & 7;
    if (strncmp($ip_bin, $subnet_bin, $bytes) !== 0) return false;
    if ($bits === 0) return true;
    $ip_byte = ord($ip_bin[$bytes]);
    $subnet_byte = ord($subnet_bin[$bytes]);
    $shift = 8 - $bits;
    return ($ip_byte >> $shift) === ($subnet_byte >> $shift);
}


}