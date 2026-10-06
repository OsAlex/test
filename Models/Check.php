<?php
 
namespace Models;
 
use Exception;
use PDO;
use PDOStatement;
use PHPMailer\PHPMailer\PHPMailer;
 
class Check extends Base
{
    public static string $db_name = 'veira-souz';
    static public string $table = 'order_checks';
    static public string $primary = 'oc_id';
 
    public  int $oc_id;
    public  ?int $oc_ord_id;
    public  ?int $oc_type;
    public  ?string $oc_uuid;
    public  ?int $oc_cassa;
    public  ?int $oc_total;
    public  ?string $oc_json;
    public  ?string $oc_fpd;
    public  ?string $oc_fdn;
    public  ?string $oc_frn;
    public  ?string $oc_shift;
    public  ?string $oc_indate;
    public  ?int $oc_or_id;
    public  ?int $oc_status;
    public  ?string $oc_error;
    public  ?string $oc_params;
 
    const STATUS_NEW = 0;
    const STATUS_SEND = 1;
    const STATUS_ERROR = 2;
 
    static public array $statuses = [
        self::STATUS_NEW   => 'Новый',
        self::STATUS_SEND  => 'Напечатан',
        self::STATUS_ERROR => 'Ошибка печати',
    ];
 
    const TYPE_AVANS                   = 1;
    const TYPE_PRIHOD                  = 2;
    const TYPE_REFUND_AVANS            = 3;
    const TYPE_REFUND_PRIHOD           = 4;
    const TYPE_CORECT_AVANS            = 5;
    const TYPE_CORECT_PRIHOD           = 6;
    const TYPE_CORECT_REFUND_AVANS     = 7;
    const TYPE_CORECT_REFUND_PRIHOD    = 8;
    const TYPE_PRIHOD_FOR_AVANS        = 9;
    const TYPE_REFUND_PRIHOD_FOR_AVANS = 10;
 
    static public array $titles = [
        self::TYPE_AVANS                   => 'Авансовый',
        self::TYPE_PRIHOD                  => 'Приходный',
        self::TYPE_REFUND_AVANS            => 'Отмена Аванса',
        self::TYPE_REFUND_PRIHOD           => 'Отмена Прихода',
        self::TYPE_CORECT_AVANS            => 'Коррекция Аванса',
        self::TYPE_CORECT_PRIHOD           => 'Коррекция Прихода',
        self::TYPE_CORECT_REFUND_AVANS     => 'Коррекция Отмены Аванса',
        self::TYPE_CORECT_REFUND_PRIHOD    => 'Коррекция Отмены Прихода',
        self::TYPE_PRIHOD_FOR_AVANS        => 'Приход за аванс',
        self::TYPE_REFUND_PRIHOD_FOR_AVANS => 'Отмена Прихода за аванс',
    ];
 
    private static array $fields = [
        'oc_id',
        'oc_ord_id',
        'oc_or_id',
        'oc_type',
        'oc_uuid',
        'oc_fpd',
        'oc_fdn',
        'oc_frn',
        'oc_shift',
        'oc_cassa',
        'oc_total',
        'oc_json',
        'oc_error',
        'oc_status',
        'oc_params',
    ];
 
    private static array $block_items = [4487, 4488];
 
