<?php
/**
 * MySQL Database Connection for Buyer Portal
 * Connects to Rukovoditel CRM database
 */

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=rukovoditel;charset=utf8mb4',
            'kylewee',
            'rainonin',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}
