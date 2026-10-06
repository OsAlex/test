<?php

namespace Models;

use DateTime;
use DateTimeZone;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use PDO;

class Order extends Base
{
    public static string $db_name = 'veira-souz';
    static public string $table = 'cassa_ord';
    static public string $primary = 'co_id';

    public int $co_id;
    public int $co_type;
    public int $co_bank;
    public int $co_pay_type;
    public int $co_ord_id;
    public float $co_amount;
    public float $co_avans_amount;
    public float $co_prihod_amount;
    public float $co_correct_amount;
    public string $co_pay_date;
    public string $co_in_date;
    public int $co_was_del_pay;
    public float $co_del_pay;
    public string $co_email;
    public string $co_phone;

    public array $items;
    public string $co_fio;
    public string $co_link;

    public const BANK_SBER_VS     = 11;
    public const BANK_TINKOFF_VS  = 12;  // на veira.net 14
    public const BANK_TINKOFF_V   = 13;
    public const BANK_SBER_V      = 14;  // на veira.net 15
    public const BANK_PROMO       = 20;
    public const BANK_UNITELLER   = 30;
    public const BANK_YOOKASSA_VS = 31;
    public const BANK_YOOKASSA_V  = 32;

    static public array $banks = [
        self::BANK_SBER_VS     => 'Сбербанк ВС',
        self::BANK_TINKOFF_VS  => 'Тинькофф ВС',
        self::BANK_TINKOFF_V   => 'Тинькофф В',
        self::BANK_SBER_V      => 'Сбербанк В',
        self::BANK_PROMO       => 'Промо',
        self::BANK_UNITELLER   => 'Uniteller',
        self::BANK_YOOKASSA_VS => 'ЮKassa ВС',
        self::BANK_YOOKASSA_V  => 'ЮKassa В',
    ];

    public const PAY_TYPE_RES_CARD = 4;                       // ресепшн карта (только приход)
    public const PAY_TYPE_BEZNAL   = 5;                         // безнал
    public const PAY_TYPE_RES_NAL  = 6;                        // ресепшн наличные (только приход)
    public const PAY_TYPE_INET     = 11;                          // интернет карта (с авансом)
    public const PAY_TYPE_INET_2   = 25;                          // интернет карта (с авансом) тестовые заказы
    public const PAY_TYPE_PROMO    = 20;                         // промо, без чека

    static public array $pay_types = [
        self::PAY_TYPE_RES_CARD => 'ресепшн карта',
        self::PAY_TYPE_BEZNAL   => 'безнал',
        self::PAY_TYPE_RES_NAL  => 'ресепшн наличные',
        self::PAY_TYPE_INET     => 'интернет',
        self::PAY_TYPE_PROMO    => 'промо',
    ];

    public const CO_TYPE_VS            = 1;            // veira-souz
    public const CO_TYPE_DOLG          = 2;          // долговые (без чека)
    public const CO_TYPE_PVP_3         = 3;         // pvp (без чека)
    public const CO_TYPE_PVP_4         = 4;         // pvp (без чека)
    public const CO_TYPE_PVP_5         = 5;         // - (без чека)
    public const CO_TYPE_ASD           = 6;           // asd (только приход)
    public const CO_TYPE_VS_DOST       = 7;       // veira-souz оплата доставки
    public const CO_TYPE_BILET         = 8;         // bilet
    public const CO_TYPE_VS_TEST       = 9;       // veira-souz тестеры (подарочные, без чека)
    public const CO_TYPE_V_CASHBACK    = 10;   // veira кеш бек
    public const CO_TYPE_V             = 11;            // veira
    public const CO_TYPE_VS_CASHBACK   = 12;  // veira-souz кеш бек
    public const CO_TYPE_BILET_V       = 13;      // bilet veira
    public const CO_TYPE_PVP_RECOMEND  = 15; // рекомендации для пвп (без чека)
    public const CO_TYPE_VS_AVANS      = 17;     // veira-souz аванс
    public const CO_TYPE_V_AVANS       = 18;      // veira аванс
    public const CO_TYPE_PROMO         = 20;        // промо заказ (без чека)
    public const CO_TYPE_SERVICE       = 25;      // услуги
    public const CO_TYPE_BILET_SERVICE = 26; // билет-услуга