    function __construct($id = false) {
        if ($id) {
            $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_id` = %s', $id));
            if ($query) {
                $data = $query->fetch(PDO::FETCH_ASSOC);
                if ($data) {
                    foreach (self::$fields as $field_name) {
                        $this->$field_name = $data[$field_name] ?? null;
                    }
                }
            }
        }
 
        return $this;
    }
 
    public static function new($uuid): bool|Check {
        $instance = false;
        $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s"', $uuid));
        if ($query) {
            $data = $query->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $instance = new self($data['oc_id']);
            }
        }
 
        return $instance;
    }
 
    /**
     * @param int $orderId
     *
     * @return Check|false
     */
    public static function getByOrderId(int $orderId): bool|Check {
        $instance = false;
        $data = self::query(sprintf('SELECT * FROM `order_checks` where `oc_ord_id` = %s ORDER BY `oc_id` DESC LIMIT 1',
            $orderId))->fetch();
        if (!empty($data)) {
            $instance = new self($data['oc_id']);
        }
 
        return $instance;
    }
 
    /**
     * @param int $orderId
     * @param int $type
     *
     * @return Check|false
     */
    public static function getFirstNotSendForOrder(int $orderId, int $type): bool|Check {
        $instance = false;
        $data = self::query(sprintf('SELECT * FROM `order_checks` where `oc_ord_id` = %s AND `oc_status` = %s AND `oc_type` = %s ORDER BY `oc_id` DESC LIMIT 1',
            $orderId, self::STATUS_NEW, $type))->fetch();
        if (!empty($data)) {
            $instance = new self($data['oc_id']);
        }
 
        return $instance;
    }
 
    /**
     * @param Order $order
     * @param Cassa $cassa
     * @param $orderRequest
     * @param int|null $type
     * @param string|null $date_cor
     *
     * @return bool|Check
     * @throws Exception
     */
    public static function createDebug(Order $order, Cassa $cassa, $orderRequest, ?int $type = null, ?string $date_cor = null): bool|Check {
        $uuid = self::getUuidPrefixNew($order, $type) . $order->co_ord_id . $order->co_link; // md5($order->co_fio) . $order->co_ord_id;
 
        if ( $type ) {
            $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s" and `oc_type` = "%s"', $uuid, $type));
        } else {
            $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s" ', $uuid));
        }
 
        $check = $query->fetch(PDO::FETCH_OBJ);
 
        if ( isset($check->oc_id) ) {
            return Check::fromStdClass($check);
        }
 
        $instance = new self;
        $instance->oc_ord_id = $order->co_ord_id;
        $instance->oc_or_id  = $orderRequest->or_id;
        $instance->oc_type   = $type ?? self::getType($order);
        $instance->oc_cassa  = $cassa->id;
        $instance->oc_total  = $order->co_amount;
 
        if ( in_array($type, [Check::TYPE_CORECT_AVANS, Check::TYPE_CORECT_PRIHOD, Check::TYPE_CORECT_REFUND_AVANS, Check::TYPE_CORECT_REFUND_PRIHOD])
            && !empty($date_cor)
        ) {
            $date_cor = self::convertToYmd($date_cor);
            if ($date_cor) {
                $instance->oc_params = json_encode(['correct_date' => $date_cor]);
            }
        }
 
        $instance->save();
 
        $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s"', $instance->oc_uuid));
        $db_check = $query->fetch(PDO::FETCH_OBJ);
 
        if ( !isset($db_check->oc_id) ) {
            throw new Exception("Error Create Check: Not save check to BD", 1);
        }
 
        return Check::fromStdClass($db_check);
    }
 
    private static function convertToYmd($dateString): false|string {
        if (!is_string($dateString) || trim($dateString) === '') {
            return false;
        }
 
        // Если уже в правильном формате
        if (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $dateString)) {
            return $dateString;
        }
 
        // Пробуем распознать
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return false;
        }
 
        return date('Y.m.d', $timestamp);
    }
 
    /**
     * @throws Exception
     */
    public static function create(Order $order, Cassa $cassa, $orderRequest, ?int $type = null) {
        $uuid = self::getUuidPrefix($order, $type) . $order->co_ord_id . $order->co_link; // md5($order->co_fio) . $order->co_ord_id;
 
        $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s"', $uuid));
        $check = $query->fetch(PDO::FETCH_OBJ);
 
        if ( isset($check->oc_id) ) {
            return $check;
        }
 
        $instance = new self;
        $instance->oc_ord_id = $order->co_ord_id;
        $instance->oc_or_id  = $orderRequest->or_id;
        $instance->oc_type   = $type ?? self::getType($order);
        $instance->oc_uuid   = $uuid;
        $instance->oc_cassa  = $cassa->id;
        $instance->oc_total  = $order->co_amount;
        $instance->save();
 
        $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s"', $instance->oc_uuid));
        $check = $query->fetch(PDO::FETCH_OBJ);
 
        if ( !isset($check->oc_id) ) {
            throw new Exception("Error Create Check: Not save check to BD", 1);
        }
 
        $instance->oc_id = $check->oc_id;
 
        return $instance;
    }
 
    public static function getUuidPrefixNew(Order $order, $type): string {
        $query = self::query(sprintf('SELECT count(*) as `count`, `oc_type` FROM `order_checks` 
                WHERE `oc_ord_id` = %d AND `oc_status` in (1, 2) 
                GROUP BY `oc_type`', $order->co_ord_id));
 
        $counts = [];
        $counts[Check::TYPE_AVANS]                   = 0;
        $counts[Check::TYPE_PRIHOD]                  = 0;
        $counts[Check::TYPE_REFUND_AVANS]            = 0;
        $counts[Check::TYPE_REFUND_PRIHOD]           = 0;
        $counts[Check::TYPE_CORECT_AVANS]            = 0;
        $counts[Check::TYPE_CORECT_PRIHOD]           = 0;
        $counts[Check::TYPE_CORECT_REFUND_AVANS]     = 0;
        $counts[Check::TYPE_CORECT_REFUND_PRIHOD]    = 0;
        $counts[Check::TYPE_PRIHOD_FOR_AVANS]        = 0;
        $counts[Check::TYPE_REFUND_PRIHOD_FOR_AVANS] = 0;
 
        while ( $check = $query->fetch(PDO::FETCH_OBJ) ) {
            $counts[$check->oc_type] = $check->count;
        }
 
        return match ($type) {
            default                            => str_repeat('S', $counts[Check::TYPE_AVANS]),
            self::TYPE_PRIHOD                  => str_repeat('F', $counts[Check::TYPE_PRIHOD] + 1),
            self::TYPE_REFUND_AVANS            => str_repeat('B', $counts[Check::TYPE_REFUND_AVANS] + 1),
            self::TYPE_REFUND_PRIHOD           => str_repeat('R', $counts[Check::TYPE_REFUND_PRIHOD] + 1),
            self::TYPE_CORECT_AVANS            => str_repeat('C', $counts[Check::TYPE_CORECT_AVANS] + 1),
            self::TYPE_CORECT_PRIHOD           => str_repeat('P', $counts[Check::TYPE_CORECT_PRIHOD] + 1),
            self::TYPE_CORECT_REFUND_AVANS     => str_repeat('V', $counts[Check::TYPE_CORECT_REFUND_AVANS] + 1),
            self::TYPE_CORECT_REFUND_PRIHOD    => str_repeat('I', $counts[Check::TYPE_CORECT_REFUND_PRIHOD] + 1),
            self::TYPE_PRIHOD_FOR_AVANS        => str_repeat('J', $counts[Check::TYPE_PRIHOD_FOR_AVANS] + 1),
            self::TYPE_REFUND_PRIHOD_FOR_AVANS => str_repeat('K', $counts[Check::TYPE_REFUND_PRIHOD_FOR_AVANS] + 1),
        };
    }
 
    private static function getUuidPrefix(Order $order, $type): string {
        $count_avans = $count_prihod = $count_refund_avans = $count_refund_prihod = 0;
        $count_corect_avans = $count_corect_prihod = $count_corect_refund_avans = $count_corect_refund_prihod = 0;
 
        $query = self::query(sprintf('SELECT * FROM `order_checks` WHERE `oc_ord_id` = %s AND `oc_status` in (%d, %d) ',
            $order->co_ord_id, Check::STATUS_SEND, Check::STATUS_ERROR));
 
        while ( $check = $query->fetch(PDO::FETCH_OBJ) ) {
            $count_avans  += (int)$check->oc_type == Check::TYPE_AVANS;
            $count_prihod += (int)$check->oc_type == Check::TYPE_PRIHOD;
 
            $count_refund_avans  += (int)$check->oc_type == Check::TYPE_REFUND_AVANS;
            $count_refund_prihod += (int)$check->oc_type == Check::TYPE_REFUND_PRIHOD;
 
            $count_corect_avans  += (int)$check->oc_type == Check::TYPE_CORECT_AVANS;
            $count_corect_prihod += (int)$check->oc_type == Check::TYPE_CORECT_PRIHOD;
 
            $count_corect_refund_avans  += (int)$check->oc_type == Check::TYPE_CORECT_REFUND_AVANS;
            $count_corect_refund_prihod += (int)$check->oc_type == Check::TYPE_CORECT_REFUND_PRIHOD;
        }
 
        return match ($type) {
            default           => str_repeat('S', $count_avans),
            self::TYPE_PRIHOD => str_repeat('F', $count_prihod + 1),
 
            self::TYPE_REFUND_AVANS  => str_repeat('B', $count_refund_avans + 1),
            self::TYPE_REFUND_PRIHOD => str_repeat('R', $count_refund_prihod + 1),
 
            self::TYPE_CORECT_AVANS  => str_repeat('C', $count_corect_avans + 1),
            self::TYPE_CORECT_PRIHOD => str_repeat('P', $count_corect_prihod + 1),
 
            self::TYPE_CORECT_REFUND_AVANS  => str_repeat('V', $count_corect_refund_avans + 1),
            self::TYPE_CORECT_REFUND_PRIHOD => str_repeat('I', $count_corect_refund_prihod + 1),
        };
    }
 
    /**
     * @param Order $order
     *
     * @return int
     * @throws Exception
     */
    public static function getType(Order $order): int {
        if ( $order->co_type == 8 AND $order->hasProduct(4410) ) {
            return self::TYPE_PRIHOD;
        }
 
        return match ($order->co_type) {
            Order::CO_TYPE_VS_AVANS, Order::CO_TYPE_V_AVANS, Order::CO_TYPE_BILET, Order::CO_TYPE_BILET_V => self::TYPE_AVANS,
            Order::CO_TYPE_ASD, Order::CO_TYPE_VS, Order::CO_TYPE_V, Order::CO_TYPE_VS_DOST, Order::CO_TYPE_V_CASHBACK, Order::CO_TYPE_VS_CASHBACK, Order::CO_TYPE_SERVICE, Order::CO_TYPE_BILET_SERVICE => self::TYPE_PRIHOD,
            default => throw new Exception('Not found check type (avans|prihod) for order : ' . $order->co_ord_id),
        };
    }
 
    /**
     * @param $or_ord_id
     *
     * @return array
     */
    public static function forOrder($or_ord_id): array {
        $query = self::query(sprintf('SELECT * FROM `order_checks` WHERE `oc_ord_id` = %s', $or_ord_id));
 
        $result = [];
        if ($query) {
            while ($data = $query->fetch(PDO::FETCH_OBJ)) {
                $result[] = Check::fromStdClass($data);
            }
        }
 
        return $result;
    }
 
    public static function getAvansForShift(int $shift_id, int $cassa_id): bool|array {
        return self::query(sprintf('SELECT * FROM `order_checks`
                WHERE `oc_shift` = %s AND `oc_cassa` = %s AND `oc_type` = %s AND `oc_status` = %s',
            $shift_id, $cassa_id, Check::TYPE_AVANS, Check::STATUS_SEND))
            ->fetchAll(PDO::FETCH_OBJ);
    }
 
    private function getTaxationType(OrderPayRequest $orderRequest): string {
        $params = isset($this->oc_params) ? json_decode($this->oc_params, true) : [];
        if ( !empty($params['correct_date']) && date('Y-m-d H:i:s', strtotime(str_replace('.', '-', $params['correct_date']))) < '2024-07-01 00:00:00' ) {
            return 'usnIncome';
        }
 
        if ( date('Y-m-d H:i:s') >= '2024-07-01 00:00:00' ) {
            return 'osn';
        }
 
        return ($orderRequest->or_company == 1 ? 'usnIncome' : 'usnIncomeOutcome');
    }
 
    private function getItemTax($nds): string {
        return match ($nds) {
            0 => 'vat22',
            10 => 'vat10',
            20 => 'vat20',
            22 => 'vat22',
            default => 'vat22',
        };
    }
 
    /**
     * @param Order $order
     *
     * @return array
     */
    private function getTaxes(Order $order): array {
        $taxes = [];
        foreach ($order->items as $item) {
            //$taxes[$item['cod_nds']]['summa'] = $taxes[$item['cod_nds']]['summa'] + floatval($item['cod_price'] * $item['cod_quant']);
            $taxes[22]['summa'] = $taxes[22]['summa'] + floatval($item['cod_price'] * $item['cod_quant']);
        }
 
        if ($order->co_del_pay > 0) {
            $taxes[22]['summa'] = $taxes[22]['summa'] + $order->co_del_pay;
        }
 
        return $taxes;
    }
 
    /**
     * @throws Exception
     */
    private function getTypeText(Order $order): string {
        return match ($this->oc_type) {
            self::TYPE_AVANS, self::TYPE_PRIHOD, self::TYPE_PRIHOD_FOR_AVANS => 'sell',
            self::TYPE_REFUND_AVANS, self::TYPE_REFUND_PRIHOD, self::TYPE_REFUND_PRIHOD_FOR_AVANS => 'sellReturn',
            self::TYPE_CORECT_AVANS, self::TYPE_CORECT_PRIHOD => 'sellCorrection',
            self::TYPE_CORECT_REFUND_AVANS, self::TYPE_CORECT_REFUND_PRIHOD => 'sellReturnCorrection',
            default => throw new Exception('Not found type text (sell|sellReturn) for order : ' . $order->co_ord_id),
        };
    }
 
    /**
     * @throws Exception
     */
    private function getPaymentType(Order $order): bool|string {
        if ( in_array($this->oc_type, [self::TYPE_PRIHOD_FOR_AVANS, self::TYPE_REFUND_PRIHOD_FOR_AVANS]) ) {
            return 'prepaid';
        }
 
        // Так как теперь проводим только аванс-приход, все приходы это закрытие аванса
        if ( in_array($this->oc_type, [
                self::TYPE_PRIHOD_FOR_AVANS,
                self::TYPE_REFUND_PRIHOD_FOR_AVANS,
                self::TYPE_PRIHOD,
                self::TYPE_CORECT_PRIHOD,
                self::TYPE_REFUND_PRIHOD,
                self::TYPE_CORECT_REFUND_PRIHOD, //было закоментировано
            ])
        ) {
            return 'prepaid';
        }
 
        try {
            if ( isset($this->oc_params) ) {
                $oc_params = json_decode($this->oc_params, true);
                if ( !empty($oc_params['source_avans']) || !empty($oc_params['source_fpd']) ) {
                    return 'prepaid';
                }
            }
        } catch (Exception $e) {
            throw new Exception('Not found payment type text for order : ' . $order->co_ord_id .' : '.$e->getMessage());
        }
 
        return match ($order->co_pay_type) {
            Order::PAY_TYPE_RES_CARD, Order::PAY_TYPE_INET, Order::PAY_TYPE_INET_2 => 'electronically',
            Order::PAY_TYPE_RES_NAL => 'cash',
            default => throw new Exception('Not found payment type text (electronically|cash) for order : ' . $order->co_ord_id),
        };
    }
 
    public function getCheckTitle(Order $order): string {
        if (in_array($order->co_type, array(Order::CO_TYPE_BILET, Order::CO_TYPE_BILET_V))) {
            $bilet = reset($order->items);
            $result = sprintf('%s (заказ № %s)',
                isset($bilet['cod_prod_name']) ? str_replace(['«', '»'], '', $bilet['cod_prod_name']) : 'Билет',
                $order->co_ord_id
            );
        } elseif ($order->co_type == Order::CO_TYPE_ASD) {
            $result = sprintf('Оплата заказа ПК Вейра-Союз %s', $order->co_ord_id);
        } else {
            // $result = ($orderRequest->or_company == OrderPayRequest::COMPANY_VS
            //         ? 'Продукция ПК Вейра-Союз заказ '
            //         : 'Продукция ООО Вейра заказ ') . $order->co_ord_id;
            $result = sprintf('заказ %s', $order->co_ord_id);
        }
 
        return $result;
    }
 
 
    private function getItemsSum($order): array {
        $items = [];
 
        $taxes = $this->getTaxes($order);
 
        foreach ($taxes as $nds => $data) {
            $item_data = [
                'type'          => 'position',
                'name'          => $this->getCheckTitle($order),
                'price'         => $data['summa'],
                'quantity'      => 1,
                'amount'        => $data['summa'],
                'paymentMethod' => 'advance',
                'paymentObject' => in_array($order->co_type,
                    [Order::CO_TYPE_BILET, Order::CO_TYPE_BILET_V]) ? 'service' : 'commodity',
                'tax'           => (object)['type' => $this->getItemTax($nds)],
            ];
 
            if (in_array($this->oc_cassa, [Cassa::CASSA_VS_I, Cassa::CASSA_VS_II, Cassa::CASSA_VS_III, Cassa::CASSA_VS_TEST])) {
                $item_data['measurementUnit'] = (Cassa::$cassa_ffd[$this->oc_cassa] == '1.2' ? '0' : 'шт.');
            }
 
            $items[] = $item_data;
        }
 
        return $items;
    }
 
    private function getItemsList($order, $typeCheck): array {
        $items = [];
        foreach ($order->items as $item) {
            $item_data = [
                'type'            => 'position',
                'name'            => str_replace(['«', '»', '"', "'", '/', '\\', '`'], '', $item['cod_prod_name']),
                'price'           => floatval($item['cod_price']),
                'quantity'        => $item['cod_quant'],
                'amount'          => floatval($item['cod_price'] * $item['cod_quant']),
                'paymentMethod'   => $typeCheck,
                'paymentObject'   => $item['cod_prod_id'] == 2319 || $order->co_type == Order::CO_TYPE_BILET ? 'service' : 'commodity',
                //'tax'             => (object)['type' => $this->getItemTax($item['cod_nds'])],
                'tax'             => (object)['type' => $this->getItemTax(22)],
            ];
 
            if ( in_array($this->oc_cassa, [Cassa::CASSA_VS_I, Cassa::CASSA_VS_II, Cassa::CASSA_VS_III, Cassa::CASSA_VS_TEST]) ) {
                $item_data['measurementUnit'] = (Cassa::$cassa_ffd[$this->oc_cassa] == '1.2' ? '0' : 'шт.');
            }
 
            $items[] = (object)$item_data;
        }
 
