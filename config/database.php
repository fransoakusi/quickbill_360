<?php
/**
 * Database Configuration for QUICKBILL 305
 * Handles database connection and common database operations
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'quickbill_305';
    private $username = 'root';
    private $password = '';
    private $conn;
    private $charset = 'utf8mb4';

    public function __construct() {
        $this->connect();
    }

    /**
     * Create database connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }
    }

    /**
     * Get database connection
     */
    public function getConnection() {
        return $this->conn;
    }

    /**
     * Execute a prepared statement
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            error_log("Database Execute Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch single row
     */
    public function fetchRow($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    /**
     * Fetch multiple rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt ? $stmt->fetchAll() : false;
    }

    /**
     * Get last inserted ID
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->conn->rollback();
    }

    /**
     * Check if table exists
     */
    public function tableExists($tableName) {
        $sql = "SHOW TABLES LIKE :table";
        $stmt = $this->execute($sql, ['table' => $tableName]);
        return $stmt && $stmt->rowCount() > 0;
    }

    /**
     * Get database connection status
     */
    public function getStatus() {
        try {
            $this->conn->query('SELECT 1');
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
}

// Test the database connection
function testDatabaseConnection() {
    try {
        $db = new Database();
        if ($db->getStatus()) {
            return [
                'status' => 'success',
                'message' => 'Database connection successful'
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Database connection failed'
            ];
        }
    } catch(Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Database connection error: ' . $e->getMessage()
        ];
    }
}
?> 
