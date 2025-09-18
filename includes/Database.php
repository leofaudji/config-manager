<?php
class Database
{
    private static ?self $instance = null;
    private mysqli $conn;

    private function __construct()
    {
        $server = Config::get('DB_SERVER');
        $username = Config::get('DB_USERNAME');
        $password = Config::get('DB_PASSWORD');
        $name = Config::get('DB_NAME');

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->conn = new mysqli($server, $username, $password, $name);
            $this->conn->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            // In a real app, log this error instead of echoing
            error_log("Database connection failed: " . $e->getMessage());
            // Provide a generic error to the user
            die("Koneksi ke database gagal. Silakan coba lagi nanti.");
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): mysqli
    {
        return $this->conn;
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {}
}