    static public array $types = [
        self::CO_TYPE_VS            => 'ВейраСоюз',
        self::CO_TYPE_DOLG          => 'Долговой',
        self::CO_TYPE_ASD           => 'АСД',
        self::CO_TYPE_VS_DOST       => 'Доставка (ВС)',
        self::CO_TYPE_BILET         => 'Билет',
        self::CO_TYPE_V_CASHBACK    => 'Вейра Кэшбэк',
        self::CO_TYPE_V             => 'Вейра',
        self::CO_TYPE_VS_CASHBACK   => 'ВейраСоюз Кэшбэк',
        self::CO_TYPE_BILET_V       => 'Билет Вейра',
        self::CO_TYPE_PVP_RECOMEND  => 'рекомендации для пвп',
        self::CO_TYPE_VS_AVANS      => 'veira-souz аванс ',
        self::CO_TYPE_V_AVANS       => 'veira аванс',
        self::CO_TYPE_PROMO         => 'Промо',
        self::CO_TYPE_SERVICE       => 'Услуги',
        self::CO_TYPE_BILET_SERVICE => 'Билет-Услуга',
    ];

    function __construct(int $id = null) {
        if ($id) {
            $query = self::query(sprintf('SELECT * FROM `cassa_ord` where `co_id` = %s', $id));
            if ($query) {
                $data_ord = $query->fetch(PDO::FETCH_ASSOC);
                if ($data_ord) {
                    foreach ($data_ord as $field_name => $field_value) {
                        $this->$field_name = $field_value ?? 0;
                    }
                }

                $query = self::query(sprintf('SELECT * FROM `cassa_ord_det` WHERE `cod_ord_id` = %s',
                    $data_ord['co_ord_id']));
                if ($query) {
                    $data_ord_det = $query->fetchAll(PDO::FETCH_ASSOC);
                    if ($data_ord_det) {
                        foreach ($data_ord_det as $row) {
                            $item = [];
                            foreach ($row as $field_name => $field_value) {
                                $item[$field_name] = $field_value;
                            }
                            $this->items[] = $item;
                        }
                    }
                }
            }
        }

        return $this;
    }

    private static function save_order_det_info(array $data, int $order_id): bool {
        $cassa_ord_det_error = false;

        $sql = sprintf('DELETE FROM `cassa_ord_det` WHERE `cod_ord_id` = %d', $order_id);
        $query = self::query($sql);

        if (!$query) {
            return true;
        }

        foreach($data as $values) {
            $sql = sprintf('INSERT INTO `cassa_ord_det`
                        (`cod_ord_id`, `cod_sku`, `cod_prod_id`, 
                        `cod_prod_name`, `cod_price`, `cod_quant`, 
                        `cod_seller`, `cod_nds`)
                    VALUES ("%s", "%s", "%s", 
                            "%s", "%s", "%s", 
                            "%s", "%s")',
                $order_id, $values['sku'], $values['prod_id'],
                $values['name'], $values['price'], $values['quant'],
                $values['seller'], $values['nds']
            );

            $query = self::query($sql);

            if (!$query) {
                $cassa_ord_det_error = true;
                break;
            }
        }

        return $cassa_ord_det_error;
    }

    /**
     * @param $data
     *
     * @return false|Order
     */
    public static function new($data): bool|Order {
        $sql = sprintf('INSERT INTO `cassa_ord`
        (`co_ord_id`, `co_fio`, `co_email`,
         `co_phone`, `co_amount`, `co_avans_amount`,
         `co_type`, `co_pay_date`, `co_pay_type`,
         `co_bank`, `co_del_pay`, `co_link`)
         VALUES ("%s", "%s", "%s",
                 "%s", "%s", "%s",
                 "%s", "%s", "%s",
                 "%s", "%s", "%s")',
            $data['co_ord_id'], $data['co_fio'], $data['co_email'],
            $data['co_phone'], $data['co_amount'], $data['co_avans_amount'],
            $data['co_type'], $data['co_pay_date'], $data['co_pay_type'],
            $data['co_bank'], $data['co_del_pay'], $data['co_link']
        );
        $status_cassa_ord = Base::query($sql);

        if ($status_cassa_ord) {
            $cassa_ord_det_error = self::save_order_det_info($data['det'], $data['co_ord_id']);
        }

        if (!$status_cassa_ord || $cassa_ord_det_error) {
            self::delete($data['co_ord_id']);

            $result = false;
        } else {
            $result = self::getByOrderId($data['co_ord_id']);
        }

        return $result;
    }