        if ($order->co_del_pay > 0) {
            $item_data = [
                'type'            => 'position',
                'name'            => 'Доставка',
                'price'           => $order->co_del_pay,
                'quantity'        = 1,
                'amount'          = $order->co_del_pay,
                'paymentMethod'   = $typeCheck,
                'paymentObject'   = 'service',
                'tax'             = (object)['type' => $this->getItemTax(22)],
            ];
 
            if ( in_array($this->oc_cassa, [Cassa::CASSA_VS_I, Cassa::CASSA_VS_II, Cassa::CASSA_VS_III, Cassa::CASSA_VS_TEST]) ) {
                $item_data['measurementUnit'] = (Cassa::$cassa_ffd[$this->oc_cassa] == '1.2' ? '0' : 'шт.');
            }
 
            $items[] = (object)$item_data;
        }
 
        return $items;
    }
 
    /**
     * @param Order $order
     *
     * @return bool|array
     */
    private function getItems(Order $order): bool|array {
        $items = false;
 
        if ( in_array($this->oc_type, [self::TYPE_AVANS, self::TYPE_REFUND_AVANS, self::TYPE_CORECT_AVANS, self::TYPE_CORECT_REFUND_AVANS])) {
            $items = $this->getItemsSum($order);
        //            $items = $this->getItemsList($order, 'advance');
        }
 
        if ( in_array($this->oc_type, [self::TYPE_PRIHOD, self::TYPE_REFUND_PRIHOD,
               self::TYPE_CORECT_PRIHOD, self::TYPE_CORECT_REFUND_PRIHOD, self::TYPE_PRIHOD_FOR_AVANS, self::TYPE_REFUND_PRIHOD_FOR_AVANS])
        ) {
            $items = $this->getItemsList($order, 'fullPayment');
        }
 
        if ( isset($this->oc_params) && $this->oc_params != '{}' ) {
            $params = json_decode($this->oc_params, true);
 
            if ( isset($params['1192']) ) {
                $item_data = [];
                $item_data['type']  = 'additionalAttribute';
                $item_data['value'] = $params['1192'];
 
                $items[] = (object)$item_data;
            }
        }
 
        return $items;
    }
 
    /**
     * SPEC.md §1.8: добавить атрибуты маркировки ЧЗ в позиции чека.
     *
     * Matching — по `item_id` (товару из заказа), а не по itemGuid;
     * multiple КМ на один item — список. При BLOCK (errorCode 10) — чек отклоняется (Exception).
     *
     * INSERT_ATOL_OFFICIAL_EXAMPLE: официальный JSON-формат тега 1260 от Атола
     * ещё не получен (docs/sprints/01/Запрос_заказчику.md). До получения — рабочее
     * приближение по SPEC §1.5: industryInfo на уровне позиции, тег 1260 =
     * additionalAttribute, 1262 = "030" (код ФНС), 1263 = ДД.ММ.ГГГГ (приказ ФНС
     * ЕД-7-20/662@), 1264 = номер норм-акта из config marking.tag1260 (by_group,
     * fallback — tag1260.default).
     *
     * @param array $items позиции чека (массивы или объекты, как из getItems())
     * @param \Service\Marking\MarkingCheckResult $result результат проверки этих КМ
     * @return array
     * @throws Exception если хотя бы один КМ под запретом
     */
    public function addMarkingAttributes(array $items, \Service\Marking\MarkingCheckResult $result): array
    {
        foreach ($result->blockedCodes() as $blockedCode) {
            throw new Exception('MARKING BLOCK: код ' . \Service\Marking\MarkingLogger::maskCode($blockedCode)
                . ' под запретом (errorCode 10). Чек заказа ' . $this->oc_ord_id . ' отклонён.');
        }
 
        $resultByCode = $result->codes;
 
        foreach ($items as $index => $item) {
            $data = is_object($item) ? get_object_vars($item) : (array) $item;
            $marking = $data['marking'] ?? null;
            if (empty($marking['cis'])) {
                continue; // позиция без маркировки
            }
 
            $attrs = [];
            foreach ((array) $marking['cis'] as $cis) {
                $attrs[] = $this->buildMarkingAttribute((string) $cis, $resultByCode[$cis] ?? []);
            }
 
            $data['industryInfo'] = array_merge($data['industryInfo'] ?? [], $attrs);
            $items[$index] = is_object($item) ? (object) $data : $data;
        }
 
        return $items;
    }
 
    /**
     * COMMENTS-9 Fix #8 + COMMENTS-10 2.3 + COMMENTS-11 #4: гейт ATOL placeholder (усиленный).
     *  - COMMENTS-11 #4: APP_ENV обязан быть ОДНИМ из dev|test|prod|production (lowercase);
     *    любое другое значение (включая НЕНАЗНАЧЕННЫЙ) → RuntimeException — неявный
     *    dev-fallback и «тихий» staging запрещены;
     *  - APP_ENV=prod + tag1260_placeholder=1 → RuntimeException (чек НЕ уходит на ККТ
     *    с несертифицированной структурой industryInfo);
     *  - APP_ENV=prod + placeholder=0 → обязателен файл официального JSON (tag1260_example_path).
     * @throws \RuntimeException
     */
    private function assertAtolExampleAvailable(): void
    {
        // 2.3/#4: отсутствие APP_ENV ≠ 'dev'. getenv/$_ENV — как в rest-фреймворке репо.
        $envRaw = getenv('APP_ENV');
        $envRaw = is_string($envRaw) && $envRaw !== '' ? $envRaw : (is_string($_ENV['APP_ENV'] ?? null) ? $_ENV['APP_ENV'] : null);
        $env = $envRaw !== null && trim($envRaw) !== '' ? strtolower(trim($envRaw)) : null;
 
        $isMock = (bool) \Service\Marking\MarkingConfig::get('atol.tag1260_placeholder', true);
 
        // COMMENTS-11 #4: whitelist — весь «прочий» env (staging, dev_2, пустое…) → throw.
        if (!in_array($env, ['dev', 'test', 'prod', 'production'], true)) {
            throw new \RuntimeException(
                'APP_ENV must be one of: dev, test, prod, production (got: '
                . ($envRaw === null ? '<unset>' : $envRaw)
                . '). Check with marking is NOT sent until the environment is declared explicitly.'
            );
        }
 
        $isProd = in_array($env, ['prod', 'production'], true);
        if (!$isProd) {
            return;
        }
 
        if ($isMock) {
            throw new \RuntimeException(
                'ATOL tag1260 placeholder is enabled in production. '
                . 'Set MARKING_ATOL_PLACEHOLDER=0 and MARKING_ATOL_EXAMPLE_PATH '
                . 'after obtaining the official JSON from ATOL (ДККТ 10.10.8.24).'
            );
        }
        $path = (string) \Service\Marking\MarkingConfig::get('atol.tag1260_example_path', '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException(
                'ATOL official JSON is required in production but not found at '
                . "MARKING_ATOL_EXAMPLE_PATH={$path}"
            );
        }
    }
 
    /**
     * Атрибут маркировки позиции (теги 1260/1262/1263/1264).
     * 1264 по товарной группе из /codes/check (group_id); группа не найдена — fallback tag1260.default.
     *
     * @param array $info пер-код данные проверки (group_id, ...)
     */
    private function buildMarkingAttribute(string $cis, array $info): array
    {
        $this->assertAtolExampleAvailable();
 
        $tag1260 = \Service\Marking\MarkingConfig::get('tag1260', []);
        $default = $tag1260['default'] ?? [];
        $group = $tag1260['by_group'][$info['group_id'] ?? null] ?? [];
 
        // INSERT_ATOL_OFFICIAL_EXAMPLE: заменить на официальный JSON Атола после получения.
        return [
            'type' => 'additionalAttribute',
            'id' => 1260,
            'value' => [
                '1260' => $cis,
                '1262' => $group['1262'] ?? $default['1262'] ?? '030',
                '1263' => $group['1263'] ?? $default['1263'] ?? '',
                '1264' => $group['1264'] ?? $default['1264'] ?? '',
            ],
        ];
    }
 
    /**
     * MARKING: интеграция в make_json (roadmap шаг 5).
     * Только приходные/возвратные чеки (позиционные товары); авансовые (сводные
     * позиции) в MVP не маркируются — ограничение задокументировано.
     *
     * @param Order $order
     * @param array $items позиции (из getItems/getItemsList)
     * @throws Exception
     */
    private function applyMarkingAttributes(Order $order, array &$items): void
    {
        if (!\Service\Marking\MarkingConfig::get('enable', false)) {
            return;
        }
 
        $marking = $this->collectMarkingByItem($order);
        if ($marking === []) {
            return; // в заказе нет маркированных товаров
        }
 
        // COMMENTS-9 Fix #13: авансовый чек (сводные позиции getItemsSum) — НЕ исключение,
        // а SKIPPED: история со skip_reason='advance_check_no_goods' + метрика, чек уходит дальше.
        if (in_array($this->oc_type, [
            self::TYPE_AVANS, self::TYPE_REFUND_AVANS,
            self::TYPE_CORECT_AVANS, self::TYPE_CORECT_REFUND_AVANS,
        ], true)) {
            $service = new \Service\Marking\MarkingCheckService();
            $service->skipAdvance((int) $order->co_ord_id, (int) $this->oc_id, $this->oc_uuid);
            \Service\Marking\MarkingLogger::warn('marking_skipped_advance_check', [
                'order_id' => $order->co_ord_id,
                'check_id' => $this->oc_id,
            ]);
            return;
        }
 
        $cisList = [];
        $receiptMeta = []; // КИ => 'GROUP'|'ITEM' (SPEC §3.5: правило агрегат+единицы без онлайн → BLOCK)
        foreach ($marking as $info) {
            $cisList = array_merge($cisList, $info['cis']);
            foreach ($info['cis'] as $cis) {
                $receiptMeta[$cis] = $info['package_type'] ?? 'ITEM';
            }
        }
 
        $service = new \Service\Marking\MarkingCheckService();
        $result = $service->check($cisList, $this->oc_uuid, (int) $order->co_ord_id, (int) $this->oc_id, $receiptMeta);
 
        // getItemsList() итерит $order->items в том же порядке: индекс позиции = индекс товара.
        foreach ($items as $index => &$item) {
            $product = $order->items[$index] ?? null;
            if ($product === null || !isset($marking[$product['cod_prod_id'] ?? null])) {
                continue;
            }
            $data = is_object($item) ? get_object_vars($item) : (array) $item;
            $data['marking'] = [
                'item_id' => (string) ($product['cod_prod_id'] ?? ''),
                'cis'     => $marking[$product['cod_prod_id']]['cis'],
            ];
            $item = is_object($item) ? (object) $data : $data;
        }
        unset($item);
 
        $items = $this->addMarkingAttributes($items, $result);
    }
 
    /**
     * Маркированные позиции заказа: cod_prod_id => ['cis' => [КИ...], 'package_type' => 'GROUP'|'ITEM'].
     *
     * SPEC §2.4 (COMMENTS-9 Fix #12): поля таблички `cassa_ord_det` (источник — Order::items):
     *  - cod_marking_cis          — КИ через ';' (≤2000 байт, ≤50 кодов, без дублей);
     *  - cod_marking_package_type — ITEM|UNIT (единица) / GROUP|BUNDLE|PRODUCT_SET (агрегат);
     *  - is_marked                — признак «товар подлежит маркировке» (из 1С).
     *
     * Семантика:
     *  - товар НЕ подлежит маркировке (нет is_marked и нет package_type) → позиция игнорируется;
     *  - товар подлежит маркировке (is_marked=1 ИЛИ package_type задан), но КИ пустые
     *    → MarkingBlockedException (Блок): «товар требует маркировки, но КМ не отсканирован»
     *    — чек отклоняется ДО проверки кодов (SPEC §2.4, COMMENTS-9 Fix #12);
     *  - КИ есть → валидация MarkingItemValidator::parseCis (дубли/лимиты) → проверка кодов.
     *
     * @throws \Service\Marking\MarkingBlockedException|InvalidArgumentException
     */
    private function collectMarkingByItem(Order $order): array
    {
        $marking = [];
        foreach ($order->items as $item) {
            $prodId = $item['cod_prod_id'] ?? null;
            if ($prodId === null) {
                continue;
            }
 
            $packageTypeRaw = strtoupper(trim((string) ($item['cod_marking_package_type'] ?? '')));
            $isMarked = (bool) ($item['is_marked'] ?? false);
            $isSubjectToMarking = $isMarked || $packageTypeRaw !== '';
 
            $codes = \Service\Marking\MarkingItemValidator::parseCis((string) ($item['cod_marking_cis'] ?? ''));
 
            if ($isSubjectToMarking && $codes === []) {
                throw new \Service\Marking\MarkingBlockedException(
                    'Товар подлежит маркировке (is_marked / package_type), но КМ не отсканирован: позиция '
                    . $prodId . ', заказ ' . $order->co_ord_id . '. Отсканируйте код маркировки.'
                );
            }
            if ($codes === []) {
                continue; // товар не подлежит маркировке — позиция не трогается
            }
 
            $marking[$prodId] = [
                'cis'          => $codes,
                'package_type' => \Service\Marking\MarkingItemValidator::normalizePackageType($packageTypeRaw),
            ];
        }
 
        return $marking;
    }
 
    /**
     * @throws Exception
     */
    public function make_json($date_correction = null): bool {
        $orderRequest = $this->getOrderRequest();
        $order = $this->getOrder();
 
        try {
            $operator = Operator::$operators[$orderRequest->or_cassir_id];
        } catch (Exception $e) {
            $logger = new Logger();
            $logger->log('Error get operator for order : ' . $order->co_ord_id . ' : ' . $e->getMessage());
            OrderPayRequest::setStatus($orderRequest->or_ord_id, OrderPayRequest::ERROR_GET_OPERATOR);
            return false;
        }
 
        $paymentType  = $this->getPaymentType($order);
        $data         = [];
        $data['uuid'] = $this->oc_uuid;
 
        $info                   = [];
        $info['type']           = $this->getTypeText($order);
        $info['taxationType']   = $this->getTaxationType($orderRequest); //($orderRequest->or_company == 1 ? 'usnIncome' : 'usnIncomeOutcome');
 
 
        if ( in_array($this->oc_type, [ self::TYPE_PRIHOD_FOR_AVANS, self::TYPE_REFUND_PRIHOD_FOR_AVANS ] ) ) {
            $params = isset($this->oc_params) ? json_decode($this->oc_params, true) : [];
            if ( !empty($params['correct_date']) ) {
                $info['type']               = $this->oc_type == self::TYPE_PRIHOD_FOR_AVANS ? 'sellCorrection' : 'sellReturnCorrection';
                $info['correctionType']     = 'self';
                $info['correctionBaseDate'] = $params['correct_date'];
            }
        }
 
        if (in_array($this->oc_type, [ self::TYPE_CORECT_AVANS, self::TYPE_CORECT_PRIHOD,
            self::TYPE_CORECT_REFUND_AVANS, self::TYPE_CORECT_REFUND_PRIHOD])
        ) {
            $type_mapping = [
                self::TYPE_CORECT_AVANS         => self::TYPE_AVANS,
                self::TYPE_CORECT_PRIHOD        => self::TYPE_PRIHOD,
                self::TYPE_CORECT_REFUND_AVANS  => self::TYPE_AVANS,
                self::TYPE_CORECT_REFUND_PRIHOD => self::TYPE_PRIHOD];
 
            try {
                $query = self::query(sprintf('SELECT * FROM `order_checks` 
                    WHERE `oc_ord_id` = %d AND `oc_type` = %d
                    ORDER BY `oc_id` DESC
                    LIMIT 1', $this->oc_ord_id, $type_mapping[$this->oc_type]));
                $check_for_correct = $query->fetch(PDO::FETCH_OBJ);
            } catch (Exception $e) {
                $logger = new Logger();
                $logger->log('Error get check for create correct check : ' . $this->oc_ord_id . ' : ' . $e->getMessage());
                OrderPayRequest::setStatus($orderRequest->or_ord_id, OrderPayRequest::ERROR_MAKE_CHECK);
                return false;
            }
 
            $info['correctionType']       = 'self';
 
            $params = isset($this->oc_params) ? json_decode($this->oc_params, true) : [];
            if ( !empty($params['correct_date']) ) {
                $info['correctionBaseDate'] = $params['correct_date'];
            } elseif ( $date_correction != null ) {
                $info['correctionBaseDate'] = $date_correction;
            } elseif ( isset($check_for_correct->oc_indate) ) {
                $info['correctionBaseDate'] = date('Y.m.d', strtotime($check_for_correct->oc_indate));
            }
 
            // $info['correctionBaseNumber'] = ; // Номер предписания налогового органа
        }
 
        $info['electronically'] = true; // $paymentType == 'electronically'; false - печатать бумажный чек
        $info['internet']       = true;
        $info['operator']       = (object)[
            'name'  => $operator['fio'],
            //            'vatin' => $operator['inn'],  // api/v2 не работает
        ];
        $info['clientInfo'] = (object)[
            'emailOrPhone' => $this->getEmailCheck($order),
        ];
 
        if ( $order->co_type == Order::CO_TYPE_BILET_SERVICE ) {
            $tax = $this->getItemTax(22);
 
            $items   = [];
            $item_data = [
                'type'          => 'position',
                'name'          => 'предоставление доступа к информационно-консультационным материалам Online-семинара «Лидерство-цена доверия и приверженности»',
                'price'         => 600,
                'quantity'      = 1,
                'amount'        = 600,
                'paymentMethod' = 'fullPayment',
                'paymentObject' = 'service',
                'tax'           = (object)['type' => $tax],
            ];
 
            if ( in_array($this->oc_cassa, [Cassa::CASSA_VS_I, Cassa::CASSA_VS_II, Cassa::CASSA_VS_III, Cassa::CASSA_VS_TEST]) ) {
                $item_data['measurementUnit'] = (Cassa::$cassa_ffd[$this->oc_cassa] == '1.2' ? '0' : 'шт.');
            }
 
            $items[] = (object)$item_data;
        } else {
            $items = $this->getItems($order);
        }
 
        // MARKING (SPEC §1.8): атрибуты ЧЗ-маркировки (теги 1260/1262/1263/1264),
        // только если marking.enable=true и в заказе есть маркированные позиции.
        $this->applyMarkingAttributes($order, $items);
 
        $info['items'] = $items;
        $payments      = (object)[
            'type' => $paymentType,// (($this->oc_type == self::TYPE_AVANS || $this->oc_type == self::TYPE_REFUND_AVANS) ? $paymentType : 'prepaid'),
            'sum'  => $order->co_amount,
        ];
        $info['payments'] = [$payments];
        $info['total']    = $order->co_amount;
 
        // if ($order->co_type == 6) {
        //     $info['receipt'] = (object)['attributes' => (object)['asd' => 1]];
        // }
 
        $data['request'] = [(object)$info];
 
        $this->oc_json = json_encode((object)$data, JSON_UNESCAPED_UNICODE);
 
        return true;
    }
 
    /**
     * Save to DB
     * @throws Exception
     */
    public function save(): void {
        $values = [];
 
        if (isset($this->oc_id) && $this->oc_id > 0) {
            $check = $this;
        } else {
            $query = self::query(sprintf('SELECT * FROM `order_checks` where `oc_uuid` = "%s"', $this->oc_uuid));
            $check = $query->fetch(PDO::FETCH_OBJ);
        }
 
        if (isset($check->oc_id) && $check->oc_id > 0) {
            foreach (self::$fields as $field_name) {
                if (!empty($this->$field_name)) {
                    if (is_string($this->$field_name) || is_int($this->$field_name)) {
                        $values[] = "`$field_name` = '" . addslashes($this->$field_name) . "'";
                    }
                }
            }
 
            $sql = sprintf('UPDATE `order_checks` SET %s WHERE `oc_id` = %s',
                implode(',', $values),
                $check->oc_id);
        } else {
            $fields = self::$fields;
            foreach ($fields as $field_name) {
                if (!empty($this->$field_name)) {
                    $values[] = $this->$field_name;
                } else {
                    if (($key = array_search($field_name, $fields)) !== false) {
                        unset($fields[$key]);
                    }
                }
            }
            $sql = sprintf("INSERT INTO `order_checks` (`%s`) VALUES ('%s')",
                implode('`,`', $fields),
                implode("','", $values));
        }
 
        $result = self::query($sql);
 
        if (!$result) {
            throw new Exception('Error save Check to DB : ' . var_export($this, true)
                . ' : ' . var_export($sql, true)
                . ' : ' . var_export($result, true));
        }
    }
 
    /**
     * @throws Exception
     */
    public function send() {
        $validate = $this->validate();
        if ( !$validate ) {
            throw new Exception('Send Check NO VALIDATE (4487, 4488) for order: ' . $this->oc_ord_id);
        }
 
 
        $headers = ['Content-type: application/json; charset=utf-8', ' '];
 
        $defaults = [
            CURLOPT_URL            => Cassa::$cassa_urls[$this->oc_cassa], // 'http://192.168.1.224:16732/requests',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $this->oc_json,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];
 
        if (isset($this->oc_cassa)
            && in_array($this->oc_cassa, [227, 231, 232])
        ) {
            $defaults[CURLOPT_HTTP_VERSION]   = CURL_HTTP_VERSION_1_1;
            $defaults[CURLOPT_HTTPAUTH]       = CURLAUTH_BASIC;
            $defaults[CURLOPT_USERPWD]        = "admin:132Svs15132Svs15";
        }
 
        $ch = curl_init();
        $pp = curl_setopt_array($ch, $defaults);
        if (!$pp) {
            throw new Exception('Send Check Error for order: ' . $this->oc_ord_id);
        }
 
        if ( in_array($this->oc_cassa, [231, 232]) ) {
            $results = curl_exec($ch);
        } else {
            for ($i = 0; $i < 30; $i++) {
                $results = curl_exec($ch);
                if ($results) {
                    break;
                }
                sleep(1);
            }
        }
 
        curl_close($ch);
 
        return json_decode($results);
    }
 
 
    /**
     * @throws Exception
     */
    public function reset() {
        $result  = false;
        $url     = Cassa::$cassa_urls[$this->oc_cassa] . '/' . $this->oc_uuid .'?reset=1';
        $headers = ['Content-type: application/json; charset=utf-8', ' '];
 
        $defaults = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     = $headers,
            CURLOPT_POSTFIELDS     = $this->oc_json,
            CURLOPT_HTTP_VERSION   = CURL_HTTP_VERSION_1_1,
        ];
        $ch = curl_init();
        $pp = curl_setopt_array($ch, $defaults);
        if ($pp) {
            $results = curl_exec($ch);
            $result  = json_decode($results);
            curl_close($ch);
        }
 
        $this->validate_answer($result, $url);
 
        return $result;
    }
 
    private function validate(): bool {
        $orderRequest = $this->getOrderRequest();
        if ( in_array($orderRequest->or_pay_bank, [Order::BANK_YOOKASSA_VS, Order::BANK_YOOKASSA_V]) ) {
            // return false;
        }
 
        if ( date('Y-m-d H:i:s') > '2023-11-11 00:00:00' ) {
            $order = $this->getOrder();
            foreach ($order->items as $item) {
                if ( isset($item['cod_prod_id']) && in_array($item['cod_prod_id'], self::$block_items) ) {
                    return false;
                }
            }
        }
 
        return true;
    }
 
    /**
     * @throws Exception
     */
    public function requestDataFromCassa(): mixed {
        $result = false;
        $url = Cassa::$cassa_urls[$this->oc_cassa] . '/' . $this->oc_uuid;
 
        $defaults = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
        ];
 
        if (isset($this->oc_cassa)
            && in_array($this->oc_cassa, [227, 231, 232])
        ) {
            $defaults[CURLOPT_HTTPGET]        = true;
            $defaults[CURLOPT_RETURNTRANSFER] = true;
            $defaults[CURLOPT_HTTPAUTH]       = CURLAUTH_BASIC;
            $defaults[CURLOPT_USERPWD]        = "admin:132Svs15132Svs15";
        }
 
        $ch = curl_init();
        $pp = curl_setopt_array($ch, $defaults);
        if ($pp) {
            $results = curl_exec($ch);
            $result  = json_decode($results);
            curl_close($ch);
 
            if (!isset($result->error) && !empty($result->results[0]->result)) {
                $fiscalDocumentSign   = $result->results[0]->result->fiscalParams->fiscalDocumentSign ?? $this->oc_fpd;
                $fiscalDocumentNumber = $result->results[0]->result->fiscalParams->fiscalDocumentNumber ?? $this->oc_fdn;
                $fiscalReceiptNumber  = $result->results[0]->result->fiscalParams->fiscalReceiptNumber ?? $this->oc_frn;
                $shiftNumber          = $result->results[0]->result->fiscalParams->shiftNumber ?? $this->oc_shift;
 
                $this->oc_fpd   = $fiscalDocumentSign;
                $this->oc_fdn   = $fiscalDocumentNumber;
                $this->oc_frn   = $fiscalReceiptNumber;
                $this->oc_shift = $shiftNumber;
            }
        }
 
        $this->validate_answer($result, $url);
 
        return $result;
    }
 
    /**
     * @throws Exception
     */
    private function validate_answer($result, $url): void {
        if (!$result || isset($result->error) || (isset($result->results[0]->status) && $result->results[0]->status == 'error')) {
            if ( isset($result->error->code) && $result->error->code == 505
                && (strpos($result->error->description, 'задание с таким UUID уже есть') > 0
                    || strpos($result->error->description, 'не найдено') > 0)
            ) {  // задание не найдено.
                $this->oc_status = Check::STATUS_NEW;
            } else {
                $this->oc_status = Check::STATUS_ERROR;
                $this->oc_error = (isset($result->error->code) && isset($result->error->description))
                    ? ($result->error->code . ' ' . $result->error->description.' : '.$url)
                    : (isset($result->results[0]->errorDescription)
                        ? $result->results[0]->errorCode . ' : ' . $result->results[0]->errorDescription .' : '.$url
                        : 'error'
                    );
 
                OrderPayRequest::setStatus($this->oc_ord_id, OrderPayRequest::ERROR_SEND_CHECK);
            }
        } else {
            $this->oc_status = Check::STATUS_SEND;
            $this->oc_error = '';
 
            OrderPayRequest::setStatus($this->oc_ord_id, OrderPayRequest::STATUS_CHECK_DONE);
        }
 
        $this->save();
    }
 
    public function saveToAtol($result = null) {
        if (is_null($result)) {
            $time   = $this->oc_indate;
            $fn     = '';
            $fpd    = $this->oc_fpd;
            $status = $this->oc_status;
            $error  = $this->oc_error;
        }
 
        $to_save = [
            'uuid'       => $this->oc_uuid,
            'time'       => $result->results[0]->result->fiscalParams->fiscalDocumentDateTime ?? $time ?? '',
            'fn'         => $result->results[0]->result->fiscalParams->fnNumber ?? $fn ?? '',
            'fpd'        => $result->results[0]->result->fiscalParams->fiscalDocumentSign ?? $fpd ?? '',
            'status'     => $result->status ?? $status ?? '',
            'error'      => $result->results[0]->error ?? $error ?? '',
            'order'      => $this->oc_ord_id,
            'type'       => $this->oc_type,
            'order_atol' => $this->oc_ord_id,
            'fd'         => $this->oc_fdn,
            'cassa'      => $this->oc_cassa,  // 228 = 7, 224 = 5, 232 = 4, 226 = 154,  154 = 6
        ];
 
        $defaults = [
            CURLOPT_URL            => 'https://www.veira.net/shop/save_atol.php',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query($to_save),
        ];
 
        $ch = curl_init();
        $pp = curl_setopt_array($ch, $defaults);
        $results = curl_exec($ch);
        curl_close($ch);
 
        return json_decode($results);
    }
 
    /**
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendEmailClient(): array {