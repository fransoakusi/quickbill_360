-- QUICKBILL 305 Database Backup
-- Generated on: 2025-07-12 18:32:25
-- Backup Type: Full

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


-- Table structure for table `audit_logs`
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table_name` (`table_name`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_audit_logs_user_date` (`user_id`,`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `audit_logs`
INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
('1', '1', 'User Login', '', NULL, NULL, '{\"user_id\":1,\"username\":\"admin\",\"ip_address\":\"::1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 02:33:15'),
('2', '1', 'Password Changed', '', NULL, NULL, '{\"user_id\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 02:33:51'),
('3', '1', 'User Logout', '', NULL, NULL, '{\"user_id\":1,\"session_duration\":17002}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 07:16:37'),
('4', '1', 'User Login', '', NULL, NULL, '{\"user_id\":1,\"username\":\"admin\",\"ip_address\":\"::1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 07:17:39'),
('5', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 17:15:56'),
('6', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-05 17:19:40'),
('7', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 16:22:51'),
('8', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 17:23:08'),
('9', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 17:23:26'),
('10', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 18:27:58'),
('11', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 18:28:33'),
('12', '1', 'CREATE_USER', 'users', '3', NULL, '{\"username\":\"Joojo\",\"email\":\"kwadwomegas@gmail.com\",\"role_id\":1,\"first_name\":\"Joojo\",\"last_name\":\"Megas\",\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 19:03:22'),
('13', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-09 19:07:33'),
('14', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 03:10:46'),
('15', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 04:25:10'),
('16', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 04:25:28'),
('17', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 08:58:55'),
('18', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 10:10:41'),
('19', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 10:11:07'),
('20', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 10:12:05'),
('21', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 10:12:20'),
('22', '3', 'PASSWORD_CHANGED', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 10:13:32'),
('23', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 13:16:52'),
('24', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 13:17:40'),
('25', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 14:16:10'),
('26', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 14:16:24'),
('27', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-10 14:47:11'),
('28', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-10 15:49:42'),
('29', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-10 15:52:51'),
('30', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 17:44:32'),
('31', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 17:47:32'),
('32', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 17:47:46'),
('33', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 18:56:11'),
('34', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 18:57:12'),
('35', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 19:59:30'),
('36', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-10 20:00:07'),
('37', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-10 20:02:07'),
('38', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 01:03:06'),
('39', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 02:07:26'),
('40', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 02:08:10'),
('41', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-11 02:30:18'),
('42', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 09:16:12'),
('43', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 10:16:41'),
('44', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 10:17:14'),
('45', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 11:04:42'),
('46', '1', 'BILL_ADJUSTED', 'bills', '4', '{\"field\":\"current_bill\",\"old_value\":1000,\"old_amount_payable\":1000}', '{\"field\":\"current_bill\",\"new_value\":1100,\"new_amount_payable\":1100,\"adjustment_method\":\"Fixed Amount\",\"adjustment_value\":100,\"reason\":\"Due to the operation of the business\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 11:06:22'),
('47', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 11:20:11'),
('48', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 11:36:34'),
('49', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 11:52:24'),
('50', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 11:52:39'),
('51', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 15:20:04'),
('52', '1', 'CREATE_USER', 'users', '4', NULL, '{\"username\":\"Kusi\",\"email\":\"kusi@gmail.com\",\"role_id\":5,\"first_name\":\"Kusi\",\"last_name\":\"France\",\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 15:21:00'),
('53', '4', 'USER_LOGIN', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 15:21:40'),
('54', '4', 'PASSWORD_CHANGED', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 15:22:17'),
('55', '4', 'USER_LOGOUT', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 16:32:47'),
('56', '4', 'USER_LOGIN', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 16:33:07'),
('57', '4', 'USER_LOGOUT', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 17:38:12'),
('58', '4', 'USER_LOGIN', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 17:38:38'),
('59', '4', 'USER_LOGOUT', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 18:41:20'),
('60', '4', 'USER_LOGIN', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 18:41:53'),
('61', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-11 18:55:11'),
('62', '4', 'USER_LOGOUT', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-11 20:17:30'),
('63', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 05:11:33'),
('64', '1', 'CREATE_USER', 'users', '5', NULL, '{\"username\":\"Aseye\",\"email\":\"aseyeabledoo@gmail.com\",\"role_id\":4,\"first_name\":\"Aseye\",\"last_name\":\"Abledu\",\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 05:12:52'),
('65', '5', 'USER_LOGIN', 'users', '5', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 05:13:38'),
('66', '5', 'PASSWORD_CHANGED', 'users', '5', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 05:14:00'),
('69', '5', 'USER_LOGOUT', 'users', '5', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 06:15:06'),
('70', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 07:16:32'),
('71', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 07:17:32'),
('72', '1', 'CREATE_USER', 'users', '6', NULL, '{\"username\":\"David\",\"email\":\"kabtechconsulting@gmail.com\",\"role_id\":3,\"first_name\":\"David\",\"last_name\":\"Lomko\",\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 07:18:33'),
('73', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 07:20:10'),
('74', '6', 'PASSWORD_CHANGED', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 07:20:34'),
('75', '6', 'USER_LOGOUT', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 08:21:22'),
('76', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 08:21:52'),
('77', '6', 'USER_LOGOUT', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 09:26:14'),
('78', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 09:26:46'),
('79', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 09:37:48'),
('80', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 09:38:07'),
('81', '1', 'BILLS_GENERATED', 'bills', NULL, NULL, '{\"generation_type\":\"specific\",\"billing_year\":2025,\"business_bills\":1,\"property_bills\":0,\"skipped_records\":0,\"total_generated\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 09:39:12'),
('82', '6', 'USER_LOGOUT', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 10:35:57'),
('83', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 10:36:33'),
('84', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 10:40:12'),
('85', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 10:40:27'),
('86', '6', 'USER_LOGOUT', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 11:40:56'),
('87', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 11:41:20'),
('88', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 11:46:21'),
('89', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 13:20:39'),
('90', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 14:08:59'),
('91', '3', '3', 'LIFT_SYSTEM_RESTRICTION', '0', '2', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 14:41:52'),
('92', '1', 'USER_LOGOUT', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 14:57:49'),
('93', '1', 'USER_LOGIN', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 14:58:02'),
('94', '3', 'USER_LOGOUT', 'users', '3', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 15:40:15'),
('95', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 15:40:47'),
('96', '6', 'USER_LOGIN', 'users', '6', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 15:42:07'),
('97', '5', 'USER_LOGIN', 'users', '5', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 16:31:21'),
('98', '5', 'USER_LOGOUT', 'users', '5', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 17:43:09'),
('99', '3', 'USER_LOGIN', 'users', '3', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', '2025-07-12 17:43:22'),
('101', '4', 'USER_LOGIN', 'users', '4', NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0', '2025-07-12 18:27:30');


-- Table structure for table `backup_logs`
DROP TABLE IF EXISTS `backup_logs`;
CREATE TABLE `backup_logs` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_type` enum('Full','Incremental') NOT NULL,
  `backup_path` varchar(255) NOT NULL,
  `backup_size` bigint(20) DEFAULT NULL,
  `status` enum('In Progress','Completed','Failed') DEFAULT 'In Progress',
  `started_by` int(11) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `started_by` (`started_by`),
  KEY `idx_backup_type` (`backup_type`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`),
  CONSTRAINT `backup_logs_ibfk_1` FOREIGN KEY (`started_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `backup_logs`
INSERT INTO `backup_logs` (`backup_id`, `backup_type`, `backup_path`, `backup_size`, `status`, `started_by`, `started_at`, `completed_at`, `error_message`) VALUES
('1', 'Full', 'C:\\xampp\\htdocs\\quickbill_305/storage/backups/quickbill_305_backup_2025-07-12_18-32-25.sql', NULL, 'In Progress', '3', '2025-07-12 18:32:25', NULL, NULL);


-- Table structure for table `bill_adjustments`
DROP TABLE IF EXISTS `bill_adjustments`;
CREATE TABLE `bill_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_type` enum('Single','Bulk') NOT NULL,
  `target_type` enum('Business','Property') NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `criteria` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`criteria`)),
  `adjustment_method` enum('Fixed Amount','Percentage') NOT NULL,
  `adjustment_value` decimal(10,2) NOT NULL,
  `old_amount` decimal(10,2) DEFAULT NULL,
  `new_amount` decimal(10,2) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `applied_by` int(11) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  KEY `applied_by` (`applied_by`),
  KEY `idx_adjustment_type` (`adjustment_type`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_applied_at` (`applied_at`),
  CONSTRAINT `bill_adjustments_ibfk_1` FOREIGN KEY (`applied_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `bill_adjustments`
INSERT INTO `bill_adjustments` (`adjustment_id`, `adjustment_type`, `target_type`, `target_id`, `criteria`, `adjustment_method`, `adjustment_value`, `old_amount`, `new_amount`, `reason`, `applied_by`, `applied_at`) VALUES
('1', 'Single', 'Business', '1', NULL, 'Fixed Amount', '100.00', '1000.00', '1100.00', 'Due to the operation of the business', '1', '2025-07-11 11:06:22');


-- Table structure for table `bills`
DROP TABLE IF EXISTS `bills`;
CREATE TABLE `bills` (
  `bill_id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_number` varchar(20) NOT NULL,
  `bill_type` enum('Business','Property') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `billing_year` year(4) NOT NULL,
  `old_bill` decimal(10,2) DEFAULT 0.00,
  `previous_payments` decimal(10,2) DEFAULT 0.00,
  `arrears` decimal(10,2) DEFAULT 0.00,
  `current_bill` decimal(10,2) NOT NULL,
  `amount_payable` decimal(10,2) NOT NULL,
  `qr_code` text DEFAULT NULL,
  `status` enum('Pending','Paid','Partially Paid','Overdue') DEFAULT 'Pending',
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  PRIMARY KEY (`bill_id`),
  UNIQUE KEY `bill_number` (`bill_number`),
  KEY `generated_by` (`generated_by`),
  KEY `idx_bill_number` (`bill_number`),
  KEY `idx_bill_type_ref` (`bill_type`,`reference_id`),
  KEY `idx_billing_year` (`billing_year`),
  KEY `idx_status` (`status`),
  KEY `idx_bills_due_date` (`due_date`),
  CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `bills`
INSERT INTO `bills` (`bill_id`, `bill_number`, `bill_type`, `reference_id`, `billing_year`, `old_bill`, `previous_payments`, `arrears`, `current_bill`, `amount_payable`, `qr_code`, `status`, `generated_by`, `generated_at`, `due_date`) VALUES
('1', 'BIL-BIZ2025-51A36E98', 'Business', '2', '2025', '0.00', '0.00', '0.00', '1000.00', '1000.00', NULL, 'Pending', '3', '2025-07-10 19:31:38', NULL),
('2', 'BILL2025B000001', 'Business', '1', '2025', '0.00', '0.00', '0.00', '1000.00', '1000.00', NULL, 'Partially Paid', '3', '2025-07-11 01:20:30', NULL),
('3', 'BILL2025P000001', 'Property', '1', '2025', '0.00', '0.00', '0.00', '225.00', '225.00', NULL, 'Partially Paid', '3', '2025-07-11 01:20:30', NULL),
('4', 'BILL2024B000001', 'Business', '1', '2024', '0.00', '0.00', '0.00', '1100.00', '1100.00', NULL, 'Pending', '3', '2025-07-11 01:53:19', NULL),
('5', 'BILL2025B000005', 'Business', '5', '2025', '0.00', '0.00', '0.00', '1200.00', '1200.00', NULL, 'Pending', '1', '2025-07-12 09:39:12', NULL);


-- Table structure for table `business_fee_structure`
DROP TABLE IF EXISTS `business_fee_structure`;
CREATE TABLE `business_fee_structure` (
  `fee_id` int(11) NOT NULL AUTO_INCREMENT,
  `business_type` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL,
  `fee_amount` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_id`),
  UNIQUE KEY `unique_business_type_category` (`business_type`,`category`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `business_fee_structure_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `business_fee_structure`
INSERT INTO `business_fee_structure` (`fee_id`, `business_type`, `category`, `fee_amount`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
('1', 'Restaurant', 'Small Scale', '500.00', '1', '1', '2025-07-04 18:57:35', '2025-07-10 14:52:25'),
('2', 'Restaurant', 'Medium Scale', '1000.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('3', 'Restaurant', 'Large Scale', '2000.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('4', 'Shop', 'Small Scale', '300.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('5', 'Shop', 'Medium Scale', '600.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('6', 'Shop', 'Large Scale', '1200.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('7', 'Saloon', 'Large', '100.00', '1', '3', '2025-07-10 14:51:40', '2025-07-10 14:51:40');


-- Table structure for table `business_summary`
DROP TABLE IF EXISTS `business_summary`;
;

-- Dumping data for table `business_summary`
INSERT INTO `business_summary` (`business_id`, `account_number`, `business_name`, `owner_name`, `business_type`, `category`, `telephone`, `exact_location`, `amount_payable`, `status`, `zone_name`, `sub_zone_name`, `payment_status`) VALUES
('1', 'BIZ000001', 'KabTech Consulting', 'Afful Bismark', 'Restaurant', 'Medium Scale', '', 'GPS: 5.593020, -0.077100', '700.00', 'Active', 'Central Zone', 'Government Area', 'Defaulter'),
('2', 'BIZ000002', 'Kwabena Ewusi Enterprise', 'Zayne Ewusi', 'Restaurant', 'Medium Scale', '0567823456', 'GPS: 5.593020, -0.077100', '1000.00', 'Active', 'North Zone', 'Residential A', 'Defaulter'),
('3', 'BIZ000003', 'Bel Aqua', 'Aseye Abledu', 'Saloon', 'Large', '0000000000', 'Battor Old District Assembly', '100.00', 'Active', 'North Zone', 'Residential A', 'Defaulter'),
('4', 'BIZ000004', 'Media General', 'Alfred Ocansey', 'Saloon', 'Large', '0545041424', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', '100.00', 'Active', 'North Zone', 'Residential A', 'Defaulter'),
('5', 'BIZ000005', 'Alinco Filling Station', 'Alinco', 'Shop', 'Large Scale', '', 'Mepe', '1200.00', 'Active', 'South Zone', 'Industrial Area', 'Defaulter');


-- Table structure for table `businesses`
DROP TABLE IF EXISTS `businesses`;
CREATE TABLE `businesses` (
  `business_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_number` varchar(20) NOT NULL,
  `business_name` varchar(200) NOT NULL,
  `owner_name` varchar(100) NOT NULL,
  `business_type` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `exact_location` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `old_bill` decimal(10,2) DEFAULT 0.00,
  `previous_payments` decimal(10,2) DEFAULT 0.00,
  `arrears` decimal(10,2) DEFAULT 0.00,
  `current_bill` decimal(10,2) DEFAULT 0.00,
  `amount_payable` decimal(10,2) DEFAULT 0.00,
  `batch` varchar(50) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  `zone_id` int(11) DEFAULT NULL,
  `sub_zone_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`business_id`),
  UNIQUE KEY `account_number` (`account_number`),
  KEY `sub_zone_id` (`sub_zone_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_account_number` (`account_number`),
  KEY `idx_business_type` (`business_type`),
  KEY `idx_zone` (`zone_id`),
  KEY `idx_status` (`status`),
  KEY `idx_businesses_payable` (`amount_payable`),
  CONSTRAINT `businesses_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`zone_id`),
  CONSTRAINT `businesses_ibfk_2` FOREIGN KEY (`sub_zone_id`) REFERENCES `sub_zones` (`sub_zone_id`),
  CONSTRAINT `businesses_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `businesses`
INSERT INTO `businesses` (`business_id`, `account_number`, `business_name`, `owner_name`, `business_type`, `category`, `telephone`, `exact_location`, `latitude`, `longitude`, `old_bill`, `previous_payments`, `arrears`, `current_bill`, `amount_payable`, `batch`, `status`, `zone_id`, `sub_zone_id`, `created_by`, `created_at`, `updated_at`) VALUES
('1', 'BIZ000001', 'KabTech Consulting', 'Afful Bismark', 'Restaurant', 'Medium Scale', '', 'GPS: 5.593020, -0.077100', '5.59302000', '-0.07710000', '0.00', '400.00', '0.00', '1100.00', '700.00', '', 'Active', '1', '2', '1', '2025-07-10 03:16:20', '2025-07-12 05:33:12'),
('2', 'BIZ000002', 'Kwabena Ewusi Enterprise', 'Zayne Ewusi', 'Restaurant', 'Medium Scale', '0567823456', 'GPS: 5.593020, -0.077100', '5.59302000', '-0.07710000', '0.00', '0.00', '0.00', '1000.00', '1000.00', '', 'Active', '2', '3', '1', '2025-07-10 09:00:51', '2025-07-10 09:00:51'),
('3', 'BIZ000003', 'Bel Aqua', 'Aseye Abledu', 'Saloon', 'Large', '0000000000', 'Battor Old District Assembly', '5.59302000', '-0.07710000', '0.00', '0.00', '0.00', '100.00', '100.00', '', 'Active', '2', '3', NULL, '2025-07-11 16:01:37', '2025-07-11 16:01:37'),
('4', 'BIZ000004', 'Media General', 'Alfred Ocansey', 'Saloon', 'Large', '0545041424', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', '5.59302000', '-0.07710000', '0.00', '0.00', '0.00', '100.00', '100.00', '', 'Active', '2', '3', NULL, '2025-07-12 09:28:28', '2025-07-12 09:28:28'),
('5', 'BIZ000005', 'Alinco Filling Station', 'Alinco', 'Shop', 'Large Scale', '', 'Mepe', '5.59302000', '-0.07710000', '0.00', '0.00', '0.00', '1200.00', '1200.00', '', 'Active', '3', '4', NULL, '2025-07-12 09:32:35', '2025-07-12 09:32:35');


-- Table structure for table `notifications`
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_type` enum('User','Business','Property') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `notification_type` enum('SMS','System','Email') NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('Pending','Sent','Failed','Read') DEFAULT 'Pending',
  `sent_by` int(11) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `sent_by` (`sent_by`),
  KEY `idx_recipient` (`recipient_type`,`recipient_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`sent_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `notifications`

-- Table structure for table `payment_summary`
DROP TABLE IF EXISTS `payment_summary`;
;

-- Dumping data for table `payment_summary`
INSERT INTO `payment_summary` (`payment_id`, `payment_reference`, `amount_paid`, `payment_method`, `payment_status`, `payment_date`, `bill_number`, `bill_type`, `payer_name`) VALUES
('1', 'PAY2025026847', '400.00', 'Cash', 'Successful', '2025-07-12 05:33:12', 'BILL2025B000001', 'Business', 'KabTech Consulting'),
('2', 'PAY2025517858', '150.00', 'Mobile Money', 'Successful', '2025-07-12 05:46:12', 'BILL2025P000001', 'Property', 'Yaw Kusi');


-- Table structure for table `payments`
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_reference` varchar(50) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` enum('Mobile Money','Cash','Bank Transfer','Online') NOT NULL,
  `payment_channel` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `paystack_reference` varchar(100) DEFAULT NULL,
  `payment_status` enum('Pending','Successful','Failed','Cancelled') DEFAULT 'Pending',
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `receipt_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payment_reference` (`payment_reference`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_payment_ref` (`payment_reference`),
  KEY `idx_bill_id` (`bill_id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_payments_date_status` (`payment_date`,`payment_status`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payments`
INSERT INTO `payments` (`payment_id`, `payment_reference`, `bill_id`, `amount_paid`, `payment_method`, `payment_channel`, `transaction_id`, `paystack_reference`, `payment_status`, `payment_date`, `processed_by`, `notes`, `receipt_url`) VALUES
('1', 'PAY2025026847', '2', '400.00', 'Cash', 'Cash Payment', '', NULL, 'Successful', '2025-07-12 05:33:12', '5', 'Part Payment', NULL),
('2', 'PAY2025517858', '3', '150.00', 'Mobile Money', 'MTN', '', NULL, 'Successful', '2025-07-12 05:46:12', '5', 'Part Payment', NULL);


-- Table structure for table `properties`
DROP TABLE IF EXISTS `properties`;
CREATE TABLE `properties` (
  `property_id` int(11) NOT NULL AUTO_INCREMENT,
  `property_number` varchar(20) NOT NULL,
  `owner_name` varchar(100) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `location` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `structure` varchar(100) NOT NULL,
  `ownership_type` enum('Self','Family','Corporate','Others') DEFAULT 'Self',
  `property_type` enum('Modern','Traditional') DEFAULT 'Modern',
  `number_of_rooms` int(11) NOT NULL,
  `property_use` enum('Commercial','Residential') NOT NULL,
  `old_bill` decimal(10,2) DEFAULT 0.00,
  `previous_payments` decimal(10,2) DEFAULT 0.00,
  `arrears` decimal(10,2) DEFAULT 0.00,
  `current_bill` decimal(10,2) DEFAULT 0.00,
  `amount_payable` decimal(10,2) DEFAULT 0.00,
  `batch` varchar(50) DEFAULT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`property_id`),
  UNIQUE KEY `property_number` (`property_number`),
  KEY `created_by` (`created_by`),
  KEY `idx_property_number` (`property_number`),
  KEY `idx_structure` (`structure`),
  KEY `idx_zone` (`zone_id`),
  KEY `idx_properties_payable` (`amount_payable`),
  CONSTRAINT `properties_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`zone_id`),
  CONSTRAINT `properties_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `properties`
INSERT INTO `properties` (`property_id`, `property_number`, `owner_name`, `telephone`, `gender`, `location`, `latitude`, `longitude`, `structure`, `ownership_type`, `property_type`, `number_of_rooms`, `property_use`, `old_bill`, `previous_payments`, `arrears`, `current_bill`, `amount_payable`, `batch`, `zone_id`, `created_by`, `created_at`, `updated_at`) VALUES
('1', 'PROP000001', 'Yaw Kusi', '0545051428', 'Male', 'GPS: 5.593020, -0.077100', '5.59302000', '-0.07710000', 'Modern Building', 'Self', 'Modern', '3', 'Residential', '0.00', '150.00', '0.00', '225.00', '75.00', '', '2', '1', '2025-07-10 04:07:51', '2025-07-12 05:46:12'),
('2', 'PROP000002', 'Beatrice Akueteh', '0543258791', 'Female', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', '5.59302000', '-0.07710000', 'Modern Building', 'Self', 'Modern', '3', 'Commercial', '0.00', '0.00', '0.00', '450.00', '450.00', '', '4', NULL, '2025-07-11 18:25:16', '2025-07-11 18:25:16'),
('3', 'PROP000003', 'Martin Kpebu', '0244657865', 'Male', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', '5.59302000', '-0.07710000', 'Mud Block', 'Corporate', 'Modern', '2', 'Commercial', '0.00', '0.00', '0.00', '100.00', '100.00', '', '2', NULL, '2025-07-12 10:06:25', '2025-07-12 10:06:25');


-- Table structure for table `property_fee_structure`
DROP TABLE IF EXISTS `property_fee_structure`;
CREATE TABLE `property_fee_structure` (
  `fee_id` int(11) NOT NULL AUTO_INCREMENT,
  `structure` varchar(100) NOT NULL,
  `property_use` enum('Commercial','Residential') NOT NULL,
  `fee_per_room` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_id`),
  UNIQUE KEY `unique_structure_use` (`structure`,`property_use`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `property_fee_structure_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `property_fee_structure`
INSERT INTO `property_fee_structure` (`fee_id`, `structure`, `property_use`, `fee_per_room`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
('1', 'Concrete Block', 'Residential', '50.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('2', 'Concrete Block', 'Commercial', '100.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('3', 'Mud Block', 'Residential', '25.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('4', 'Mud Block', 'Commercial', '50.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('5', 'Modern Building', 'Residential', '75.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35'),
('6', 'Modern Building', 'Commercial', '150.00', '1', '1', '2025-07-04 18:57:35', '2025-07-04 18:57:35');


-- Table structure for table `property_summary`
DROP TABLE IF EXISTS `property_summary`;
;

-- Dumping data for table `property_summary`
INSERT INTO `property_summary` (`property_id`, `property_number`, `owner_name`, `telephone`, `location`, `structure`, `property_use`, `number_of_rooms`, `amount_payable`, `zone_name`, `payment_status`) VALUES
('1', 'PROP000001', 'Yaw Kusi', '0545051428', 'GPS: 5.593020, -0.077100', 'Modern Building', 'Residential', '3', '75.00', 'North Zone', 'Defaulter'),
('2', 'PROP000002', 'Beatrice Akueteh', '0543258791', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', 'Modern Building', 'Commercial', '3', '450.00', 'Eastern Zone', 'Defaulter'),
('3', 'PROP000003', 'Martin Kpebu', '0244657865', 'GPS: 5.593020, -0.077100
HWVF+37C, Accra, Ghana', 'Mud Block', 'Commercial', '2', '100.00', 'North Zone', 'Defaulter');


-- Table structure for table `public_sessions`
DROP TABLE IF EXISTS `public_sessions`;
CREATE TABLE `public_sessions` (
  `session_id` varchar(64) NOT NULL,
  `account_number` varchar(20) DEFAULT NULL,
  `session_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`session_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT (current_timestamp() + interval 1 hour),
  PRIMARY KEY (`session_id`),
  KEY `idx_account_number` (`account_number`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `public_sessions`

-- Table structure for table `sub_zones`
DROP TABLE IF EXISTS `sub_zones`;
CREATE TABLE `sub_zones` (
  `sub_zone_id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) NOT NULL,
  `sub_zone_name` varchar(100) NOT NULL,
  `sub_zone_code` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sub_zone_id`),
  UNIQUE KEY `sub_zone_code` (`sub_zone_code`),
  KEY `zone_id` (`zone_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `sub_zones_ibfk_1` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`zone_id`) ON DELETE CASCADE,
  CONSTRAINT `sub_zones_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `sub_zones`
INSERT INTO `sub_zones` (`sub_zone_id`, `zone_id`, `sub_zone_name`, `sub_zone_code`, `description`, `created_by`, `created_at`) VALUES
('1', '1', 'Market Area', 'MA01', 'Main market area', '1', '2025-07-04 18:57:35'),
('2', '1', 'Government Area', 'GA02', 'Government offices area', '1', '2025-07-04 18:57:35'),
('3', '2', 'Residential A', 'RA01', 'High-end residential', '1', '2025-07-04 18:57:35'),
('4', '3', 'Industrial Area', 'IA01', 'Industrial zone', '1', '2025-07-04 18:57:35');


-- Table structure for table `system_restrictions`
DROP TABLE IF EXISTS `system_restrictions`;
CREATE TABLE `system_restrictions` (
  `restriction_id` int(11) NOT NULL AUTO_INCREMENT,
  `restriction_start_date` date NOT NULL,
  `restriction_end_date` date NOT NULL,
  `warning_days` int(11) DEFAULT 7,
  `is_active` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`restriction_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `system_restrictions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `system_restrictions`
INSERT INTO `system_restrictions` (`restriction_id`, `restriction_start_date`, `restriction_end_date`, `warning_days`, `is_active`, `created_by`, `created_at`) VALUES
('1', '2025-07-12', '2025-10-12', '7', '0', '3', '2025-07-12 14:25:08'),
('2', '2025-07-12', '2025-10-12', '30', '0', '3', '2025-07-12 14:36:38'),
('3', '2025-07-18', '2025-07-30', '7', '1', '3', '2025-07-12 18:18:35');


-- Table structure for table `system_settings`
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','date','json') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `system_settings`
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_by`, `updated_at`) VALUES
('1', 'assembly_name', 'Municipal Assembly', 'text', 'Name to appear on bills and reports', '1', '2025-07-12 13:55:48'),
('2', 'billing_start_date', '2024-11-01', 'date', 'Annual billing start date', NULL, '2025-07-04 18:57:35'),
('3', 'restriction_period_months', '3', 'number', 'System restriction period in months', NULL, '2025-07-04 18:57:35'),
('4', 'restriction_start_date', '2025-07-18', 'date', 'Restriction countdown start date', '3', '2025-07-12 18:18:35'),
('5', 'system_restricted', 'false', 'boolean', 'System restriction status', '3', '2025-07-12 14:41:52'),
('6', 'sms_enabled', 'true', 'boolean', 'SMS notifications enabled', NULL, '2025-07-04 18:57:35'),
('7', 'auto_bill_generation', 'true', 'boolean', 'Automatic bill generation on Nov 1st', NULL, '2025-07-04 18:57:35');


-- Table structure for table `user_roles`
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `user_roles`
INSERT INTO `user_roles` (`role_id`, `role_name`, `description`, `created_at`) VALUES
('1', 'Super Admin', 'Full system access with restriction controls', '2025-07-04 18:57:34'),
('2', 'Admin', 'Full system access excluding restrictions', '2025-07-04 18:57:34'),
('3', 'Officer', 'Register businesses/properties, record payments, generate bills', '2025-07-04 18:57:34'),
('4', 'Revenue Officer', 'Record payments and view maps', '2025-07-04 18:57:34'),
('5', 'Data Collector', 'Register businesses/properties and view profiles', '2025-07-04 18:57:34');


-- Table structure for table `users`
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `first_login` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role_id`, `first_name`, `last_name`, `phone`, `is_active`, `first_login`, `last_login`, `created_at`, `updated_at`) VALUES
('1', 'admin', 'admin@quickbill305.com', '$2y$10$e4YGmKebT13JFeJVTNJTr.oWNFXUzfTYqhmQEco1/VF/hVOSPCdYS', '2', 'System', 'Administrator', '+233000000000', '1', '0', '2025-07-12 14:58:02', '2025-07-04 18:57:35', '2025-07-12 14:58:02'),
('2', 'abismark', 'kabslink@gmail.com', '$2y$10$JUEO.SZbvFTCgI6p.QYfyO3zVd6hcKqyp8FJcr/.ido7RApNtGXlW', '2', 'Afful', 'Bismark', '+233545041428', '1', '1', NULL, '2025-07-05 17:21:43', '2025-07-05 17:21:43'),
('3', 'Joojo', 'kwadwomegas@gmail.com', '$2y$10$JSLvWE7gM/FUgiTqv9v1qOU9L4U3udx6crIBivD6KIP9.q2NMuTDq', '1', 'Joojo', 'Megas', '0545041428', '1', '0', '2025-07-12 17:43:22', '2025-07-09 19:03:22', '2025-07-12 17:43:22'),
('4', 'Kusi', 'kusi@gmail.com', '$2y$10$xXAtNw3GQSVKPNRPnaIacOX9XWegyGQT47fAkuZ22b1J9swsJllge', '5', 'Kusi', 'France', '+233543258791', '1', '0', '2025-07-12 18:27:30', '2025-07-11 15:21:00', '2025-07-12 18:27:30'),
('5', 'Aseye', 'aseyeabledoo@gmail.com', '$2y$10$I8aBJT72RTKJ8bMgiWOwP.831BvSerUvhqQCLft82TbkDyTDJgIZO', '4', 'Aseye', 'Abledu', '', '1', '0', '2025-07-12 16:31:21', '2025-07-12 05:12:52', '2025-07-12 16:31:21'),
('6', 'David', 'kabtechconsulting@gmail.com', '$2y$10$Sn1Ex9uZx3GlCdAwsKkOcuow7anUlJI9FJBSaRgjyDDUeQg4S0XjW', '3', 'David', 'Lomko', '', '1', '0', '2025-07-12 15:42:07', '2025-07-12 07:18:33', '2025-07-12 15:42:07');


-- Table structure for table `zones`
DROP TABLE IF EXISTS `zones`;
CREATE TABLE `zones` (
  `zone_id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_name` varchar(100) NOT NULL,
  `zone_code` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`zone_id`),
  UNIQUE KEY `zone_code` (`zone_code`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `zones_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `zones`
INSERT INTO `zones` (`zone_id`, `zone_name`, `zone_code`, `description`, `created_by`, `created_at`) VALUES
('1', 'Central Zone', 'CZ01', 'Central business district', '1', '2025-07-04 18:57:35'),
('2', 'North Zone', 'NZ02', 'Northern residential area', '1', '2025-07-04 18:57:35'),
('3', 'South Zone', 'SZ03', 'Southern commercial area', '1', '2025-07-04 18:57:35'),
('4', 'Eastern Zone', 'EZ', NULL, '1', '2025-07-10 09:20:38');

COMMIT;
