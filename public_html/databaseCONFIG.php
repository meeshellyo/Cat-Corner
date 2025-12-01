<?php
class Database {
    private static $mysqli = null;

    public static function dbConnect() {
        require_once __DIR__ . "/../DB_config_private.php";

        try {
            self::$mysqli = new PDO(
                "mysql:host=" . DBHOST . ";dbname=" . DBNAME,
                USERNAME,
                PASSWORD
            );
        } catch (PDOException $e) {
            die($e->getMessage());
        }

        return self::$mysqli;
    }
}
?>
