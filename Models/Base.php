<?php
 
namespace Models;
 
use Dotenv\Dotenv;
use PDO;
use PDOException;
use PDOStatement;
 
require __DIR__ . '/../vendor/autoload.php';
 
class Base
{
    public array $config = [];
 
    public static function fromStdClass(\stdClass $object) {
        $class = get_called_class();
        $result = new $class;
        foreach ($object as $key => $value) {
            $result->$key = $value ?? 0;
        }
 
        return $result;
    }
 
    public static function getConfig(): array {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
 
        $config = [];
        $config['DB_HOST'] = $_ENV['DB_HOST'] ?? 'localhost';
        $config['DB_USER'] = $_ENV['DB_USER'] ?? 'inter_dist';
        $config['DB_PASS'] = $_ENV['DB_PASS'] ?? '873Idt16';
        $config['DB_NAME'] = $_ENV['DB_NAME'] ?? 'veira-souz';
        $config['VEIRA.NET_TOKEN'] = $_ENV['VEIRA.NET_TOKEN'] ?? 'eb9912b801b1fea99adadbb0e67fb8aa';
 
        $config['TELEGRAM_LOGGER_BOT_TOKEN'] = $_ENV['TELEGRAM_LOGGER_BOT_TOKEN'] ?? '';
        $config['TELEGRAM_LOGGER_CHAT_ID'] = $_ENV['TELEGRAM_LOGGER_CHAT_ID'] ?? '';
        $config['TELEGRAM_LOGGER_ERROR_CHAT_ID'] = $_ENV['TELEGRAM_LOGGER_ERROR_CHAT_ID'] ?? '';
        $config['TELEGRAM_AUTH_BOT_TOKEN'] = $_ENV['TELEGRAM_AUTH_BOT_TOKEN'] ?? '';
 
        return $config;
    }
 
    /**
     * COMMENTS-9 блок 2/3: тестовый оверрайт — маршрутизация self::query() во внешний
     * PDO (SQLite в tests/DbSmokeTest.php и тестках очередей). null — реальный MySQL.
     * Механизм необходимый, т.к. модели проекта статические (DI отсутствует).
     */
    public static ?PDO $testPdo = null;
 
    /**
     * @param $sql
     * @param int $mode
     *
     * @return false|PDOStatement
     */
    public static function query($sql, int $mode = PDO::FETCH_ASSOC): bool|PDOStatement {
        if (self::$testPdo !== null) {
            try {
                return self::$testPdo->query($sql, $mode);
            } catch (PDOException $e) {
                return false;
            }
        }
 
        $config = self::getConfig();
 
        try {
            $dbh = new Pdo(sprintf('mysql:host=%s;dbname=%s', $config['DB_HOST'], $config['DB_NAME']),
                $config['DB_USER'], $config['DB_PASS']);
 
            return $dbh->query($sql, $mode);
        } catch (PDOException $e) {
            $logger = new Logger();
            $logger->log('Error Mysql: ' . $sql . ' : ' . $e->getMessage());
 
            return false;
        }
    }
 
    /**
     * @param $sql
     *
     * @return array
     */
    public static function get($sql): array {
        $query = self::query($sql);
 
        /** @var Base $class */
        $class = get_called_class();
        $primary = $class::$primary;
 
        $result = array();
        if ($query) {
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $object = new $class;
                foreach ($row as $key => $value) {
                    $object->$key = $value ?? 0;
                }
                $result[$object->$primary] = $object;
            }
        }
 
        return $result;
    }
 
    public static function queryFetch($sql, int $mode = PDO::FETCH_OBJ) {
        $query = self::query($sql, $mode);
        if ($query) {
            $data = $query->fetch($mode);
            if ($data) {
                return $data;
            }
        }
 
        return false;
    }