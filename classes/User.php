<?php
/**
 * Fixed User Class for QUICKBILL 305
 * Compatible with any Database setup
 */

class User {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Create a new user
     */
    public function createUser($userData) {
        try {
            // Validate required fields
            $required = ['username', 'email', 'password', 'first_name', 'last_name', 'role_id'];
            foreach ($required as $field) {
                if (empty($userData[$field])) {
                    throw new Exception("Field '$field' is required.");
                }
            }
            
            // Check if username exists
            if ($this->usernameExists($userData['username'])) {
                throw new Exception('Username already exists.');
            }
            
            // Check if email exists
            if ($this->emailExists($userData['email'])) {
                throw new Exception('Email already exists.');
            }
            
            // Hash password
            $passwordHash = password_hash($userData['password'], PASSWORD_DEFAULT);
            
            // Prepare data
            $query = "INSERT INTO users (username, email, password_hash, role_id, first_name, last_name, phone, is_active, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $params = [
                $userData['username'],
                $userData['email'],
                $passwordHash,
                $userData['role_id'],
                $userData['first_name'],
                $userData['last_name'],
                $userData['phone'] ?? null,
                $userData['is_active'] ?? 1
            ];
            
            if ($this->db->execute($query, $params)) {
                return getLastInsertId($this->db);
            }
            
            throw new Exception('Failed to create user.');
            
        } catch (Exception $e) {
            throw new Exception('Create user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update user information
     */
    public function updateUser($userId, $userData) {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                throw new Exception('User not found.');
            }
            
            // Check username uniqueness (excluding current user)
            if (isset($userData['username']) && $userData['username'] !== $user['username']) {
                if ($this->usernameExists($userData['username'], $userId)) {
                    throw new Exception('Username already exists.');
                }
            }
            
            // Check email uniqueness (excluding current user)
            if (isset($userData['email']) && $userData['email'] !== $user['email']) {
                if ($this->emailExists($userData['email'], $userId)) {
                    throw new Exception('Email already exists.');
                }
            }
            
            // Build update query dynamically
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['username', 'email', 'first_name', 'last_name', 'phone', 'role_id', 'is_active'];
            
            foreach ($allowedFields as $field) {
                if (isset($userData[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $userData[$field];
                }
            }
            
            // Handle password update
            if (!empty($userData['password'])) {
                $updateFields[] = 'password_hash = ?';
                $updateFields[] = 'first_login = 1'; // Reset first login flag
                $params[] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($updateFields)) {
                throw new Exception('No fields to update.');
            }
            
            $updateFields[] = 'updated_at = NOW()';
            $params[] = $userId;
            
            $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
            
            if ($this->db->execute($query, $params)) {
                return true;
            }
            
            throw new Exception('Failed to update user.');
            
        } catch (Exception $e) {
            throw new Exception('Update user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user by ID with role information
     */
    public function getUserById($userId) {
        try {
            $query = "SELECT u.*, ur.role_name, ur.description as role_description 
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     WHERE u.user_id = ?";
            
            return $this->db->fetchRow($query, [$userId]);
            
        } catch (Exception $e) {
            throw new Exception('Get user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user by username
     */
    public function getUserByUsername($username) {
        try {
            $query = "SELECT u.*, ur.role_name 
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     WHERE u.username = ?";
            
            return $this->db->fetchRow($query, [$username]);
            
        } catch (Exception $e) {
            throw new Exception('Get user by username error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user by email
     */
    public function getUserByEmail($email) {
        try {
            $query = "SELECT u.*, ur.role_name 
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     WHERE u.email = ?";
            
            return $this->db->fetchRow($query, [$email]);
            
        } catch (Exception $e) {
            throw new Exception('Get user by email error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get all users with filtering and pagination
     */
    public function getUsers($filters = [], $limit = null, $offset = null) {
        try {
            $conditions = [];
            $params = [];
            
            // Build WHERE conditions
            if (!empty($filters['search'])) {
                $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            if (!empty($filters['role'])) {
                $conditions[] = "ur.role_name = ?";
                $params[] = $filters['role'];
            }
            
            if (isset($filters['status']) && $filters['status'] !== '') {
                $conditions[] = "u.is_active = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            // Build query
            $query = "SELECT u.*, ur.role_name 
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     $whereClause 
                     ORDER BY u.created_at DESC";
            
            // Add pagination
            if ($limit !== null) {
                $query .= " LIMIT $limit";
                if ($offset !== null) {
                    $query .= " OFFSET $offset";
                }
            }
            
            return $this->db->fetchAll($query, $params);
            
        } catch (Exception $e) {
            throw new Exception('Get users error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get total user count with filters
     */
    public function getUserCount($filters = []) {
        try {
            $conditions = [];
            $params = [];
            
            if (!empty($filters['search'])) {
                $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            if (!empty($filters['role'])) {
                $conditions[] = "ur.role_name = ?";
                $params[] = $filters['role'];
            }
            
            if (isset($filters['status']) && $filters['status'] !== '') {
                $conditions[] = "u.is_active = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $query = "SELECT COUNT(*) as total 
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     $whereClause";
            
            $result = $this->db->fetchRow($query, $params);
            return $result['total'] ?? 0;
            
        } catch (Exception $e) {
            throw new Exception('Get user count error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user statistics
     */
    public function getUserStats() {
        try {
            $stats = [];
            
            // Total users
            $result = $this->db->fetchRow("SELECT COUNT(*) as count FROM users");
            $stats['total'] = $result['count'] ?? 0;
            
            // Active users
            $result = $this->db->fetchRow("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
            $stats['active'] = $result['count'] ?? 0;
            
            // Inactive users
            $result = $this->db->fetchRow("SELECT COUNT(*) as count FROM users WHERE is_active = 0");
            $stats['inactive'] = $result['count'] ?? 0;
            
            // Admin users
            $result = $this->db->fetchRow("
                SELECT COUNT(*) as count 
                FROM users u 
                JOIN user_roles ur ON u.role_id = ur.role_id 
                WHERE ur.role_name IN ('Admin', 'Super Admin') AND u.is_active = 1
            ");
            $stats['admins'] = $result['count'] ?? 0;
            
            return $stats;
            
        } catch (Exception $e) {
            throw new Exception('Get user stats error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username, $excludeUserId = null) {
        try {
            $query = "SELECT user_id FROM users WHERE username = ?";
            $params = [$username];
            
            if ($excludeUserId) {
                $query .= " AND user_id != ?";
                $params[] = $excludeUserId;
            }
            
            $result = $this->db->fetchRow($query, $params);
            return !empty($result);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email, $excludeUserId = null) {
        try {
            $query = "SELECT user_id FROM users WHERE email = ?";
            $params = [$email];
            
            if ($excludeUserId) {
                $query .= " AND user_id != ?";
                $params[] = $excludeUserId;
            }
            
            $result = $this->db->fetchRow($query, $params);
            return !empty($result);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Activate user
     */
    public function activateUser($userId) {
        try {
            $query = "UPDATE users SET is_active = 1, updated_at = NOW() WHERE user_id = ?";
            return $this->db->execute($query, [$userId]);
            
        } catch (Exception $e) {
            throw new Exception('Activate user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Deactivate user (soft delete)
     */
    public function deactivateUser($userId) {
        try {
            $query = "UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id = ?";
            return $this->db->execute($query, [$userId]);
            
        } catch (Exception $e) {
            throw new Exception('Deactivate user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Hard delete user (permanent)
     */
    public function deleteUser($userId) {
        try {
            beginTransaction($this->db);
            
            // Delete audit logs for this user
            $this->db->execute("DELETE FROM audit_logs WHERE user_id = ?", [$userId]);
            
            // Delete user
            $this->db->execute("DELETE FROM users WHERE user_id = ?", [$userId]);
            
            commitTransaction($this->db);
            return true;
            
        } catch (Exception $e) {
            rollbackTransaction($this->db);
            throw new Exception('Delete user error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update user password
     */
    public function updatePassword($userId, $newPassword, $resetFirstLogin = true) {
        try {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $query = "UPDATE users SET password_hash = ?, first_login = ?, updated_at = NOW() WHERE user_id = ?";
            $params = [$passwordHash, $resetFirstLogin ? 1 : 0, $userId];
            
            return $this->db->execute($query, $params);
            
        } catch (Exception $e) {
            throw new Exception('Update password error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update last login time
     */
    public function updateLastLogin($userId) {
        try {
            $query = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
            return $this->db->execute($query, [$userId]);
            
        } catch (Exception $e) {
            throw new Exception('Update last login error: ' . $e->getMessage());
        }
    }
    
    /**
     * Complete first login setup
     */
    public function completeFirstLogin($userId) {
        try {
            $query = "UPDATE users SET first_login = 0, updated_at = NOW() WHERE user_id = ?";
            return $this->db->execute($query, [$userId]);
            
        } catch (Exception $e) {
            throw new Exception('Complete first login error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user activity statistics
     */
    public function getUserActivity($userId) {
        try {
            $activity = [];
            
            // Total actions
            $result = $this->db->fetchRow("
                SELECT COUNT(*) as total_actions,
                       MAX(created_at) as last_activity,
                       COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as actions_last_30_days,
                       COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as actions_last_7_days
                FROM audit_logs 
                WHERE user_id = ?", [$userId]);
            
            $activity = $result ?? [];
            
            // Recent activity
            $activity['recent_activity'] = $this->db->fetchAll("
                SELECT action, table_name, record_id, created_at, ip_address 
                FROM audit_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10", [$userId]);
            
            return $activity;
            
        } catch (Exception $e) {
            throw new Exception('Get user activity error: ' . $e->getMessage());
        }
    }
    
    /**
     * Verify user password
     */
    public function verifyPassword($userId, $password) {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return false;
            }
            
            return password_verify($password, $user['password_hash']);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all roles
     */
    public function getRoles() {
        try {
            return $this->db->fetchAll("SELECT * FROM user_roles ORDER BY role_name");
            
        } catch (Exception $e) {
            throw new Exception('Get roles error: ' . $e->getMessage());
        }
    }
    
    /**
     * Search users
     */
    public function searchUsers($searchTerm, $limit = 10) {
        try {
            $searchTerm = "%$searchTerm%";
            
            $query = "SELECT u.user_id, u.username, u.email, u.first_name, u.last_name, ur.role_name
                     FROM users u 
                     JOIN user_roles ur ON u.role_id = ur.role_id 
                     WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)
                     AND u.is_active = 1
                     ORDER BY u.first_name, u.last_name 
                     LIMIT ?";
            
            return $this->db->fetchAll($query, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
            
        } catch (Exception $e) {
            throw new Exception('Search users error: ' . $e->getMessage());
        }
    }
}
?>