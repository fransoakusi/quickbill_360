<?php
/**
 * Audit Logs Management - QuickBill 305
 * View and manage system audit logs
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../index.php');
    exit();
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize database
$db = new Database();

// Pagination settings
$recordsPerPage = 50;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $recordsPerPage;

// Filter parameters
$filters = [
    'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? intval($_GET['user_id']) : null,
    'action' => isset($_GET['action']) && $_GET['action'] !== '' ? $_GET['action'] : null,
    'table_name' => isset($_GET['table_name']) && $_GET['table_name'] !== '' ? $_GET['table_name'] : null,
    'date_from' => isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null,
    'date_to' => isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null,
    'ip_address' => isset($_GET['ip_address']) && $_GET['ip_address'] !== '' ? $_GET['ip_address'] : null,
    'search' => isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null
];

// Handle export requests
if (isset($_GET['export'])) {
    switch ($_GET['export']) {
        case 'csv':
            exportAuditLogsCSV($db, $filters);
            exit();
        case 'pdf':
            generateAuditLogsPDF($db, $filters);
            exit();
        case 'print':
            showPrintableAuditLogs($db, $filters);
            exit();
    }
}

// Build WHERE clause for filtering
$whereConditions = [];
$whereParams = [];

if ($filters['user_id']) {
    $whereConditions[] = "al.user_id = ?";
    $whereParams[] = $filters['user_id'];
}

if ($filters['action']) {
    $whereConditions[] = "al.action LIKE ?";
    $whereParams[] = '%' . $filters['action'] . '%';
}

if ($filters['table_name']) {
    $whereConditions[] = "al.table_name = ?";
    $whereParams[] = $filters['table_name'];
}

if ($filters['date_from']) {
    $whereConditions[] = "DATE(al.created_at) >= ?";
    $whereParams[] = $filters['date_from'];
}

if ($filters['date_to']) {
    $whereConditions[] = "DATE(al.created_at) <= ?";
    $whereParams[] = $filters['date_to'];
}

if ($filters['ip_address']) {
    $whereConditions[] = "al.ip_address = ?";
    $whereParams[] = $filters['ip_address'];
}

if ($filters['search']) {
    $whereConditions[] = "(al.action LIKE ? OR al.table_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Get total count for pagination
try {
    $countQuery = "
        SELECT COUNT(*) as total
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $whereClause
    ";
    $totalRecords = $db->fetchRow($countQuery, $whereParams)['total'];
    $totalPages = ceil($totalRecords / $recordsPerPage);
} catch (Exception $e) {
    error_log("Audit logs count error: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 0;
}

// Get audit logs
try {
    $auditQuery = "
        SELECT 
            al.*,
            u.first_name,
            u.last_name,
            u.username,
            u.email
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT $recordsPerPage OFFSET $offset
    ";
    $auditLogs = $db->fetchAll($auditQuery, $whereParams);
} catch (Exception $e) {
    error_log("Audit logs fetch error: " . $e->getMessage());
    $auditLogs = [];
}

// Get filter options
try {
    // Get all users for filter
    $users = $db->fetchAll("SELECT user_id, first_name, last_name, username FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
    
    // Get unique actions
    $actions = $db->fetchAll("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL ORDER BY action");
    
    // Get unique table names
    $tableNames = $db->fetchAll("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name");
    
    // Get unique IP addresses (recent ones)
    $ipAddresses = $db->fetchAll("SELECT DISTINCT ip_address FROM audit_logs WHERE ip_address IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY ip_address LIMIT 50");
    
} catch (Exception $e) {
    error_log("Filter options error: " . $e->getMessage());
    $users = [];
    $actions = [];
    $tableNames = [];
    $ipAddresses = [];
}

// Get activity statistics
try {
    $stats = [
        'total_logs' => $db->fetchRow("SELECT COUNT(*) as count FROM audit_logs")['count'],
        'today_logs' => $db->fetchRow("SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = CURDATE()")['count'],
        'week_logs' => $db->fetchRow("SELECT COUNT(*) as count FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['count'],
        'unique_users' => $db->fetchRow("SELECT COUNT(DISTINCT user_id) as count FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['count']
    ];
} catch (Exception $e) {
    $stats = ['total_logs' => 0, 'today_logs' => 0, 'week_logs' => 0, 'unique_users' => 0];
}

// Export function
function exportAuditLogsCSV($db, $filters) {
    // Build WHERE clause for export (same as main query)
    $whereConditions = [];
    $whereParams = [];
    
    if ($filters['user_id']) {
        $whereConditions[] = "al.user_id = ?";
        $whereParams[] = $filters['user_id'];
    }
    
    if ($filters['action']) {
        $whereConditions[] = "al.action LIKE ?";
        $whereParams[] = '%' . $filters['action'] . '%';
    }
    
    if ($filters['table_name']) {
        $whereConditions[] = "al.table_name = ?";
        $whereParams[] = $filters['table_name'];
    }
    
    if ($filters['date_from']) {
        $whereConditions[] = "DATE(al.created_at) >= ?";
        $whereParams[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $whereConditions[] = "DATE(al.created_at) <= ?";
        $whereParams[] = $filters['date_to'];
    }
    
    if ($filters['ip_address']) {
        $whereConditions[] = "al.ip_address = ?";
        $whereParams[] = $filters['ip_address'];
    }
    
    if ($filters['search']) {
        $whereConditions[] = "(al.action LIKE ? OR al.table_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    $exportQuery = "
        SELECT 
            al.log_id,
            al.action,
            al.table_name,
            al.record_id,
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            u.username,
            u.email,
            al.ip_address,
            al.created_at
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT 10000
    ";
    
    try {
        $exportData = $db->fetchAll($exportQuery, $whereParams);
        
        $filename = 'audit_logs_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Log ID',
            'Action',
            'Table',
            'Record ID',
            'User Name',
            'Username',
            'Email',
            'IP Address',
            'Date & Time'
        ]);
        
        // CSV data
        foreach ($exportData as $log) {
            fputcsv($output, [
                $log['log_id'],
                $log['action'],
                $log['table_name'],
                $log['record_id'],
                $log['user_name'],
                $log['username'],
                $log['email'],
                $log['ip_address'],
                $log['created_at']
            ]);
        }
        
        fclose($output);
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Export failed: " . $e->getMessage();
    }
}

// Generate PDF report
function generateAuditLogsPDF($db, $filters) {
    // Build WHERE clause for export
    $whereConditions = [];
    $whereParams = [];
    
    if ($filters['user_id']) {
        $whereConditions[] = "al.user_id = ?";
        $whereParams[] = $filters['user_id'];
    }
    
    if ($filters['action']) {
        $whereConditions[] = "al.action LIKE ?";
        $whereParams[] = '%' . $filters['action'] . '%';
    }
    
    if ($filters['table_name']) {
        $whereConditions[] = "al.table_name = ?";
        $whereParams[] = $filters['table_name'];
    }
    
    if ($filters['date_from']) {
        $whereConditions[] = "DATE(al.created_at) >= ?";
        $whereParams[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $whereConditions[] = "DATE(al.created_at) <= ?";
        $whereParams[] = $filters['date_to'];
    }
    
    if ($filters['ip_address']) {
        $whereConditions[] = "al.ip_address = ?";
        $whereParams[] = $filters['ip_address'];
    }
    
    if ($filters['search']) {
        $whereConditions[] = "(al.action LIKE ? OR al.table_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
        $whereParams[] = $searchTerm;
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    $exportQuery = "
        SELECT 
            al.log_id,
            al.action,
            al.table_name,
            al.record_id,
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            u.username,
            u.email,
            al.ip_address,
            al.user_agent,
            al.created_at
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT 5000
    ";
    
    try {
        $exportData = $db->fetchAll($exportQuery, $whereParams);
        
        // Create HTML for PDF
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Audit Logs Report</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    font-size: 12px; 
                    margin: 20px;
                    color: #333;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 30px; 
                    border-bottom: 2px solid #667eea;
                    padding-bottom: 20px;
                }
                .header h1 { 
                    color: #667eea; 
                    margin: 0;
                    font-size: 24px;
                }
                .header p { 
                    margin: 5px 0; 
                    color: #666;
                }
                .filters {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                }
                .filters h3 {
                    margin: 0 0 10px 0;
                    color: #333;
                    font-size: 14px;
                }
                .filter-item {
                    display: inline-block;
                    margin-right: 20px;
                    margin-bottom: 5px;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-top: 20px;
                    font-size: 10px;
                }
                th { 
                    background-color: #667eea; 
                    color: white; 
                    padding: 8px; 
                    text-align: left;
                    font-weight: bold;
                }
                td { 
                    padding: 6px 8px; 
                    border-bottom: 1px solid #ddd;
                    vertical-align: top;
                }
                tr:nth-child(even) { 
                    background-color: #f9f9f9; 
                }
                .action-badge {
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 9px;
                    font-weight: bold;
                    text-transform: uppercase;
                }
                .action-create { background: #d4edda; color: #155724; }
                .action-update { background: #fff3cd; color: #856404; }
                .action-delete { background: #f8d7da; color: #721c24; }
                .action-login { background: #cce5ff; color: #004085; }
                .action-logout { background: #e2e3e5; color: #383d41; }
                .action-default { background: #e9ecef; color: #495057; }
                .footer {
                    margin-top: 30px;
                    text-align: center;
                    color: #666;
                    font-size: 10px;
                    border-top: 1px solid #ddd;
                    padding-top: 15px;
                }
                .summary {
                    background: #e8f4fd;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                }
                .summary h3 {
                    margin: 0 0 10px 0;
                    color: #0c5460;
                }
            </style>
        </head>
        <body>';
        
        // Header
        $html .= '
            <div class="header">
                <h1>' . APP_NAME . ' - Audit Logs Report</h1>
                <p>Generated on: ' . date('F d, Y \a\t H:i:s') . '</p>
                <p>Total Records: ' . count($exportData) . '</p>
            </div>';
        
        // Applied Filters
        if (array_filter($filters)) {
            $html .= '<div class="filters"><h3>Applied Filters:</h3>';
            if ($filters['user_id']) {
                $userInfo = $db->fetchRow("SELECT first_name, last_name FROM users WHERE user_id = ?", [$filters['user_id']]);
                $html .= '<div class="filter-item"><strong>User:</strong> ' . htmlspecialchars($userInfo['first_name'] . ' ' . $userInfo['last_name']) . '</div>';
            }
            if ($filters['action']) $html .= '<div class="filter-item"><strong>Action:</strong> ' . htmlspecialchars($filters['action']) . '</div>';
            if ($filters['table_name']) $html .= '<div class="filter-item"><strong>Table:</strong> ' . htmlspecialchars($filters['table_name']) . '</div>';
            if ($filters['ip_address']) $html .= '<div class="filter-item"><strong>IP:</strong> ' . htmlspecialchars($filters['ip_address']) . '</div>';
            if ($filters['date_from']) $html .= '<div class="filter-item"><strong>From:</strong> ' . htmlspecialchars($filters['date_from']) . '</div>';
            if ($filters['date_to']) $html .= '<div class="filter-item"><strong>To:</strong> ' . htmlspecialchars($filters['date_to']) . '</div>';
            if ($filters['search']) $html .= '<div class="filter-item"><strong>Search:</strong> ' . htmlspecialchars($filters['search']) . '</div>';
            $html .= '</div>';
        }
        
        // Summary Statistics
        $actions = [];
        $users = [];
        foreach ($exportData as $log) {
            $actions[$log['action']] = ($actions[$log['action']] ?? 0) + 1;
            if ($log['user_name']) {
                $users[$log['user_name']] = ($users[$log['user_name']] ?? 0) + 1;
            }
        }
        
        $html .= '<div class="summary">';
        $html .= '<h3>Summary Statistics</h3>';
        $html .= '<strong>Top Actions:</strong> ';
        arsort($actions);
        $topActions = array_slice($actions, 0, 5, true);
        $actionSummary = [];
        foreach ($topActions as $action => $count) {
            $actionSummary[] = $action . ' (' . $count . ')';
        }
        $html .= implode(', ', $actionSummary);
        $html .= '<br><strong>Unique Users:</strong> ' . count($users);
        $html .= '</div>';
        
        // Table
        $html .= '
            <table>
                <thead>
                    <tr>
                        <th width="8%">ID</th>
                        <th width="15%">Action</th>
                        <th width="12%">Table</th>
                        <th width="20%">User</th>
                        <th width="15%">IP Address</th>
                        <th width="20%">Date & Time</th>
                        <th width="10%">Record ID</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($exportData as $log) {
            $actionClass = 'action-default';
            $action = strtolower($log['action']);
            if (strpos($action, 'create') !== false) $actionClass = 'action-create';
            elseif (strpos($action, 'update') !== false) $actionClass = 'action-update';
            elseif (strpos($action, 'delete') !== false) $actionClass = 'action-delete';
            elseif (strpos($action, 'login') !== false) $actionClass = 'action-login';
            elseif (strpos($action, 'logout') !== false) $actionClass = 'action-logout';
            
            $html .= '<tr>
                <td>' . htmlspecialchars($log['log_id']) . '</td>
                <td><span class="action-badge ' . $actionClass . '">' . htmlspecialchars($log['action']) . '</span></td>
                <td>' . htmlspecialchars($log['table_name'] ?: '-') . '</td>
                <td>' . htmlspecialchars($log['user_name'] ?: 'Unknown') . '<br><small>' . htmlspecialchars($log['username'] ?: '') . '</small></td>
                <td>' . htmlspecialchars($log['ip_address'] ?: '-') . '</td>
                <td>' . date('M d, Y H:i:s', strtotime($log['created_at'])) . '</td>
                <td>' . htmlspecialchars($log['record_id'] ?: '-') . '</td>
            </tr>';
        }
        
        $html .= '</tbody></table>';
        
        // Footer
        $html .= '
            <div class="footer">
                <p>This report was generated automatically by ' . APP_NAME . ' system.</p>
                <p>For questions about this report, please contact your system administrator.</p>
            </div>
        </body>
        </html>';
        
        // Generate PDF filename
        $filename = 'audit_logs_' . date('Y-m-d_H-i-s') . '.pdf';
        
        // Use browser's PDF generation (wkhtmltopdf style)
        header('Content-Type: text/html');
        echo $html;
        
        // Add JavaScript to trigger print dialog
        echo '<script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 1000);
            }
        </script>';
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "PDF generation failed: " . $e->getMessage();
    }
}

// Show printable version
function showPrintableAuditLogs($db, $filters) {
    // Same logic as PDF but optimized for browser printing
    generateAuditLogsPDF($db, $filters);
}

// Get flash messages once
$flashMessages = getFlashMessages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - <?php echo APP_NAME; ?></title>
    
    <!-- Icons and Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #00d2ff 0%, #3a7bd5 100%);
            --warning-gradient: linear-gradient(135deg, #feca57 0%, #ff9ff3 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            --light-gradient: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            --shadow-soft: 0 15px 35px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #2d3748;
        }
        
        /* Top Navigation */
        .top-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: #2d3748;
            padding: 15px 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .brand {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .back-btn {
            background: var(--primary-gradient);
            color: white;
            padding: 8px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            color: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 18px;
        }
        
        /* Main Container */
        .main-container {
            margin-top: 80px;
            padding: 30px;
            max-width: 1600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-soft);
            text-align: center;
        }
        
        .page-title {
            font-size: 3rem;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }
        
        .page-subtitle {
            font-size: 1.2rem;
            color: #6c757d;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Flash Messages */
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 30px;
            font-weight: 500;
            box-shadow: var(--shadow-soft);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--shadow-soft);
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-card.primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .stat-card.success {
            background: var(--success-gradient);
            color: white;
        }
        
        .stat-card.info {
            background: var(--info-gradient);
            color: white;
        }
        
        .stat-card.warning {
            background: var(--warning-gradient);
            color: #2d3748;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-title {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .stat-icon {
            font-size: 32px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        
        /* Cards */
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            border: none;
        }
        
        .card-header {
            background: var(--light-gradient);
            border-bottom: 1px solid #e2e8f0;
            padding: 25px;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 30px;
        }
        
        /* Filters */
        .filters-section {
            background: var(--light-gradient);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .form-control, .form-select {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: var(--transition);
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        /* Buttons */
        .btn {
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-success {
            background: var(--success-gradient);
            color: white;
        }
        
        .btn-outline-secondary {
            background: transparent;
            color: #6c757d;
            border: 2px solid #6c757d;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 0.8rem;
        }
        
        /* Tables */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background: #f7fafc;
            border: none;
            font-weight: 600;
            color: #2d3748;
            padding: 15px;
            font-size: 0.9rem;
        }
        
        .table td {
            border-color: #e2e8f0;
            vertical-align: middle;
            padding: 15px;
            font-size: 0.9rem;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Action Badges */
        .action-badge {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .action-badge.create { background: #d4edda; color: #155724; }
        .action-badge.update { background: #fff3cd; color: #856404; }
        .action-badge.delete { background: #f8d7da; color: #721c24; }
        .action-badge.login { background: #cce5ff; color: #004085; }
        .action-badge.logout { background: #e2e3e5; color: #383d41; }
        .action-badge.default { background: #e9ecef; color: #495057; }
        
        /* Pagination */
        .pagination-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
            box-shadow: var(--shadow-soft);
            display: flex;
            justify-content: between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-link {
            color: #667eea;
            border: none;
            padding: 8px 16px;
            margin: 0 2px;
            border-radius: 8px;
            transition: var(--transition);
        }
        
        .page-link:hover {
            background-color: #667eea;
            color: white;
            transform: translateY(-1px);
        }
        
        .page-item.active .page-link {
            background: var(--primary-gradient);
            border: none;
        }
        
        /* Details Modal */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-hover);
        }
        
        .modal-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        .modal-title {
            font-weight: 700;
        }
        
        .json-display {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h5 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .main-container {
                padding: 15px;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .table-responsive {
                font-size: 0.8rem;
            }
            
            .pagination-container {
                flex-direction: column;
                text-align: center;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Loading States */
        .loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Keyboard shortcuts styling */
        kbd {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.2), 0 2px 0 0 rgba(255, 255, 255, 0.7) inset;
            color: #333;
            display: inline-block;
            font-size: 0.8em;
            font-weight: 700;
            line-height: 1;
            padding: 2px 4px;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <a href="../index.php" class="brand">
                <i class="fas fa-clipboard-list"></i>
                <?php echo APP_NAME; ?> Audit Logs
            </a>
        </div>
        
        <div class="user-info">
            <a href="../index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
            <div class="user-avatar">
                <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
            </div>
            <div>
                <div style="font-weight: 600;"><?php echo htmlspecialchars($userDisplayName); ?></div>
                <div style="font-size: 0.8rem; color: #6c757d;">Administrator</div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1 class="page-title">System Audit Logs</h1>
            <p class="page-subtitle">Monitor and track all user activities and system changes for security and compliance.</p>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessages && isset($flashMessages['type']) && isset($flashMessages['message'])): ?>
            <div class="alert alert-<?php echo $flashMessages['type'] === 'error' ? 'danger' : $flashMessages['type']; ?> fade-in-up">
                <i class="fas fa-<?php echo $flashMessages['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($flashMessages['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid fade-in-up">
            <div class="stat-card primary">
                <div class="stat-header">
                    <div class="stat-title">Total Activity</div>
                    <div class="stat-icon">
                        <i class="fas fa-list"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_logs']); ?></div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-title">Today's Activity</div>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['today_logs']); ?></div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-title">This Week</div>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['week_logs']); ?></div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-title">Active Users</div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['unique_users']); ?></div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="card fade-in-up">
            <div class="card-header">
                <h5 class="card-title">
                    <i class="fas fa-filter"></i>
                    Search & Filter Logs
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm">
                    <div class="filters-section">
                        <!-- Search Bar -->
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Search by action, table, user name, or username... (Ctrl+F to focus)" 
                                       value="<?php echo htmlspecialchars($filters['search'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                        Search
                                    </button>
                                    <a href="audit_logs.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                        Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Tips -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div style="background: #e8f4fd; padding: 10px; border-radius: 8px; font-size: 0.85rem; color: #0c5460;">
                                    <i class="fas fa-keyboard me-1"></i>
                                    <strong>Quick Tips:</strong> 
                                    Use <kbd>Ctrl+F</kbd> to focus search, <kbd>Ctrl+P</kbd> to print, <kbd>Ctrl+E</kbd> to export CSV
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filter Options -->
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label class="filter-label">User</label>
                                <select class="form-select" name="user_id">
                                    <option value="">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>" 
                                                <?php echo ($filters['user_id'] == $user['user_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['username'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Action</label>
                                <select class="form-select" name="action">
                                    <option value="">All Actions</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?php echo htmlspecialchars($action['action']); ?>" 
                                                <?php echo ($filters['action'] === $action['action']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($action['action']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Table</label>
                                <select class="form-select" name="table_name">
                                    <option value="">All Tables</option>
                                    <?php foreach ($tableNames as $table): ?>
                                        <option value="<?php echo htmlspecialchars($table['table_name']); ?>" 
                                                <?php echo ($filters['table_name'] === $table['table_name']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($table['table_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">IP Address</label>
                                <select class="form-select" name="ip_address">
                                    <option value="">All IPs</option>
                                    <?php foreach ($ipAddresses as $ip): ?>
                                        <option value="<?php echo htmlspecialchars($ip['ip_address']); ?>" 
                                                <?php echo ($filters['ip_address'] === $ip['ip_address']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ip['ip_address']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Date From</label>
                                <input type="date" class="form-control" name="date_from" 
                                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Date To</label>
                                <input type="date" class="form-control" name="date_to" 
                                       value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Audit Logs Table -->
        <div class="card fade-in-up">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title">
                        <i class="fas fa-list"></i>
                        Activity Logs (<?php echo number_format($totalRecords); ?> records)
                    </h5>
                    <?php if (!empty($auditLogs)): ?>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="text-muted small me-2">Export:</span>
                            <a href="?export=csv<?php echo $_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''; ?>" 
                               class="btn btn-success btn-sm" title="Download as spreadsheet file">
                                <i class="fas fa-file-csv"></i>
                                CSV
                            </a>
                            <a href="?export=pdf<?php echo $_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''; ?>" 
                               class="btn btn-danger btn-sm" target="_blank" title="Generate professional PDF report">
                                <i class="fas fa-file-pdf"></i>
                                PDF
                            </a>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="printAuditLogs()" 
                                    title="Open print-friendly version">
                                <i class="fas fa-print"></i>
                                Print
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($auditLogs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h5>No Activity Found</h5>
                        <p>No audit logs match your current search criteria. Try adjusting your filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th width="80">ID</th>
                                    <th width="120">Action</th>
                                    <th width="100">Table</th>
                                    <th width="150">User</th>
                                    <th width="120">IP Address</th>
                                    <th width="150">Date & Time</th>
                                    <th width="80">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['log_id']; ?></td>
                                        <td>
                                            <span class="action-badge <?php 
                                                $action = strtolower($log['action']);
                                                if (strpos($action, 'create') !== false) echo 'create';
                                                elseif (strpos($action, 'update') !== false) echo 'update';
                                                elseif (strpos($action, 'delete') !== false) echo 'delete';
                                                elseif (strpos($action, 'login') !== false) echo 'login';
                                                elseif (strpos($action, 'logout') !== false) echo 'logout';
                                                else echo 'default';
                                            ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['table_name'] ?? '-'); ?></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')); ?></strong>
                                                <div style="font-size: 0.8rem; color: #6c757d;">
                                                    @<?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                                        <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td>
                                            <?php if ($log['old_values'] || $log['new_values']): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                                        onclick="showLogDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-container fade-in-up">
                <div class="pagination-info">
                    Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $recordsPerPage, $totalRecords)); ?> 
                    of <?php echo number_format($totalRecords); ?> records
                </div>
                
                <nav>
                    <ul class="pagination">
                        <?php if ($currentPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $currentPage - 1; ?><?php echo $_SERVER['QUERY_STRING'] ? '&' . http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) : ''; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <li class="page-item <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $_SERVER['QUERY_STRING'] ? '&' . http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $currentPage + 1; ?><?php echo $_SERVER['QUERY_STRING'] ? '&' . http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) : ''; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <!-- Log Details Modal -->
    <div class="modal fade" id="logDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>
                        Audit Log Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="logDetailsContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show log details in modal
        function showLogDetails(log) {
            const modal = new bootstrap.Modal(document.getElementById('logDetailsModal'));
            const content = document.getElementById('logDetailsContent');
            
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Basic Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Log ID:</strong></td><td>${log.log_id}</td></tr>
                            <tr><td><strong>Action:</strong></td><td>${log.action}</td></tr>
                            <tr><td><strong>Table:</strong></td><td>${log.table_name || '-'}</td></tr>
                            <tr><td><strong>Record ID:</strong></td><td>${log.record_id || '-'}</td></tr>
                            <tr><td><strong>Date:</strong></td><td>${new Date(log.created_at).toLocaleString()}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>User Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Name:</strong></td><td>${(log.first_name || '') + ' ' + (log.last_name || '')}</td></tr>
                            <tr><td><strong>Username:</strong></td><td>${log.username || 'Unknown'}</td></tr>
                            <tr><td><strong>Email:</strong></td><td>${log.email || '-'}</td></tr>
                            <tr><td><strong>IP Address:</strong></td><td>${log.ip_address || '-'}</td></tr>
                        </table>
                    </div>
                </div>
            `;
            
            if (log.old_values) {
                html += `
                    <div class="mt-3">
                        <h6>Previous Values</h6>
                        <div class="json-display">${formatJSON(log.old_values)}</div>
                    </div>
                `;
            }
            
            if (log.new_values) {
                html += `
                    <div class="mt-3">
                        <h6>New Values</h6>
                        <div class="json-display">${formatJSON(log.new_values)}</div>
                    </div>
                `;
            }
            
            if (log.user_agent) {
                html += `
                    <div class="mt-3">
                        <h6>User Agent</h6>
                        <div class="json-display" style="max-height: 100px;">${log.user_agent}</div>
                    </div>
                `;
            }
            
            content.innerHTML = html;
            modal.show();
        }
        
        // Format JSON for display
        function formatJSON(jsonString) {
            try {
                const obj = JSON.parse(jsonString);
                return JSON.stringify(obj, null, 2);
            } catch (e) {
                return jsonString;
            }
        }
        
        // Auto-submit form when filters change
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            const filterInputs = filterForm.querySelectorAll('select, input[type="date"]');
            
            filterInputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Remove page parameter when filtering
                    const pageInput = filterForm.querySelector('input[name="page"]');
                    if (pageInput) {
                        pageInput.remove();
                    }
                    
                    // Auto-submit for non-search fields
                    if (this.name !== 'search') {
                        filterForm.submit();
                    }
                });
            });
            
            // Auto-dismiss alerts
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 5000);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
                showTooltip('Search focused (Ctrl+F)');
            }
            // Ctrl/Cmd + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printAuditLogs();
            }
            // Ctrl/Cmd + E to export CSV
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                const csvLink = document.querySelector('a[href*="export=csv"]');
                if (csvLink) {
                    window.location.href = csvLink.href;
                    showTooltip('Exporting CSV (Ctrl+E)');
                }
            }
        });
        
        // Show tooltip notification
        function showTooltip(message) {
            const tooltip = document.createElement('div');
            tooltip.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #333;
                color: white;
                padding: 10px 15px;
                border-radius: 5px;
                font-size: 12px;
                z-index: 9999;
                opacity: 0;
                transition: opacity 0.3s;
            `;
            tooltip.textContent = message;
            document.body.appendChild(tooltip);
            
            setTimeout(() => tooltip.style.opacity = '1', 10);
            setTimeout(() => {
                tooltip.style.opacity = '0';
                setTimeout(() => document.body.removeChild(tooltip), 300);
            }, 2000);
        }
        
        // Print function
        function printAuditLogs() {
            const currentUrl = window.location.href;
            const printUrl = currentUrl + (currentUrl.includes('?') ? '&' : '?') + 'export=print';
            
            // Open print window
            const printWindow = window.open(printUrl, '_blank', 'width=1200,height=800');
            
            // Focus the print window
            if (printWindow) {
                printWindow.focus();
                showTooltip('Opening print window...');
            } else {
                alert('Please allow popups to use the print feature, or use the PDF export option.');
            }
        }
    </script>
</body>
</html>