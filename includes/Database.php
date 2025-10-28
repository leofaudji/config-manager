<?php

class Database
{
    private static ?Database $instance = null;
    private ?mysqli $conn;

    /**
     * Private constructor to prevent direct instantiation.
     * Establishes the database connection.
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Establishes a new database connection.
     * @throws Exception if connection fails.
     */
    private function connect(): void
    {
        $db_server = Config::get('DB_SERVER');
        $db_username = Config::get('DB_USERNAME');
        $db_password = Config::get('DB_PASSWORD');
        $db_name = Config::get('DB_NAME');
        // Suppress errors to handle them manually
        $this->conn = @new mysqli($db_server, $db_username, $db_password, $db_name);

        if ($this->conn->connect_error) {
            throw new Exception("Database connection failed: " . $this->conn->connect_error);
        }

        $this->conn->set_charset("utf8mb4");
    }

    /**
     * Gets the single instance of the Database class.
     * @return Database The single instance.
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the active database connection.
     * @return mysqli The mysqli connection object.
     */
    public function getConnection(): mysqli
    {
        return $this->conn;
    }

    /**
     * Re-establishes the database connection.
     * This is crucial for child processes created by pcntl_fork.
     */
    public function reconnect(): void
    {
        if ($this->conn) {
            $this->conn->close();
        }
        $this->connect();
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}