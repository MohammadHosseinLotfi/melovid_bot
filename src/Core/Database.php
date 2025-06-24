<?php

namespace TelegramMusicBot\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?PDO $pdo = null;
    private static string $dsn;
    private static string $username;
    private static string $password;
    private static array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * Initializes the database connection parameters.
     * This method should be called once, typically at the application's entry point.
     */
    public static function init(): void
    {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_CHARSET')) {
            error_log("Database configuration constants are not defined.");
            throw new \Exception("Database configuration is incomplete.");
        }

        self::$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        self::$username = DB_USER;
        self::$password = DB_PASSWORD;
    }

    /**
     * Gets the PDO instance. Connects if not already connected.
     *
     * @return PDO
     * @throws \Exception if database configuration is not initialized or connection fails.
     */
    private static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            if (empty(self::$dsn)) {
                // Attempt to initialize if not done yet (e.g., for CLI scripts or tests)
                // In a web context, init() should be called by the bootstrap process.
                self::init(); 
            }
            try {
                self::$pdo = new PDO(self::$dsn, self::$username, self::$password, self::$options);
            } catch (PDOException $e) {
                error_log("Database Connection Error: " . $e->getMessage());
                throw new \Exception("Could not connect to the database: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    /**
     * Executes a prepared statement.
     *
     * @param string $sql SQL query with placeholders.
     * @param array $params Parameters to bind to the query.
     * @return PDOStatement|false The PDOStatement object, or false on failure.
     */
    public static function executeQuery(string $sql, array $params = []): PDOStatement|false
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database Query Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            // Depending on the error, you might want to throw the exception
            // or return false/null to indicate failure.
            // For critical operations, re-throwing might be better.
            // throw $e; 
            return false;
        }
    }

    /**
     * Fetches a single row from a query.
     *
     * @param string $sql
     * @param array $params
     * @param int $fetchStyle
     * @return mixed The row as an associative array or object, or false if no row.
     */
    public static function fetchOne(string $sql, array $params = [], int $fetchStyle = PDO::FETCH_ASSOC): mixed
    {
        $stmt = self::executeQuery($sql, $params);
        return $stmt ? $stmt->fetch($fetchStyle) : false;
    }

    /**
     * Fetches all rows from a query.
     *
     * @param string $sql
     * @param array $params
     * @param int $fetchStyle
     * @return array An array of rows, or an empty array if no rows/failure.
     */
    public static function fetchAll(string $sql, array $params = [], int $fetchStyle = PDO::FETCH_ASSOC): array
    {
        $stmt = self::executeQuery($sql, $params);
        return $stmt ? $stmt->fetchAll($fetchStyle) : [];
    }

    /**
     * Returns the ID of the last inserted row.
     *
     * @return string|false The ID of the last inserted row, or false on failure.
     */
    public static function lastInsertId(): string|false
    {
        if (self::$pdo) {
            return self::$pdo->lastInsertId();
        }
        return false;
    }
    
    /**
     * Begins a transaction.
     * @return bool True on success, false on failure.
     */
    public static function beginTransaction(): bool
    {
        if (self::$pdo === null) {
            self::getConnection(); // Ensure connection is established
        }
        return self::$pdo->beginTransaction();
    }

    /**
     * Commits a transaction.
     * @return bool True on success, false on failure.
     */
    public static function commit(): bool
    {
        return self::$pdo ? self::$pdo->commit() : false;
    }

    /**
     * Rolls back a transaction.
     * @return bool True on success, false on failure.
     */
    public static function rollBack(): bool
    {
        return self::$pdo ? self::$pdo->rollBack() : false;
    }

    /**
     * Checks if inside a transaction.
     * @return bool True if a transaction is currently active, false otherwise.
     */
    public static function inTransaction(): bool
    {
        return self::$pdo ? self::$pdo->inTransaction() : false;
    }
}