    public static function update(mixed $data): bool|Order {
        $sql = sprintf('UPDATE `cassa_ord` SET `co_fio` = "%s", `co_email` = "%s",
         `co_phone` = "%s", `co_amount` = "%s", `co_avans_amount` = "%s",
         `co_type` = %s, `co_pay_date` = "%s", `co_pay_type` = %s,
         `co_bank` = %s, `co_del_pay` = %s, `co_link` = "%s"
         WHERE `co_ord_id` = %s',
            $data['co_fio'], $data['co_email'],
            $data['co_phone'], $data['co_amount'], $data['co_avans_amount'],
            $data['co_type'], $data['co_pay_date'], $data['co_pay_type'],
            $data['co_bank'], $data['co_del_pay'], $data['co_link'],
            $data['co_ord_id']
        );
        $status_cassa_ord = Base::query($sql);

        if ($status_cassa_ord) {
            $cassa_ord_det_error = self::save_order_det_info($data['det'], $data['co_ord_id']);
        }

        if (!$status_cassa_ord || $cassa_ord_det_error) {
            self::delete($data['co_ord_id']);

            $result = false;
        } else {
            $result = self::getByOrderId($data['co_ord_id']);
        }

        return $result;
    }

    public static function getByOrderId(int $orderId): bool|self {
        $instance = false;
        $query = self::query(sprintf('SELECT * FROM `cassa_ord` where `co_ord_id` = %s', $orderId));
        if ($query) {
            $data = $query->fetch(PDO::FETCH_ASSOC);
            if (isset($data['co_id'])) {
                $instance = new self($data['co_id']);
            }
        }

        return $instance;
    }

    #[ArrayShape(['status' => "bool", 'values' => "array"])]
    public static function validate(array $result): array {
        $order_det_sum = 0;
        foreach ($result['det'] as $values) {
            $order_det_sum += ($values['price'] * $values['quant']);
        }

        return [
            'status' => (($order_det_sum + $result['co_del_pay']) == $result['co_amount']),
            'values' => [
                'order_det_sum' => $order_det_sum,
                'co_del_pay'    => $result['co_del_pay'],
                'co_amount'     => $result['co_amount'],
            ],
        ];
    }

    /**
     * @param int $or_ord_id
     */
    public static function delete(int $or_ord_id): void {
        $sql = sprintf('DELETE FROM `cassa_ord_det` WHERE `cod_ord_id` = %s', $or_ord_id);
        Base::query($sql);
        $sql = sprintf('DELETE FROM `cassa_ord` WHERE `co_ord_id` = %s', $or_ord_id);
        Base::query($sql);
    }

    public static function getForPeriod(string $from, string $to): array {
        $sql = sprintf('SELECT * FROM `cassa_ord` WHERE `co_pay_date` >= "%s" AND `co_pay_date` <= "%s"', $from, $to);
        $query = self::query($sql);
        $result = [];
        if ($query) {
            while ($data = $query->fetch( PDO::FETCH_OBJ)) {
                $result[] = Order::fromStdClass($data);
            }
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function createCassa(): Cassa {
        $instance = false;

        foreach (Cassa::$cassa_conditions as $condition) {
            if (in_array($this->co_bank, $condition['conditions']['banks'])
                && in_array($this->co_type, $condition['conditions']['type'])
                && in_array($this->co_pay_type, $condition['conditions']['pay_type'])
            ) {
                $instance = new Cassa();
                $instance->id = $condition['cassa_id'];
                $instance->url = Cassa::$cassa_urls[$condition['cassa_id']];

                break;
            }
        }

        if ( $instance->id == Cassa::CASSA_VS_I ) {
//            $cassa_for_check = [
//                Cassa::CASSA_VS_I,
//                Cassa::CASSA_VS_II,
//            ];

//            $now = new DateTime('now', new DateTimeZone('UTC'));
//            $targetDate = new DateTime('2025-11-18 20:00:00', new DateTimeZone('UTC'));

//            if ($now > $targetDate) {}

            $cassa_for_check = [
                //Cassa::CASSA_VS_I,  // 232
                Cassa::CASSA_VS_II, //231
                Cassa::CASSA_VS_III,  // 227
            ];

            $cassa_index = $this->co_ord_id % count($cassa_for_check);
            $selected_cassa = $cassa_for_check[$cassa_index];

            $instance->id = $selected_cassa;
            $instance->url = Cassa::$cassa_urls[$selected_cassa];
        }

        if (!$instance) {
            throw new Exception('Error get cassa for order : ' . $this->co_ord_id
                . '; Bank : ' . $this->co_bank . ' ' . (self::$banks[$this->co_bank] ?? '')
                . '; Type : ' . $this->co_type . ' ' . (self::$types[$this->co_type] ?? '')
                . '; Pay_Type : ' . $this->co_pay_type . ' ' . (self::$pay_types[$this->co_pay_type] ?? '')
            );
        }

        return $instance;
    }

    public function hasProduct($product_id): bool {
        $sql = sprintf('SELECT * FROM `cassa_ord_det` WHERE `cod_ord_id` = "%d" AND `cod_prod_id` = "%d"',
            $this->co_ord_id, $product_id);
        $result = self::get($sql);

        return !empty($result);
    }
}