-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 13, 2026 at 03:20 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tesda_compliance`
--

DELIMITER $$
--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `get_course_task_count` (`course_id` INT) RETURNS INT DETERMINISTIC READS SQL DATA BEGIN
    DECLARE task_count INT;
    SELECT COUNT(*) INTO task_count 
    FROM performance_tasks 
    WHERE course_id = course_id OR course_id IS NULL;
    RETURN task_count;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_role` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_department` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_qualification` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `login_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT '0',
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `username`, `user_role`, `user_department`, `user_qualification`, `action`, `login_time`, `deleted_at`, `deleted_by`, `is_deleted`, `ip_address`, `user_agent`) VALUES
(1, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-11 21:21:16', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(3, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-11 22:45:20', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(4, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-11 22:57:30', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(6, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 11:47:25', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(7, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 13:17:14', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(8, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 14:40:44', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(9, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 15:11:36', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(10, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 17:26:57', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(12, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 18:01:22', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(13, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 18:02:45', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(14, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 18:04:02', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(15, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 18:23:09', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(16, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-12 18:32:29', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(17, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 08:39:23', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(18, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 11:05:39', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(19, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 11:06:50', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(21, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 15:44:07', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(22, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 17:25:15', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(24, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 19:31:35', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(26, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 20:34:45', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(27, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 20:37:22', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(28, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 20:51:08', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(29, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:08:18', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(30, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:08:36', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(31, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:17:25', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(33, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:25:22', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(34, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:35:18', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(35, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:44:06', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(36, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:49:09', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(37, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 21:56:39', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(38, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:02:50', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(39, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:06:06', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(41, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:07:22', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(42, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:07:59', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0'),
(43, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:15:42', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(44, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:21:49', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(45, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 22:44:36', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(46, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 23:01:29', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(47, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 23:08:03', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'),
(48, 1, 'coordinator', 'coordinator', 'N/A', '', 'LOGIN', '2026-10-13 23:13:46', NULL, NULL, 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36');

-- --------------------------------------------------------

--
-- Table structure for table `competency_standards`
--

CREATE TABLE `competency_standards` (
  `id` int NOT NULL,
  `qualification_id` int NOT NULL,
  `unit_of_competency` enum('core') NOT NULL DEFAULT 'core',
  `competency_name` varchar(200) NOT NULL,
  `code_no` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `competency_standards`
--

INSERT INTO `competency_standards` (`id`, `qualification_id`, `unit_of_competency`, `competency_name`, `code_no`, `created_at`, `updated_at`, `created_by`) VALUES
(117, 6, 'core', 'SERVICE CLUTCH SYSTEM', 'AS2-CO-001', '2026-09-12 01:53:47', '2026-09-12 01:53:47', NULL),
(118, 6, 'core', 'SERVICING STARTING SYSTEM', 'AS2-CO-002', '2026-09-12 01:54:01', '2026-09-12 01:54:01', NULL),
(119, 6, 'core', 'SERVICE CHARGING SYSTEM', 'AS2-CO-003', '2026-09-12 01:55:45', '2026-09-12 01:55:45', NULL),
(120, 6, 'core', 'SERVICE ENGINE MECHANICAL SYSTEM', 'AS2-CO-004', '2026-09-12 01:56:39', '2026-09-12 01:56:39', NULL),
(122, 6, 'core', 'SERVICE DIFFERENTIAL AND FRONT AXLE', 'AS2-CO-005', '2026-09-12 02:02:20', '2026-09-12 02:02:20', NULL),
(123, 6, 'core', 'SERVICE MANUAL STEERING SYSTEM', 'AS2-CO-006', '2026-09-12 02:06:06', '2026-09-12 02:06:06', NULL),
(124, 6, 'core', 'OVERHAUL MANUAL TRANSMISSION', 'AS2-CO-007', '2026-09-12 02:12:14', '2026-09-12 02:12:14', NULL),
(125, 6, 'core', 'SERVICE BRAKE SYSTEM', 'AS2-CO-008', '2026-09-12 02:14:31', '2026-09-12 02:14:31', NULL),
(126, 6, 'core', 'SERVICE SUSPENSION SYSTEM', 'AS2-CO-009', '2026-09-12 02:17:01', '2026-09-12 02:17:01', NULL),
(127, 3, 'core', 'INSTALL AND CONFIGURE COMPUTER SYSTEMS', 'CSS-CO-001', '2026-09-12 02:25:45', '2026-09-12 02:25:45', NULL),
(128, 3, 'core', 'SET-UP COMPUTER NETWORKS', 'CSS-CO-002', '2026-09-12 02:27:25', '2026-09-12 02:27:25', NULL),
(129, 3, 'core', 'SET-UP COMPUTER SERVERS', 'CSS-CO-003', '2026-09-12 02:32:55', '2026-09-12 02:32:55', NULL),
(130, 3, 'core', 'MAINTAIN AND REPAIR COMPUTER SYSTEMS AND NETWORKS', 'CSS-CO-004', '2026-09-12 02:34:00', '2026-09-12 02:34:00', NULL),
(131, 4, 'core', 'ASSEMBLE ELECTRONIC PRODUCTS', 'EPAS-CO-001', '2026-09-12 02:39:13', '2026-09-12 02:39:13', NULL),
(132, 4, 'core', 'SERVICE CONSUMER ELECTRONIC PRODUCTS AND SYSTEMS', 'EPAS-CO-002', '2026-09-12 02:40:17', '2026-09-12 02:40:17', NULL),
(133, 4, 'core', 'SERVICE INDUSTRIAL ELECTRONIC MODULES, PRODUCTS AND SYSTEMS', 'EPAS-CO-003', '2026-09-12 02:41:57', '2026-09-12 02:41:57', NULL),
(135, 1, 'core', 'CLEAN AND PREPARE ROOMS FOR INCOMING GUESTS', 'TRS5123112', '2026-09-12 02:58:00', '2026-09-12 03:37:33', NULL),
(136, 1, 'core', 'PROVIDE HOUSEKEEPING SERVICES TO GUESTS', 'TRS5123111', '2026-09-12 02:59:58', '2026-09-12 02:59:58', NULL),
(137, 1, 'core', 'PROVIDE VALET/ BUTLER SERVICE', 'TRS5123113', '2026-09-12 03:01:15', '2026-09-12 03:01:15', NULL),
(138, 1, 'core', 'LAUNDRY LINEN AND GUEST CLOTHES', 'TRS5123114', '2026-09-12 03:03:49', '2026-09-12 03:03:49', NULL),
(139, 1, 'core', 'CLEAN PUBLIC AREAS, FACILITIES AND EQUIPMENT', 'TRS5123115', '2026-09-12 03:11:05', '2026-09-12 03:11:05', NULL),
(140, 1, 'core', 'DEAL WITH/HANDLE INTOXICATED GUESTS', 'TRS5123122', '2026-09-12 03:12:23', '2026-09-12 03:12:23', NULL),
(141, 2, 'core', 'PREPARE THE DINING ROOM/ RESTAURANT AREA FOR SERVICE', 'TRS512387', '2026-09-12 03:19:31', '2026-09-12 03:19:31', NULL),
(142, 2, 'core', 'WELCOME GUESTS AND TAKE FOOD AND BEVERAGE ORDERS', 'TRS512388', '2026-09-12 03:21:10', '2026-09-12 03:21:10', NULL),
(143, 2, 'core', 'PROMOTE FOOD AND BEVERAGE PRODUCTS', 'TRS512389', '2026-09-12 03:27:13', '2026-09-12 03:27:13', NULL),
(144, 2, 'core', 'PROVIDE FOOD AND BEVERAGE SERVICES TO GUESTS', 'TRS512390', '2026-09-12 03:29:16', '2026-09-12 03:29:16', NULL),
(145, 2, 'core', 'PROVIDE ROOM SERVICE', 'TRS512391', '2026-09-12 03:30:36', '2026-09-12 03:30:36', NULL),
(146, 2, 'core', 'RECEIVE AND HANDLE GUEST CONCERNS', 'TRS512392', '2026-09-12 03:31:45', '2026-09-12 03:31:45', NULL),
(147, 5, 'core', 'PERFORM PRE-DELIVERY INSPECTION', 'ALT723372', '2026-09-12 03:36:35', '2026-09-12 03:36:35', NULL),
(148, 5, 'core', 'PERFORM PERIODIC MAINTENANCE OF AUTOMOTIVE ENGINE', 'ALT723373', '2026-09-12 03:39:03', '2026-09-12 03:39:03', NULL),
(149, 5, 'core', 'PERFORM PERIODIC MAINTENANCE OF DRIVE TRAIN', 'ALT723374', '2026-09-12 03:41:58', '2026-09-12 03:41:58', NULL),
(150, 5, 'core', 'PERFORM PERIODIC MAINTENANCE OF BRAKE SYSTEM', 'ALT723375', '2026-09-12 03:43:32', '2026-09-12 03:43:32', NULL),
(151, 5, 'core', 'PERFORM PERIODIC MAINTENANCE OF SUSPENSION SYSTEM', 'ALT723376', '2026-09-12 03:45:18', '2026-09-12 03:45:18', NULL),
(152, 5, 'core', 'PERFORM PERIODIC MAINTENANCE OF STEERING SYSTEM', 'ALT723377', '2026-09-12 03:52:12', '2026-09-12 03:52:12', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int NOT NULL,
  `course_code` varchar(10) NOT NULL,
  `course_name` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT '#4F46E5',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `coordinator_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `course_code`, `course_name`, `color`, `created_at`, `updated_at`, `coordinator_id`) VALUES
(1, 'HRS', 'Hotel and Restaurant Services', '#8b5cf6', '2026-08-25 10:53:29', '2026-08-25 10:53:29', NULL),
(2, 'CSS', 'Computer Systems Servicing', '#10b981', '2026-08-25 10:53:29', '2026-08-25 10:53:29', NULL),
(3, 'ATS', 'Automotive Mechanics', '#f59e0b', '2026-08-25 10:53:29', '2026-08-25 10:53:29', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_eligibility`
--

CREATE TABLE `evaluation_eligibility` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `is_eligible` tinyint(1) DEFAULT '0',
  `total_tasks` int DEFAULT '0',
  `completed_tasks` int DEFAULT '0',
  `eligibility_date` timestamp NULL DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pre_assessment_completed` tinyint(1) DEFAULT '0',
  `ready_for_assessment` tinyint(1) DEFAULT '0',
  `ready_for_assessment_date` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_tracker`
--

CREATE TABLE `evaluation_tracker` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `task_id` int NOT NULL,
  `performance_task` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remarks` enum('Done','Not Done') COLLATE utf8mb4_general_ci DEFAULT 'Not Done',
  `status` enum('complied','not_complied') COLLATE utf8mb4_general_ci DEFAULT 'not_complied',
  `evaluated_by` int DEFAULT NULL,
  `evaluated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `video_path` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation_tracker`
--

INSERT INTO `evaluation_tracker` (`id`, `trainee_id`, `task_id`, `performance_task`, `remarks`, `status`, `evaluated_by`, `evaluated_at`, `completed_at`, `created_at`, `updated_at`, `deleted_at`, `video_path`) VALUES
(38, 2, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-12 08:19:17', '2026-10-13 03:42:46', NULL, NULL),
(39, 2, 135, 'Prepare required items in the vehicle', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-12 08:19:17', '2026-10-13 03:42:46', NULL, NULL),
(40, 2, 136, 'Install factory-loaded parts on the vehicle', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-12 08:19:17', '2026-10-13 03:42:46', NULL, NULL),
(41, 2, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-12 08:19:17', '2026-10-13 03:42:46', NULL, NULL),
(42, 2, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-12 08:19:17', '2026-10-13 03:42:46', NULL, NULL),
(43, 2, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-12 08:19:17', NULL, '2026-10-12 08:19:17', '2026-10-12 08:19:17', NULL, NULL),
(44, 2, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-12 08:19:17', NULL, '2026-10-12 08:19:17', '2026-10-12 08:19:17', NULL, NULL),
(45, 2, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-12 08:19:17', NULL, '2026-10-12 08:19:17', '2026-10-12 08:19:17', NULL, NULL),
(46, 2, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-12 08:19:17', NULL, '2026-10-12 08:19:17', '2026-10-12 08:19:17', NULL, NULL),
(47, 2, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-12 08:19:17', NULL, '2026-10-12 08:19:17', '2026-10-12 08:19:17', NULL, NULL),
(48, 2, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(49, 2, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(50, 2, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(51, 2, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(52, 2, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(53, 2, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(54, 2, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(55, 2, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(56, 2, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(57, 2, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(58, 2, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(59, 2, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(60, 2, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(61, 2, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(62, 2, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(63, 2, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(64, 2, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(65, 2, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(66, 2, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(67, 2, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(68, 2, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(69, 2, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-12 08:19:18', NULL, '2026-10-12 08:19:18', '2026-10-12 08:19:18', NULL, NULL),
(70, 5, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 05:35:03', NULL, '2026-10-13 05:35:03', '2026-10-13 05:35:03', NULL, NULL),
(71, 5, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(72, 5, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(73, 5, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(74, 5, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(75, 5, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(76, 5, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(77, 5, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(78, 5, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(79, 5, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(80, 5, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(81, 5, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(82, 5, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(83, 5, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(84, 5, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 05:38:30', NULL, '2026-10-13 05:38:30', '2026-10-13 05:38:30', NULL, NULL),
(85, 8, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:10', NULL, '2026-10-13 06:27:10', '2026-10-13 06:27:10', NULL, NULL),
(86, 8, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 06:27:10', NULL, '2026-10-13 06:27:10', '2026-10-13 06:27:10', NULL, NULL),
(87, 8, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 06:27:10', NULL, '2026-10-13 06:27:10', '2026-10-13 06:27:10', NULL, NULL),
(88, 8, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:10', NULL, '2026-10-13 06:27:10', '2026-10-13 06:27:10', NULL, NULL),
(89, 8, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:10', NULL, '2026-10-13 06:27:10', '2026-10-13 06:27:10', NULL, NULL),
(90, 8, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(91, 8, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(92, 8, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(93, 8, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(94, 8, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(95, 8, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(96, 8, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(97, 8, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(98, 8, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(99, 8, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:11', NULL, '2026-10-13 06:27:11', '2026-10-13 06:27:11', NULL, NULL),
(100, 6, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(101, 6, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(102, 6, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(103, 6, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(104, 6, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(105, 6, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(106, 6, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(107, 6, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(108, 6, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(109, 6, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(110, 6, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(111, 6, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(112, 6, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 06:27:15', NULL, '2026-10-13 06:27:15', '2026-10-13 06:27:15', NULL, NULL),
(113, 6, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:16', NULL, '2026-10-13 06:27:16', '2026-10-13 06:27:16', NULL, NULL),
(114, 6, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:16', NULL, '2026-10-13 06:27:16', '2026-10-13 06:27:16', NULL, NULL),
(115, 12, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(116, 12, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(117, 12, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(118, 12, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(119, 12, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(120, 12, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(121, 12, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(122, 12, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(123, 12, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(124, 12, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(125, 12, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(126, 12, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(127, 12, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 06:27:20', NULL, '2026-10-13 06:27:20', '2026-10-13 06:27:20', NULL, NULL),
(128, 12, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 06:27:21', NULL, '2026-10-13 06:27:21', '2026-10-13 06:27:21', NULL, NULL),
(129, 12, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 06:27:21', NULL, '2026-10-13 06:27:21', '2026-10-13 06:27:21', NULL, NULL),
(130, 14, 85, 'Set up equipment and trolleys', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(131, 14, 86, 'Access rooms for servicing', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(132, 14, 87, 'Make up beds', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(133, 14, 88, 'Clean and clear rooms', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(134, 14, 89, 'Clean and store trolleys and equipment', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(135, 14, 90, 'Provide Housekeeping Services to Guests', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(136, 14, 91, 'Provide/Service housekeeping requests', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(137, 14, 92, 'Provide/Service housekeeping requests', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(138, 14, 93, 'Liaise with other departments', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(139, 14, 94, 'Provide valet services to guests', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(140, 14, 95, 'Display professional valet standards', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(141, 14, 96, 'Care for guest property', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(142, 14, 97, 'Collect laundry for laundering functions', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(143, 14, 98, 'Perform laundering functions', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(144, 14, 99, 'Process laundered item', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(145, 14, 100, 'Process laundered item', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(146, 14, 101, 'Select and set up equipment and materials', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(147, 14, 102, 'Apply cleaning technique 5.3	Clean dry and wet areas', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(148, 14, 103, 'Maintain and store cleaning equipment and chemicals', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(149, 14, 104, 'Determine the level of intoxication', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(150, 14, 105, 'Apply appropriate procedures', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(151, 14, 106, 'Comply with legislation', 'Done', 'complied', 1, '2026-10-13 06:43:54', NULL, '2026-10-13 06:43:54', '2026-10-13 06:43:54', NULL, NULL),
(152, 21, 85, 'Set up equipment and trolleys', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(153, 21, 86, 'Access rooms for servicing', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(154, 21, 87, 'Make up beds', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(155, 21, 88, 'Clean and clear rooms', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(156, 21, 89, 'Clean and store trolleys and equipment', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(157, 21, 90, 'Provide Housekeeping Services to Guests', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(158, 21, 91, 'Provide/Service housekeeping requests', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(159, 21, 92, 'Provide/Service housekeeping requests', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(160, 21, 93, 'Liaise with other departments', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(161, 21, 94, 'Provide valet services to guests', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(162, 21, 95, 'Display professional valet standards', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(163, 21, 96, 'Care for guest property', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(164, 21, 97, 'Collect laundry for laundering functions', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(165, 21, 98, 'Perform laundering functions', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(166, 21, 99, 'Process laundered item', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(167, 21, 100, 'Process laundered item', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(168, 21, 101, 'Select and set up equipment and materials', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(169, 21, 102, 'Apply cleaning technique 5.3	Clean dry and wet areas', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(170, 21, 103, 'Maintain and store cleaning equipment and chemicals', 'Done', 'complied', 1, '2026-10-13 06:43:58', NULL, '2026-10-13 06:43:58', '2026-10-13 06:43:58', NULL, NULL),
(171, 21, 104, 'Determine the level of intoxication', 'Done', 'complied', 1, '2026-10-13 06:43:59', NULL, '2026-10-13 06:43:59', '2026-10-13 06:43:59', NULL, NULL),
(172, 21, 105, 'Apply appropriate procedures', 'Done', 'complied', 1, '2026-10-13 06:43:59', NULL, '2026-10-13 06:43:59', '2026-10-13 06:43:59', NULL, NULL),
(173, 21, 106, 'Comply with legislation', 'Done', 'complied', 1, '2026-10-13 06:43:59', NULL, '2026-10-13 06:43:59', '2026-10-13 06:43:59', NULL, NULL),
(174, 3, 12, 'Diagnose clutch failure/problem', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:33', '2026-10-13 10:02:32', NULL, NULL),
(175, 3, 13, 'Pull out and mount clutch component parts', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:33', '2026-10-13 10:02:32', NULL, NULL),
(176, 3, 14, 'Dismantle/install clutch mechanism', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:34', '2026-10-13 10:02:32', NULL, NULL),
(177, 3, 15, 'Overhaul hydraulic clutch mechanism', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:34', '2026-10-13 10:02:32', NULL, NULL),
(178, 3, 16, 'Perform clutch parts failure analysis', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:34', '2026-10-13 10:02:32', NULL, NULL),
(179, 3, 17, 'Set/adjust clutch system components', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:34', '2026-10-13 10:02:32', NULL, NULL),
(180, 3, 18, 'Bleed clutch hydraulic system', 'Not Done', 'not_complied', NULL, NULL, NULL, '2026-10-13 06:53:34', '2026-10-13 10:02:32', NULL, NULL),
(181, 3, 1, 'Test starting system components and identify faults', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(182, 3, 2, 'Disassemble starter', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(183, 3, 3, 'Repair/replace and assemble starter components/parts', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(184, 3, 4, 'Test charging system components and identify faults', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(185, 3, 5, 'Disassemble alternator', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(186, 3, 6, 'Repair/replace and assemble alternator components/parts', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(187, 3, 7, 'Explain coolant circulation within cooling system', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(188, 3, 8, 'Check thermostat condition', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(189, 3, 9, 'Perform leakage test on cooling system', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(190, 3, 10, 'Repair/replace water pump', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(191, 3, 11, 'Apply coolant additives', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(192, 3, 19, 'Inspect drive shaft and joint', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(193, 3, 20, 'Dismount and mount', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(194, 3, 21, 'Dismantle and assemble differential parts', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(195, 3, 22, 'Diagnose differential parts failure', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(196, 3, 23, 'Set/adjust differential components', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(197, 3, 24, 'Overhaul rear/front wheel hub', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(198, 3, 25, 'Analyze king pin condition', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(199, 3, 26, 'Inspect/replace I-beam', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(200, 3, 27, 'Service steering system', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(201, 3, 28, 'Diagnose power steering system failure', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(202, 3, 29, 'Adjust/align tie-rod end to wheel', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(203, 3, 30, 'Dismantle/install steering components', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(204, 3, 31, 'Conduct wheel alignment', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(205, 3, 32, 'Identify types of transmission and components', 'Done', 'complied', 1, '2026-10-13 06:53:34', NULL, '2026-10-13 06:53:34', '2026-10-13 06:53:34', NULL, NULL),
(206, 3, 33, 'Testing transmission', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(207, 3, 34, 'Pull out and dismount manual transmission and associate component', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(208, 3, 35, 'Disassemble manual transmission', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(209, 3, 36, 'Assemble manual transmission component', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(210, 3, 37, 'Component of hydraulic brake system (ABS).', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(211, 3, 38, 'Function of brake', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(212, 3, 39, 'Operating principle of Anti-Lock Brake system (ABS)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(213, 3, 40, 'Types and classification of brakes (ABS)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(214, 3, 41, 'ABS operation and manufacturer specifications', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(215, 3, 42, 'Checking electronic control unit (ECU)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(216, 3, 43, 'Checking ABS hydraulic pump', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(217, 3, 44, 'ABS schematic diagrams', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(218, 3, 45, 'Identify the type of suspension system, component and its operating principle (conventional and air suspension)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(219, 3, 46, 'Diagnose suspension system failure', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(220, 3, 47, 'Disassemble and assemble suspension system (conventional type)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(221, 3, 48, 'Inspect and replace suspension system component (conventional type)', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(222, 3, 49, 'Service air suspension system', 'Done', 'complied', 1, '2026-10-13 06:53:35', NULL, '2026-10-13 06:53:35', '2026-10-13 06:53:35', NULL, NULL),
(223, 27, 12, 'Diagnose clutch failure/problem', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(224, 27, 13, 'Pull out and mount clutch component parts', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(225, 27, 14, 'Dismantle/install clutch mechanism', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(226, 27, 15, 'Overhaul hydraulic clutch mechanism', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(227, 27, 16, 'Perform clutch parts failure analysis', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(228, 27, 17, 'Set/adjust clutch system components', 'Done', 'complied', 1, '2026-10-13 06:53:41', NULL, '2026-10-13 06:53:41', '2026-10-13 06:53:41', NULL, NULL),
(229, 27, 18, 'Bleed clutch hydraulic system', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(230, 27, 1, 'Test starting system components and identify faults', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(231, 27, 2, 'Disassemble starter', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(232, 27, 3, 'Repair/replace and assemble starter components/parts', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(233, 27, 4, 'Test charging system components and identify faults', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(234, 27, 5, 'Disassemble alternator', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(235, 27, 6, 'Repair/replace and assemble alternator components/parts', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(236, 27, 7, 'Explain coolant circulation within cooling system', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(237, 27, 8, 'Check thermostat condition', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(238, 27, 9, 'Perform leakage test on cooling system', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(239, 27, 10, 'Repair/replace water pump', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(240, 27, 11, 'Apply coolant additives', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(241, 27, 19, 'Inspect drive shaft and joint', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(242, 27, 20, 'Dismount and mount', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(243, 27, 21, 'Dismantle and assemble differential parts', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(244, 27, 22, 'Diagnose differential parts failure', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(245, 27, 23, 'Set/adjust differential components', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(246, 27, 24, 'Overhaul rear/front wheel hub', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(247, 27, 25, 'Analyze king pin condition', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(248, 27, 26, 'Inspect/replace I-beam', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(249, 27, 27, 'Service steering system', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(250, 27, 28, 'Diagnose power steering system failure', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(251, 27, 29, 'Adjust/align tie-rod end to wheel', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(252, 27, 30, 'Dismantle/install steering components', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(253, 27, 31, 'Conduct wheel alignment', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(254, 27, 32, 'Identify types of transmission and components', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(255, 27, 33, 'Testing transmission', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(256, 27, 34, 'Pull out and dismount manual transmission and associate component', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(257, 27, 35, 'Disassemble manual transmission', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(258, 27, 36, 'Assemble manual transmission component', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(259, 27, 37, 'Component of hydraulic brake system (ABS).', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(260, 27, 38, 'Function of brake', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(261, 27, 39, 'Operating principle of Anti-Lock Brake system (ABS)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(262, 27, 40, 'Types and classification of brakes (ABS)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(263, 27, 41, 'ABS operation and manufacturer specifications', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(264, 27, 42, 'Checking electronic control unit (ECU)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(265, 27, 43, 'Checking ABS hydraulic pump', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(266, 27, 44, 'ABS schematic diagrams', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(267, 27, 45, 'Identify the type of suspension system, component and its operating principle (conventional and air suspension)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(268, 27, 46, 'Diagnose suspension system failure', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(269, 27, 47, 'Disassemble and assemble suspension system (conventional type)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(270, 27, 48, 'Inspect and replace suspension system component (conventional type)', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(271, 27, 49, 'Service air suspension system', 'Done', 'complied', 1, '2026-10-13 06:53:42', NULL, '2026-10-13 06:53:42', '2026-10-13 06:53:42', NULL, NULL),
(272, 45, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(273, 45, 135, 'Prepare required items in the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(274, 45, 136, 'Install factory-loaded parts on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(275, 45, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(276, 45, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(277, 45, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(278, 45, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(279, 45, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(280, 45, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(281, 45, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(282, 45, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(283, 45, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(284, 45, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(285, 45, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(286, 45, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(287, 45, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(288, 45, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(289, 45, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(290, 45, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:05', NULL, '2026-10-13 09:58:05', '2026-10-13 09:58:05', NULL, NULL),
(291, 45, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(292, 45, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(293, 45, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(294, 45, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(295, 45, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(296, 45, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(297, 45, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(298, 45, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(299, 45, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(300, 45, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(301, 45, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(302, 45, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(303, 45, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-13 09:58:06', NULL, '2026-10-13 09:58:06', '2026-10-13 09:58:06', NULL, NULL),
(304, 46, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(305, 46, 135, 'Prepare required items in the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(306, 46, 136, 'Install factory-loaded parts on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(307, 46, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(308, 46, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(309, 46, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(310, 46, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(311, 46, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(312, 46, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(313, 46, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(314, 46, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(315, 46, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-13 09:58:11', NULL, '2026-10-13 09:58:11', '2026-10-13 09:58:11', NULL, NULL),
(316, 46, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(317, 46, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(318, 46, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(319, 46, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(320, 46, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(321, 46, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(322, 46, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(323, 46, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(324, 46, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(325, 46, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(326, 46, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL);
INSERT INTO `evaluation_tracker` (`id`, `trainee_id`, `task_id`, `performance_task`, `remarks`, `status`, `evaluated_by`, `evaluated_at`, `completed_at`, `created_at`, `updated_at`, `deleted_at`, `video_path`) VALUES
(327, 46, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(328, 46, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(329, 46, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(330, 46, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(331, 46, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(332, 46, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(333, 46, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(334, 46, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(335, 46, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-13 09:58:12', NULL, '2026-10-13 09:58:12', '2026-10-13 09:58:12', NULL, NULL),
(336, 52, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(337, 52, 135, 'Prepare required items in the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(338, 52, 136, 'Install factory-loaded parts on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(339, 52, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(340, 52, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(341, 52, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(342, 52, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(343, 52, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(344, 52, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(345, 52, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(346, 52, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(347, 52, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(348, 52, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(349, 52, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(350, 52, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(351, 52, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(352, 52, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(353, 52, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(354, 52, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(355, 52, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(356, 52, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(357, 52, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(358, 52, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(359, 52, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(360, 52, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(361, 52, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(362, 52, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(363, 52, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(364, 52, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(365, 52, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(366, 52, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(367, 52, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-13 09:58:17', NULL, '2026-10-13 09:58:17', '2026-10-13 09:58:17', NULL, NULL),
(368, 47, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(369, 47, 135, 'Prepare required items in the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(370, 47, 136, 'Install factory-loaded parts on the vehicle', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(371, 47, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(372, 47, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(373, 47, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(374, 47, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(375, 47, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(376, 47, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(377, 47, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(378, 47, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(379, 47, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-13 09:58:23', NULL, '2026-10-13 09:58:23', '2026-10-13 09:58:23', NULL, NULL),
(380, 47, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(381, 47, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(382, 47, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(383, 47, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(384, 47, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(385, 47, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(386, 47, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(387, 47, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(388, 47, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(389, 47, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(390, 47, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(391, 47, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(392, 47, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(393, 47, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(394, 47, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(395, 47, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(396, 47, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(397, 47, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(398, 47, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(399, 47, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-13 09:58:24', NULL, '2026-10-13 09:58:24', '2026-10-13 09:58:24', NULL, NULL),
(400, 11, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(401, 11, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(402, 11, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(403, 11, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(404, 11, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(405, 11, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(406, 11, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(407, 11, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(408, 11, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(409, 11, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(410, 11, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(411, 11, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(412, 11, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 10:05:10', NULL, '2026-10-13 10:05:10', '2026-10-13 10:05:10', NULL, NULL),
(413, 11, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:11', NULL, '2026-10-13 10:05:11', '2026-10-13 10:05:11', NULL, NULL),
(414, 11, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 10:05:11', NULL, '2026-10-13 10:05:11', '2026-10-13 10:05:11', NULL, NULL),
(415, 4, 66, 'Prepare to assemble electronics products', 'Done', 'complied', 1, '2026-10-13 10:05:15', NULL, '2026-10-13 10:05:15', '2026-10-13 10:05:15', NULL, NULL),
(416, 4, 67, 'Prepare/ Make PCB modules', 'Done', 'complied', 1, '2026-10-13 10:05:15', NULL, '2026-10-13 10:05:15', '2026-10-13 10:05:15', NULL, NULL),
(417, 4, 68, 'Mount and solder electronic components', 'Done', 'complied', 1, '2026-10-13 10:05:15', NULL, '2026-10-13 10:05:15', '2026-10-13 10:05:15', NULL, NULL),
(418, 4, 69, 'Assemble electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(419, 4, 70, 'Test and inspect assembled electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(420, 4, 71, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(421, 4, 72, 'Install consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(422, 4, 73, 'Diagnose faults and defects consumer electronic products and systems', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(423, 4, 74, 'Maintain/Repair consumer electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(424, 4, 75, 'Re-assemble and test repaired consumer electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(425, 4, 76, 'Prepare unit, tools and workplace for installation and service', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(426, 4, 77, 'Install industrial electronic modules/products/systems', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(427, 4, 78, 'Diagnose faults and defects of industrial electronic modules/systems/products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(428, 4, 79, 'Maintain/Repair industrial electronic products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(429, 4, 80, 'Re-assemble and test repaired industrial electronics products', 'Done', 'complied', 1, '2026-10-13 10:05:16', NULL, '2026-10-13 10:05:16', '2026-10-13 10:05:16', NULL, NULL),
(430, 35, 50, 'Assemble computer hardware', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(431, 35, 51, 'Install operating system and drivers for peripherals/ devices', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(432, 35, 52, 'Install the computer application software', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(433, 35, 53, 'Conduct testing and documentation', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(434, 35, 54, 'Install network cables', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(435, 35, 55, 'Set network configuration', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(436, 35, 56, 'Set router/Wi-Fi/ wireless access point/repeater configuration', 'Done', 'complied', 1, '2026-10-13 10:07:05', NULL, '2026-10-13 10:07:05', '2026-10-13 10:07:05', NULL, NULL),
(437, 35, 57, 'Inspect and test the configured computer networks', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(438, 35, 58, 'Set-up user access', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(439, 35, 59, 'Configure network services', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(440, 35, 60, 'Perform testing, documentation and pre- deployment practices', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(441, 35, 61, 'Plan and prepare for maintenance and repair', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(442, 35, 62, 'Maintain computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(443, 35, 63, 'Diagnose faults of computer systems', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(444, 35, 65, 'Inspect and test the computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:06', NULL, '2026-10-13 10:07:06', '2026-10-13 10:07:06', NULL, NULL),
(445, 37, 50, 'Assemble computer hardware', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(446, 37, 51, 'Install operating system and drivers for peripherals/ devices', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(447, 37, 52, 'Install the computer application software', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(448, 37, 53, 'Conduct testing and documentation', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(449, 37, 54, 'Install network cables', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(450, 37, 55, 'Set network configuration', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(451, 37, 56, 'Set router/Wi-Fi/ wireless access point/repeater configuration', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(452, 37, 57, 'Inspect and test the configured computer networks', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(453, 37, 58, 'Set-up user access', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(454, 37, 59, 'Configure network services', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(455, 37, 60, 'Perform testing, documentation and pre- deployment practices', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(456, 37, 61, 'Plan and prepare for maintenance and repair', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(457, 37, 62, 'Maintain computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(458, 37, 63, 'Diagnose faults of computer systems', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(459, 37, 65, 'Inspect and test the computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:12', NULL, '2026-10-13 10:07:12', '2026-10-13 10:07:12', NULL, NULL),
(460, 34, 50, 'Assemble computer hardware', 'Done', 'complied', 1, '2026-10-13 10:07:20', NULL, '2026-10-13 10:07:20', '2026-10-13 10:07:20', NULL, NULL),
(461, 34, 51, 'Install operating system and drivers for peripherals/ devices', 'Done', 'complied', 1, '2026-10-13 10:07:20', NULL, '2026-10-13 10:07:20', '2026-10-13 10:07:20', NULL, NULL),
(462, 34, 52, 'Install the computer application software', 'Done', 'complied', 1, '2026-10-13 10:07:20', NULL, '2026-10-13 10:07:20', '2026-10-13 10:07:20', NULL, NULL),
(463, 34, 53, 'Conduct testing and documentation', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(464, 34, 54, 'Install network cables', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(465, 34, 55, 'Set network configuration', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(466, 34, 56, 'Set router/Wi-Fi/ wireless access point/repeater configuration', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(467, 34, 57, 'Inspect and test the configured computer networks', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(468, 34, 58, 'Set-up user access', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(469, 34, 59, 'Configure network services', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(470, 34, 60, 'Perform testing, documentation and pre- deployment practices', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(471, 34, 61, 'Plan and prepare for maintenance and repair', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(472, 34, 62, 'Maintain computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(473, 34, 63, 'Diagnose faults of computer systems', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(474, 34, 65, 'Inspect and test the computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:21', NULL, '2026-10-13 10:07:21', '2026-10-13 10:07:21', NULL, NULL),
(475, 36, 50, 'Assemble computer hardware', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(476, 36, 51, 'Install operating system and drivers for peripherals/ devices', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(477, 36, 52, 'Install the computer application software', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(478, 36, 53, 'Conduct testing and documentation', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(479, 36, 54, 'Install network cables', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(480, 36, 55, 'Set network configuration', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(481, 36, 56, 'Set router/Wi-Fi/ wireless access point/repeater configuration', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(482, 36, 57, 'Inspect and test the configured computer networks', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(483, 36, 58, 'Set-up user access', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(484, 36, 59, 'Configure network services', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(485, 36, 60, 'Perform testing, documentation and pre- deployment practices', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(486, 36, 61, 'Plan and prepare for maintenance and repair', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(487, 36, 62, 'Maintain computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(488, 36, 63, 'Diagnose faults of computer systems', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(489, 36, 65, 'Inspect and test the computer systems and networks', 'Done', 'complied', 1, '2026-10-13 10:07:27', NULL, '2026-10-13 10:07:27', '2026-10-13 10:07:27', NULL, NULL),
(490, 57, 107, 'Take table reservation', 'Done', 'complied', 1, '2026-10-13 10:07:44', NULL, '2026-10-13 10:07:44', '2026-10-13 10:07:44', NULL, NULL),
(491, 57, 108, 'Prepare service stations and equipment', 'Done', 'complied', 1, '2026-10-13 10:07:44', NULL, '2026-10-13 10:07:44', '2026-10-13 10:07:44', NULL, NULL),
(492, 57, 109, 'Set-up the tables in the dining area', 'Done', 'complied', 1, '2026-10-13 10:07:44', NULL, '2026-10-13 10:07:44', '2026-10-13 10:07:44', NULL, NULL),
(493, 57, 110, 'Set the mood/ambiance of the dining area', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(494, 57, 111, 'Welcome and greet guests', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(495, 57, 112, 'Seat the guest', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(496, 57, 113, 'Take food and beverage orders', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(497, 57, 114, 'Liaise between kitchen and service areas', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(498, 57, 115, 'Know the product', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(499, 57, 116, 'Undertake Suggestive selling', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(500, 57, 117, 'Carry out Upselling strategies', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(501, 57, 118, 'Serve food orders', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(502, 57, 119, 'Assist the diners', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(503, 57, 120, 'Perform banquet or catering food service', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(504, 57, 121, 'Serve beverage orders', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(505, 57, 122, 'Process payments and receipts', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(506, 57, 123, 'Conclude food service and close down dining area', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(507, 57, 124, 'Manage intoxicated persons', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(508, 57, 125, 'Take and process room service orders', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(509, 57, 126, 'Set up trays and trolleys', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(510, 57, 127, 'Present and serve food and beverage orders to guests', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(511, 57, 128, 'Present room service account', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(512, 57, 129, 'Clear away room service equipment', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(513, 57, 130, 'Listen to the complaint', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(514, 57, 131, 'Apologize to the guest', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(515, 57, 132, 'Take proper action on the complaint', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(516, 57, 133, 'Record complaint', 'Done', 'complied', 1, '2026-10-13 10:07:45', NULL, '2026-10-13 10:07:45', '2026-10-13 10:07:45', NULL, NULL),
(517, 55, 107, 'Take table reservation', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(518, 55, 108, 'Prepare service stations and equipment', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(519, 55, 109, 'Set-up the tables in the dining area', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(520, 55, 110, 'Set the mood/ambiance of the dining area', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(521, 55, 111, 'Welcome and greet guests', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(522, 55, 112, 'Seat the guest', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(523, 55, 113, 'Take food and beverage orders', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(524, 55, 114, 'Liaise between kitchen and service areas', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(525, 55, 115, 'Know the product', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(526, 55, 116, 'Undertake Suggestive selling', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(527, 55, 117, 'Carry out Upselling strategies', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(528, 55, 118, 'Serve food orders', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(529, 55, 119, 'Assist the diners', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(530, 55, 120, 'Perform banquet or catering food service', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(531, 55, 121, 'Serve beverage orders', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(532, 55, 122, 'Process payments and receipts', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(533, 55, 123, 'Conclude food service and close down dining area', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(534, 55, 124, 'Manage intoxicated persons', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(535, 55, 125, 'Take and process room service orders', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(536, 55, 126, 'Set up trays and trolleys', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(537, 55, 127, 'Present and serve food and beverage orders to guests', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(538, 55, 128, 'Present room service account', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(539, 55, 129, 'Clear away room service equipment', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(540, 55, 130, 'Listen to the complaint', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(541, 55, 131, 'Apologize to the guest', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(542, 55, 132, 'Take proper action on the complaint', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(543, 55, 133, 'Record complaint', 'Done', 'complied', 1, '2026-10-13 10:07:49', NULL, '2026-10-13 10:07:49', '2026-10-13 10:07:49', NULL, NULL),
(544, 48, 134, 'Identify required items before pre- delivery inspection on the vehicle', 'Done', 'complied', 1, '2026-10-13 11:34:10', NULL, '2026-10-13 11:34:10', '2026-10-13 11:34:10', NULL, NULL),
(545, 48, 135, 'Prepare required items in the vehicle', 'Done', 'complied', 1, '2026-10-13 11:34:11', NULL, '2026-10-13 11:34:11', '2026-10-13 11:34:11', NULL, NULL),
(546, 48, 136, 'Install factory-loaded parts on the vehicle', 'Done', 'complied', 1, '2026-10-13 11:34:15', NULL, '2026-10-13 11:34:15', '2026-10-13 11:34:15', NULL, NULL),
(547, 48, 137, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', 'Done', 'complied', 1, '2026-10-13 11:34:17', NULL, '2026-10-13 11:34:17', '2026-10-13 11:34:17', NULL, NULL),
(548, 48, 139, 'Determine job requirements from workplace instructions', 'Done', 'complied', 1, '2026-10-13 11:34:20', NULL, '2026-10-13 11:34:20', '2026-10-13 11:34:20', NULL, NULL),
(549, 48, 140, 'Source and interpret servicing information', 'Done', 'complied', 1, '2026-10-13 11:34:23', NULL, '2026-10-13 11:34:23', '2026-10-13 11:34:23', NULL, NULL),
(550, 48, 143, 'Determine job requirements', 'Done', 'complied', 1, '2026-10-13 11:34:26', NULL, '2026-10-13 11:34:26', '2026-10-13 11:34:26', NULL, NULL),
(551, 48, 138, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(552, 48, 141, 'Identify hazards associated with the work and manage risks', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(553, 48, 142, 'Select tools, equipment and materials and check it’s serviceability', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(554, 48, 144, 'Source servicing information from service manual', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(555, 48, 145, 'Use vehicle mileage as reference for changing fluid', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(556, 48, 146, 'Inspect transmission fluids condition', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(557, 48, 147, 'Acquire transmission fluids', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(558, 48, 148, 'Prepare tools for periodic maintenance of drive train', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(559, 48, 149, 'Manage hazards and risks', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(560, 48, 150, 'Demonstrate Job requirements are determined based on brake system repair order', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(561, 48, 151, 'Explain the service information sourced from the service manual', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(562, 48, 152, 'Prepare tools based on suspension system repair order', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(563, 48, 153, 'Explain hazards and risks associated in the workplace are managed following OSHS', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(564, 48, 154, 'Job and inspection performed is written/noted down on the repair order.', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(565, 48, 155, 'State the basic function of the suspension system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(566, 48, 156, 'Identify the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(567, 48, 157, 'Explain the features of the different types of suspension system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(568, 48, 158, 'Identify the tools, equipment and materials required to service suspension system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(569, 48, 159, 'Identify the different safety precautions, hazards and risks when servicing suspension system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(570, 48, 160, 'Prepare tools, materials and equipment to be used for suspension system service', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(571, 48, 161, 'State the basic function of the steering system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(572, 48, 162, 'Identify the different types of steering system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(573, 48, 163, 'Identify the tools, equipment and materials required to service steering system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(574, 48, 164, 'Identify the different safety precautions, hazards and risks when servicing steering system', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(575, 48, 165, 'Prepare tools, materials and equipment to be used for steering system service', 'Done', 'complied', 1, '2026-10-13 14:56:07', NULL, '2026-10-13 14:56:07', '2026-10-13 14:56:07', NULL, NULL),
(576, 63, 109, 'Set-up the tables in the dining area', 'Done', 'complied', 1, '2026-10-13 15:18:13', NULL, '2026-10-13 15:18:13', '2026-10-13 15:18:13', NULL, NULL),
(577, 63, 107, 'Take table reservation', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(578, 63, 108, 'Prepare service stations and equipment', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(579, 63, 110, 'Set the mood/ambiance of the dining area', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(580, 63, 111, 'Welcome and greet guests', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(581, 63, 112, 'Seat the guest', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(582, 63, 113, 'Take food and beverage orders', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(583, 63, 114, 'Liaise between kitchen and service areas', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(584, 63, 115, 'Know the product', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(585, 63, 116, 'Undertake Suggestive selling', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(586, 63, 117, 'Carry out Upselling strategies', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(587, 63, 118, 'Serve food orders', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(588, 63, 119, 'Assist the diners', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(589, 63, 120, 'Perform banquet or catering food service', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(590, 63, 121, 'Serve beverage orders', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(591, 63, 122, 'Process payments and receipts', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(592, 63, 123, 'Conclude food service and close down dining area', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(593, 63, 124, 'Manage intoxicated persons', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(594, 63, 125, 'Take and process room service orders', 'Done', 'complied', 1, '2026-10-13 15:18:16', NULL, '2026-10-13 15:18:16', '2026-10-13 15:18:16', NULL, NULL),
(595, 63, 126, 'Set up trays and trolleys', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(596, 63, 127, 'Present and serve food and beverage orders to guests', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(597, 63, 128, 'Present room service account', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(598, 63, 129, 'Clear away room service equipment', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(599, 63, 130, 'Listen to the complaint', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(600, 63, 131, 'Apologize to the guest', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(601, 63, 132, 'Take proper action on the complaint', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL),
(602, 63, 133, 'Record complaint', 'Done', 'complied', 1, '2026-10-13 15:18:17', NULL, '2026-10-13 15:18:17', '2026-10-13 15:18:17', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notification_history`
--

CREATE TABLE `notification_history` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `trainee_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `trainee_email` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `trainer_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `trainer_id` int DEFAULT NULL,
  `notification_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `channel` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `status` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `qualification_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_history`
--

INSERT INTO `notification_history` (`id`, `trainee_id`, `trainee_name`, `trainee_email`, `department`, `trainer_name`, `trainer_id`, `notification_type`, `channel`, `message`, `status`, `sent_at`, `qualification_id`, `deleted_at`) VALUES
(1, 3, 'Kathleen Jill Bahibag', 'kathleenjilllonzaga@gmail.com', 'Automotive Servicing (ATS)', 'Roy Fernandez', 1, 'regular_reminder', 'SMS & Email', '\r\n            \r\n            \r\n            \r\n                \r\n                \r\n                TESDA Compliance Task Reminder\r\n            \r\n            \r\n\r\n                \r\n\r\n                    \r\n                        \r\n                            \r\n                                TESDA Compliance\r\n                                Performance Task Tracking System\r\n                            \r\n                            \r\n                                Task Reminder\r\n                     ', 'Sent', '2026-10-13 18:03:58', '6', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notified_logs`
--

CREATE TABLE `notified_logs` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `task_id` int NOT NULL,
  `notification_channel` enum('SMS','Email','SMS & Email','System Alert') COLLATE utf8mb4_unicode_ci DEFAULT 'SMS & Email',
  `delivery_status` enum('Sent','Pending','Failed') COLLATE utf8mb4_unicode_ci DEFAULT 'Sent',
  `sent_by` int DEFAULT NULL,
  `date_sent` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `returned_to_evaluation` tinyint(1) DEFAULT '0',
  `returned_at` datetime DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notified_logs`
--

INSERT INTO `notified_logs` (`id`, `trainee_id`, `task_id`, `notification_channel`, `delivery_status`, `sent_by`, `date_sent`, `returned_to_evaluation`, `returned_at`, `deleted_at`) VALUES
(1, 3, 12, 'SMS & Email', 'Sent', 1, '2026-10-13 10:03:58', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `performance_tasks`
--

CREATE TABLE `performance_tasks` (
  `id` int NOT NULL,
  `competency_id` int DEFAULT NULL,
  `task_name` text COLLATE utf8mb4_general_ci,
  `code_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `performance_tasks`
--

INSERT INTO `performance_tasks` (`id`, `competency_id`, `task_name`, `code_no`) VALUES
(1, 118, 'Test starting system components and identify faults', ''),
(2, 118, 'Disassemble starter', ''),
(3, 118, 'Repair/replace and assemble starter components/parts', ''),
(4, 119, 'Test charging system components and identify faults', ''),
(5, 119, 'Disassemble alternator', ''),
(6, 119, 'Repair/replace and assemble alternator components/parts', ''),
(7, 120, 'Explain coolant circulation within cooling system', ''),
(8, 120, 'Check thermostat condition', ''),
(9, 120, 'Perform leakage test on cooling system', ''),
(10, 120, 'Repair/replace water pump', ''),
(11, 120, 'Apply coolant additives', ''),
(12, 117, 'Diagnose clutch failure/problem', ''),
(13, 117, 'Pull out and mount clutch component parts', ''),
(14, 117, 'Dismantle/install clutch mechanism', ''),
(15, 117, 'Overhaul hydraulic clutch mechanism', ''),
(16, 117, 'Perform clutch parts failure analysis', ''),
(17, 117, 'Set/adjust clutch system components', ''),
(18, 117, 'Bleed clutch hydraulic system', ''),
(19, 122, 'Inspect drive shaft and joint', ''),
(20, 122, 'Dismount and mount', ''),
(21, 122, 'Dismantle and assemble differential parts', ''),
(22, 122, 'Diagnose differential parts failure', ''),
(23, 122, 'Set/adjust differential components', ''),
(24, 122, 'Overhaul rear/front wheel hub', ''),
(25, 122, 'Analyze king pin condition', ''),
(26, 122, 'Inspect/replace I-beam', ''),
(27, 123, 'Service steering system', ''),
(28, 123, 'Diagnose power steering system failure', ''),
(29, 123, 'Adjust/align tie-rod end to wheel', ''),
(30, 123, 'Dismantle/install steering components', ''),
(31, 123, 'Conduct wheel alignment', ''),
(32, 124, 'Identify types of transmission and components', ''),
(33, 124, 'Testing transmission', ''),
(34, 124, 'Pull out and dismount manual transmission and associate component', ''),
(35, 124, 'Disassemble manual transmission', ''),
(36, 124, 'Assemble manual transmission component', ''),
(37, 125, 'Component of hydraulic brake system (ABS).', ''),
(38, 125, 'Function of brake', ''),
(39, 125, 'Operating principle of Anti-Lock Brake system (ABS)', ''),
(40, 125, 'Types and classification of brakes (ABS)', ''),
(41, 125, 'ABS operation and manufacturer specifications', ''),
(42, 125, 'Checking electronic control unit (ECU)', ''),
(43, 125, 'Checking ABS hydraulic pump', ''),
(44, 125, 'ABS schematic diagrams', ''),
(45, 126, 'Identify the type of suspension system, component and its operating principle (conventional and air suspension)', ''),
(46, 126, 'Diagnose suspension system failure', ''),
(47, 126, 'Disassemble and assemble suspension system (conventional type)', ''),
(48, 126, 'Inspect and replace suspension system component (conventional type)', ''),
(49, 126, 'Service air suspension system', ''),
(50, 127, 'Assemble computer hardware', ''),
(51, 127, 'Install operating system and drivers for peripherals/ devices', ''),
(52, 127, 'Install the computer application software', ''),
(53, 127, 'Conduct testing and documentation', ''),
(54, 128, 'Install network cables', ''),
(55, 128, 'Set network configuration', ''),
(56, 128, 'Set router/Wi-Fi/ wireless access point/repeater configuration', ''),
(57, 128, 'Inspect and test the configured computer networks', ''),
(58, 129, 'Set-up user access', ''),
(59, 129, 'Configure network services', ''),
(60, 129, 'Perform testing, documentation and pre- deployment practices', ''),
(61, 130, 'Plan and prepare for maintenance and repair', ''),
(62, 130, 'Maintain computer systems and networks', ''),
(63, 130, 'Diagnose faults of computer systems', ''),
(65, 130, 'Inspect and test the computer systems and networks', ''),
(66, 131, 'Prepare to assemble electronics products', ''),
(67, 131, 'Prepare/ Make PCB modules', ''),
(68, 131, 'Mount and solder electronic components', ''),
(69, 131, 'Assemble electronic products', ''),
(70, 131, 'Test and inspect assembled electronic products', ''),
(71, 132, 'Prepare unit, tools and workplace for installation and service', ''),
(72, 132, 'Install consumer electronic products and systems', ''),
(73, 132, 'Diagnose faults and defects consumer electronic products and systems', ''),
(74, 132, 'Maintain/Repair consumer electronic products', ''),
(75, 132, 'Re-assemble and test repaired consumer electronic products', ''),
(76, 133, 'Prepare unit, tools and workplace for installation and service', ''),
(77, 133, 'Install industrial electronic modules/products/systems', ''),
(78, 133, 'Diagnose faults and defects of industrial electronic modules/systems/products', ''),
(79, 133, 'Maintain/Repair industrial electronic products', ''),
(80, 133, 'Re-assemble and test repaired industrial electronics products', ''),
(85, 135, 'Set up equipment and trolleys', ''),
(86, 135, 'Access rooms for servicing', ''),
(87, 135, 'Make up beds', ''),
(88, 135, 'Clean and clear rooms', ''),
(89, 135, 'Clean and store trolleys and equipment', ''),
(90, 136, 'Provide Housekeeping Services to Guests', ''),
(91, 136, 'Provide/Service housekeeping requests', ''),
(92, 136, 'Provide/Service housekeeping requests', ''),
(93, 136, 'Liaise with other departments', ''),
(94, 137, 'Provide valet services to guests', ''),
(95, 137, 'Display professional valet standards', ''),
(96, 137, 'Care for guest property', ''),
(97, 138, 'Collect laundry for laundering functions', ''),
(98, 138, 'Perform laundering functions', ''),
(99, 138, 'Process laundered item', ''),
(100, 138, 'Process laundered item', ''),
(101, 139, 'Select and set up equipment and materials', ''),
(102, 139, 'Apply cleaning technique 5.3	Clean dry and wet areas', ''),
(103, 139, 'Maintain and store cleaning equipment and chemicals', ''),
(104, 140, 'Determine the level of intoxication', ''),
(105, 140, 'Apply appropriate procedures', ''),
(106, 140, 'Comply with legislation', ''),
(107, 141, 'Take table reservation', ''),
(108, 141, 'Prepare service stations and equipment', ''),
(109, 141, 'Set-up the tables in the dining area', ''),
(110, 141, 'Set the mood/ambiance of the dining area', ''),
(111, 142, 'Welcome and greet guests', ''),
(112, 142, 'Seat the guest', ''),
(113, 142, 'Take food and beverage orders', ''),
(114, 142, 'Liaise between kitchen and service areas', ''),
(115, 143, 'Know the product', ''),
(116, 143, 'Undertake Suggestive selling', ''),
(117, 143, 'Carry out Upselling strategies', ''),
(118, 144, 'Serve food orders', ''),
(119, 144, 'Assist the diners', ''),
(120, 144, 'Perform banquet or catering food service', ''),
(121, 144, 'Serve beverage orders', ''),
(122, 144, 'Process payments and receipts', ''),
(123, 144, 'Conclude food service and close down dining area', ''),
(124, 144, 'Manage intoxicated persons', ''),
(125, 145, 'Take and process room service orders', ''),
(126, 145, 'Set up trays and trolleys', ''),
(127, 145, 'Present and serve food and beverage orders to guests', ''),
(128, 145, 'Present room service account', ''),
(129, 145, 'Clear away room service equipment', ''),
(130, 146, 'Listen to the complaint', ''),
(131, 146, 'Apologize to the guest', ''),
(132, 146, 'Take proper action on the complaint', ''),
(133, 146, 'Record complaint', ''),
(134, 147, 'Identify required items before pre- delivery inspection on the vehicle', ''),
(135, 147, 'Prepare required items in the vehicle', ''),
(136, 147, 'Install factory-loaded parts on the vehicle', ''),
(137, 147, 'Removal of emergency towing eyelets, spring locks, labels, tags, stickers, covers and body protective films', ''),
(138, 147, 'Adjust tire pressure into standard pressure based on manufacturer’s standards', ''),
(139, 148, 'Determine job requirements from workplace instructions', ''),
(140, 148, 'Source and interpret servicing information', ''),
(141, 148, 'Identify hazards associated with the work and manage risks', ''),
(142, 148, 'Select tools, equipment and materials and check it’s serviceability', ''),
(143, 149, 'Determine job requirements', ''),
(144, 149, 'Source servicing information from service manual', ''),
(145, 149, 'Use vehicle mileage as reference for changing fluid', ''),
(146, 149, 'Inspect transmission fluids condition', ''),
(147, 149, 'Acquire transmission fluids', ''),
(148, 149, 'Prepare tools for periodic maintenance of drive train', ''),
(149, 149, 'Manage hazards and risks', ''),
(150, 150, 'Demonstrate Job requirements are determined based on brake system repair order', ''),
(151, 150, 'Explain the service information sourced from the service manual', ''),
(152, 150, 'Prepare tools based on suspension system repair order', ''),
(153, 150, 'Explain hazards and risks associated in the workplace are managed following OSHS', ''),
(154, 150, 'Job and inspection performed is written/noted down on the repair order.', ''),
(155, 151, 'State the basic function of the suspension system', ''),
(156, 151, 'Identify the different types of suspension system', ''),
(157, 151, 'Explain the features of the different types of suspension system', ''),
(158, 151, 'Identify the tools, equipment and materials required to service suspension system', ''),
(159, 151, 'Identify the different safety precautions, hazards and risks when servicing suspension system', ''),
(160, 151, 'Prepare tools, materials and equipment to be used for suspension system service', ''),
(161, 152, 'State the basic function of the steering system', ''),
(162, 152, 'Identify the different types of steering system', ''),
(163, 152, 'Identify the tools, equipment and materials required to service steering system', ''),
(164, 152, 'Identify the different safety precautions, hazards and risks when servicing steering system', ''),
(165, 152, 'Prepare tools, materials and equipment to be used for steering system service', '');

-- --------------------------------------------------------

--
-- Table structure for table `pre_assessment`
--

CREATE TABLE `pre_assessment` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `trainee_name` varchar(255) DEFAULT NULL,
  `qualification_id` int DEFAULT NULL,
  `qualification_name` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `moved_by` int DEFAULT NULL,
  `moved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pre_assessment`
--

INSERT INTO `pre_assessment` (`id`, `trainee_id`, `trainee_name`, `qualification_id`, `qualification_name`, `status`, `moved_by`, `moved_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(3, 1, 'Michelle Barba', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-12 14:58:03', '2026-10-12 14:58:03', '2026-10-12 14:58:03', NULL),
(5, 5, 'Leonard Escuban', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 13:38:30', '2026-10-13 13:38:30', '2026-10-13 13:38:30', NULL),
(6, 8, 'Rejean Intinado', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 14:27:11', '2026-10-13 14:27:11', '2026-10-13 14:27:11', NULL),
(7, 6, 'Jun Rylee Amania', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 14:27:16', '2026-10-13 14:27:16', '2026-10-13 14:27:16', NULL),
(8, 12, 'Johan Franz Nator', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 14:27:21', '2026-10-13 14:27:21', '2026-10-13 14:27:21', NULL),
(9, 14, 'Ronielyn Alon', 1, 'Housekeeping NC II', 'pending', 1, '2026-10-13 14:43:54', '2026-10-13 14:43:54', '2026-10-13 14:43:54', NULL),
(10, 21, 'Harold Buenavista', 1, 'Housekeeping NC II', 'pending', 1, '2026-10-13 14:43:59', '2026-10-13 14:43:59', '2026-10-13 14:43:59', NULL),
(12, 27, 'Louigie Celedona', 6, 'Automotive Servicing NC II', 'pending', 1, '2026-10-13 14:53:42', '2026-10-13 14:53:42', '2026-10-13 14:53:42', NULL),
(13, 45, 'Lester De Los Santos', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-13 17:58:06', '2026-10-13 17:58:06', '2026-10-13 17:58:06', NULL),
(14, 46, 'Warren Vince', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-13 17:58:12', '2026-10-13 17:58:12', '2026-10-13 17:58:12', NULL),
(15, 52, 'Glenn Banasing', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-13 17:58:17', '2026-10-13 17:58:17', '2026-10-13 17:58:17', NULL),
(16, 47, 'Bob Marley Pugoy', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-13 17:58:24', '2026-10-13 17:58:24', '2026-10-13 17:58:24', NULL),
(17, 11, 'Faulen Joy Pingcas', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 18:05:11', '2026-10-13 18:05:11', '2026-10-13 18:05:11', NULL),
(18, 4, 'Dusten Recopelacion', 4, 'Electronic Products Assembly and Servicing NC II', 'pending', 1, '2026-10-13 18:05:16', '2026-10-13 18:05:16', '2026-10-13 18:05:16', NULL),
(19, 35, 'Anarose Biernas', 3, 'Computer Systems Servicing NC II', 'pending', 1, '2026-10-13 18:07:06', '2026-10-13 18:07:06', '2026-10-13 18:07:06', NULL),
(20, 37, 'Julita Compania', 3, 'Computer Systems Servicing NC II', 'pending', 1, '2026-10-13 18:07:12', '2026-10-13 18:07:12', '2026-10-13 18:07:12', NULL),
(21, 34, 'Leah Mae Lape', 3, 'Computer Systems Servicing NC II', 'pending', 1, '2026-10-13 18:07:21', '2026-10-13 18:07:21', '2026-10-13 18:07:21', NULL),
(22, 36, 'Jobel Naval', 3, 'Computer Systems Servicing NC II', 'pending', 1, '2026-10-13 18:07:27', '2026-10-13 18:07:27', '2026-10-13 18:07:27', NULL),
(23, 57, 'Lyca Mae Bendivel', 2, 'Food and Beverage Services NC II', 'pending', 1, '2026-10-13 18:07:45', '2026-10-13 18:07:45', '2026-10-13 18:07:45', NULL),
(24, 55, 'Camelle Caborog', 2, 'Food and Beverage Services NC II', 'pending', 1, '2026-10-13 18:07:49', '2026-10-13 18:07:49', '2026-10-13 18:07:49', NULL),
(25, 48, 'Vince John Del Rosario', 5, 'Automotive Servicing NC I', 'pending', 1, '2026-10-13 22:56:07', '2026-10-13 22:56:07', '2026-10-13 22:56:07', NULL),
(26, 63, 'Salcy Kim Destura', 2, 'Food and Beverage Services NC II', 'pending', 1, '2026-10-13 23:18:17', '2026-10-13 23:18:17', '2026-10-13 23:18:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `qualifications`
--

CREATE TABLE `qualifications` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `qualification_name` varchar(255) NOT NULL,
  `qualification_code` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `coordinator_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `qualifications`
--

INSERT INTO `qualifications` (`id`, `course_id`, `qualification_name`, `qualification_code`, `created_at`, `updated_at`, `coordinator_id`) VALUES
(1, 1, 'Housekeeping NC II', 'HK', '2026-08-22 05:32:17', '2026-09-25 09:02:37', NULL),
(2, 1, 'Food and Beverage Services NC II', 'FBS', '2026-08-22 05:32:17', '2026-09-25 09:02:51', NULL),
(3, 2, 'Computer Systems Servicing NC II', 'CSS', '2026-08-22 05:32:24', '2026-09-25 09:03:02', NULL),
(4, 2, 'Electronic Products Assembly and Servicing NC II', 'EPAS', '2026-08-22 05:32:24', '2026-09-25 09:03:17', NULL),
(5, 3, 'Automotive Servicing NC I', 'AS1', '2026-08-22 05:32:30', '2026-09-25 09:03:28', NULL),
(6, 3, 'Automotive Servicing NC II', 'AS2', '2026-08-22 05:32:30', '2026-09-25 09:03:39', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_general_ci,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES
(1, 'school_name', 'USAT AMPARO', 1, '2026-09-29 11:44:53'),
(2, 'school_address', 'National Highway, Sagay City, Negros Occidental', 1, '2026-04-19 14:06:42'),
(3, 'school_email', 'USATCOLLEGE@GMAIL.COM', 1, '2026-04-19 14:06:42'),
(4, 'school_phone', '09069314722', 1, '2026-04-19 14:06:57');

-- --------------------------------------------------------

--
-- Table structure for table `trainees`
--

CREATE TABLE `trainees` (
  `id` int NOT NULL,
  `reference_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `middle_initial` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `suffix` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `section` enum('A','B') NOT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `address` text,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `contact` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `qualification_id` int DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `pre_assessment_notified` tinyint(1) DEFAULT '0',
  `completion_alert_sent` tinyint(1) DEFAULT '0',
  `completion_alert_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainees`
--

INSERT INTO `trainees` (`id`, `reference_id`, `first_name`, `middle_initial`, `last_name`, `suffix`, `section`, `sex`, `birthdate`, `address`, `email`, `contact`, `created_at`, `department`, `qualification_id`, `status`, `deleted_at`, `pre_assessment_notified`, `completion_alert_sent`, `completion_alert_sent_at`) VALUES
(2, 'TESDA-0001-02', 'PearlMarie', 'B.', 'Jablo', '', 'A', 'Female', '2004-10-04', 'Villacen, Cadiz City', 'jablopearlmarie423@gmail.com', '09544129399', '2026-10-12 08:03:34', 'ATS', 5, 'In Progress', NULL, 0, 0, NULL),
(3, 'TESDA-0001-03', 'Kathleen Jill', '', 'Bahibag', '', 'A', 'Female', '2003-09-24', 'San Isidro, Toboso, Negros Occidental', 'kathleenjilllonzaga@gmail.com', '09150682635', '2026-10-12 08:11:43', 'ATS', 6, 'In Progress', NULL, 0, 0, NULL),
(4, 'RDC-06-385-06045-001', 'Dusten', 'S', 'Recopelacion', '', 'A', 'Male', '2006-10-25', 'Brgy, Salamanca, Toboso, Negros Occidental', 'recopelaciondusten49@gmail.com', '09776542764', '2026-10-13 04:33:24', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(5, 'ELP-07-210-06045-00', 'Leonard', 'P.', 'Escuban', '', 'A', 'Male', '2007-06-10', 'Pob I, Sagay City, Negros Occidental', 'leonardescuban@gmail.com', '09568890755', '2026-10-13 04:37:49', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(6, 'AJD-07-230-13088-001', 'Jun Rylee', 'D.', 'Amania', '', 'B', 'Male', '2005-06-30', 'St. Joseph Village, Pob II, Sagay City, Negros Occidental', 'amaniadiongsonrylee@gmail.com', '09774284882', '2026-10-13 04:40:20', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(7, 'LR-03-945-06045-001', 'Rowella', '', 'Villaester', '', 'A', 'Female', '2004-12-05', 'Brgy Sicaba, Cadiz City, Negros Occidental', 'rowellavillaester098@gmail.com', '09071200492', '2026-10-13 04:43:18', 'CSS', 4, NULL, NULL, 0, 0, NULL),
(8, 'IRG-06-581-06045-001', 'Rejean', 'G.', 'Intinado', '', 'A', 'Female', '2006-06-19', 'Brgy Marcelo, Calatrava, Negros Occidental', 'jeanintinado78@gmail.com', '09465308068', '2026-10-13 05:01:51', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(9, 'CMC-95-137-06045-001', 'Marvin', 'C.', 'Cosca', '', 'B', 'Male', '1995-04-17', 'Pob II, Sagay City, Negros Occidental', 'coscamarvin789', '09481493524', '2026-10-13 05:17:41', 'CSS', 4, NULL, NULL, 0, 0, NULL),
(10, 'AP-03-281-06045-001', 'Patricia', '', 'Abarquez', '', 'B', 'Female', '2003-08-01', 'Brgy Daga, Cadiz City, Negros Occidental', 'patriciaabarquez908', '09369412706', '2026-10-13 05:22:05', 'CSS', 4, NULL, NULL, 0, 0, NULL),
(11, 'PFP-06-889-06045-001', 'Faulen Joy', 'P.', 'Pingcas', '', 'A', 'Female', '2006-10-29', 'Brgy Bato, Sagay City, Negros Occidental', 'pingcasjoy675@gmail.com', '09093448358', '2026-10-13 05:25:56', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(12, 'NJP-07-633-06045-001', 'Johan Franz', 'P.', 'Nator', '', 'B', 'Male', '2007-04-13', 'Brgy Bato, Sagay City Negros Occidental', 'natorfranz675', '09503386246', '2026-10-13 05:29:59', 'CSS', 4, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(13, 'MMM-06-848-06045-001', 'Merrianne', 'M.', 'Montefalco', '', 'B', 'Female', '2006-09-28', 'Brgy Rizal, Sagay City, Negros Occidental', 'montefalcomerian@gmail.com', '095101739606', '2026-10-13 05:33:40', 'CSS', 4, NULL, NULL, 0, 0, NULL),
(14, 'ARI-03-848-06045-001', 'Ronielyn', 'I.', 'Alon', '', 'A', 'Female', '2003-09-28', 'Brgy Bug-ang, Toboso, Negros Occidental', 'ronielyninitia896@gmail.com', '09525279554', '2026-10-13 05:37:57', 'HRS', 1, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(15, 'SJC-02-340-06045-001', 'Jan Stephen', 'C.', 'Salvaroza', '', 'A', 'Male', '2003-09-30', 'Brgy Bato, Sagay  City, Negros Occidental', 'salvarozaJan500@gmail.com', '09096887020', '2026-10-13 05:43:42', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(16, 'NMA-03-591-06045-001', 'Madel', 'A.', 'Nativo', '', 'A', 'Female', '2003-03-11', 'Brgy Minautok, Calatrava, Negros Occidental', 'madelnativo786@gmail.com', '09300075607', '2026-10-13 05:47:28', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(17, 'ABO-08-839-06045-001', 'Baby Grace', 'O.', 'Amacan', '', 'A', 'Female', '2008-09-19', 'Brgy Alimango, Escalante City, Negros Occidental', 'amacanbabygrace89@gmail.com', '09816480742', '2026-10-13 05:51:32', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(18, 'SRT-08-791-06045-001', 'Rose Mae', 'T.', 'Sufrir', '', 'A', 'Female', '2008-08-11', 'Brgy Alimango, Escalante City, Negros Occidental', 'sufrirrosemae@gmail.com', '09465187672', '2026-10-13 05:55:33', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(19, 'SSS-08-560-06045-001', 'Sofia Lorain', 'S.', 'Sabordo', '', 'B', 'Female', '2008-02-20', 'Brgy Balintawak, Escalante City, Negros Occidental', 'sabordosofialorain@gmail.com', '090545647011', '2026-10-13 06:00:11', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(20, 'AJC-05-018-06045-001', 'Jay', 'C.', 'Alforque', '', 'B', 'Male', '2005-01-18', 'Brgy Alimango, Escalante City, Negros Occidental', 'alforquejay63@gmail.com', '09128080379', '2026-10-13 06:04:46', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(21, 'BHI-08-045-06045-001', 'Harold', 'I.', 'Buenavista', '', 'B', 'Male', '2008-02-05', 'HDA.Fe, Escalante City, Negros Occidental', 'buenavistaharold765@gmail.com', '094553042481', '2026-10-13 06:08:11', 'HRS', 1, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(22, 'CAA-05-544-06045-001', 'Annabelle', 'A.', 'Camingawan', '', 'B', 'Female', '2005-02-04', 'Brgy Washington, Escalante City, Negros Occidental', 'camingawanannabelle421@gmail.com', '09351502275', '2026-10-13 06:12:12', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(23, 'DKE-94-131-06045-001', 'Kent John', 'E.', 'Demogena', '', 'B', 'Male', '1994-04-01', 'Brgy Barrera, Sagay City', 'demogenakentjohn2@gmail.com', '09812113699', '2026-10-13 06:17:05', 'HRS', 1, NULL, NULL, 0, 0, NULL),
(24, 'RDB-05-256-06045-001', 'Dominador', 'B.', 'Rosario', '', 'A', 'Male', '2005-07-16', 'Brgy Old Poblacion Escalante City, Negros Occidental', 'rosariodominador@gmail.com', '09207302761', '2026-10-13 06:22:44', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(25, 'GWM-99-095-07022-001', 'Wezmuellher', 'M.', 'Jurado', '', 'A', 'Male', '2007-08-01', 'Brgy, Marcelo, Calatrava, Negros Occidental', 'juradowezmuellher@gmail.com', '09943558738', '2026-10-13 06:26:16', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(26, 'EJV-04-338-06045-001', 'Jheremy', 'V.', 'Ebro', '', 'A', 'Male', '2004-09-18', 'Brgy Paraiso, Sagay City, Negros Occidental', 'ebrojheremyvargas@gmail.com', '09659987624', '2026-10-13 06:30:13', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(27, 'CLM-07-947-06045-001', 'Louigie', 'M.', 'Celedona', '', 'A', 'Male', '2007-01-07', 'Brgy Old Poblacion, Escalante, Negros Occidental', 'louigieceledona785@gmail.com', '09916296253', '2026-10-13 06:33:04', 'ATS', 6, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(28, 'RRB-07-136-06045-001', 'Reejay Nel', 'B.', 'Rivera', '', 'A', 'Male', '2007-04-16', 'Brgy Mabini, Escalante City, Negros Occidental', 'templadoreejay51@gmail.com', '09172140000', '2026-10-13 06:37:06', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(29, 'TJS-07-045-06045-001', 'Jeff Andrie', 'S.', 'Templado', '', 'B', 'Male', '2007-07-25', 'Brgy Japitan, Escalante City, Negros Occidental', 'jeffandrietemplado@gmail.com', '09276053814', '2026-10-13 06:39:44', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(30, 'PAB-07-309-06045-001', 'Alfred', 'B.', 'Perez', '', 'B', 'Male', '2007-08-29', 'Brgy Old Poblacion, Escalante City, Negros Occidental', 'perezjairga@gmail.com', '09946003003', '2026-10-13 06:43:07', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(31, 'MRI-07-445-06045-001', 'Rean', 'I.', 'Mapa', '', 'B', 'Male', '2007-01-25', 'Brgy Old Poblacion, Escalante City, Negros Occidental', 'maparean43@gmail.com', '09708254367', '2026-10-13 06:47:11', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(32, 'PMV-06-060-06045-001', 'Marl Anthony', 'V.', 'Patino', '', 'B', 'Male', '2006-02-20', 'Brgy Bulanon, Sagay City, Negros Occidental', 'patinomarlanthony@gmail.com', '09708254367', '2026-10-13 06:50:06', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(33, 'AJG-07-061-06045-001', 'Jay', 'G.', 'Amacna', '', 'B', 'Male', '2007-02-21', 'Brgy Bulanon, Sagay City, Negros Occidental', 'jayamacan674@gmail.com', '09708254367', '2026-10-13 06:52:55', 'ATS', 6, NULL, NULL, 0, 0, NULL),
(34, 'LLC-06-930-06045-001', 'Leah Mae', 'C.', 'Lape', '', 'A', 'Female', '2006-11-30', 'Brgy Bato, Sagay City, Negros Occidental', 'lapeleahmae76@gmil.com', '09917503821', '2026-10-13 07:30:58', 'CSS', 3, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(35, 'BAA-05-606-06045-001', 'Anarose', 'A.', 'Biernas', '', 'A', 'Female', '2005-03-26', 'Brgy Campo Himogaan, Sagay City, Negros Occidental', 'biernasanrose74@gmail.com', '09941505465', '2026-10-13 07:34:54', 'CSS', 3, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(36, 'NJM-05-092-06045-001', 'Jobel', 'M.', 'Naval', '', 'A', 'Female', '2005-03-01', 'Brgy Andres Bonifacio, Cdiz  City, Negros Occidental', 'navaljobel510@gmail.com', '0935871709', '2026-10-13 07:37:10', 'CSS', 3, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(37, 'CJS-94-265-06045-001', 'Julita', 'S.', 'Compania', '', 'A', 'Female', '1994-07-25', 'Brgy Washington, Escalante City, Negros Ociidental', 'companiajulita31@gmail.com', '09105608331', '2026-10-13 07:39:29', 'CSS', 3, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(38, 'TJB-07-167-06045-001', 'James', 'B.', 'Tan', '', 'A', 'Male', '2001-05-01', 'Brgy Malasibog, Escalante City, Negros Occidental', 'tanjames000@gmail.com', '09972492334', '2026-10-13 07:41:26', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(39, 'CJG-05-178-06045-001', 'Joriedine', 'G.', 'Carabelio', '', 'B', 'Male', '2005-05-18', 'Brgy Tamlang, Escalante City, Negros Occidental', 'carabeliojayjay@gmail.com', '09556107407', '2026-10-13 07:43:47', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(40, 'MDC-007-382-06045-001', 'Dominic', 'C.', 'Mayordomo', '', 'B', 'Male', '2007-10-12', 'Brgy Minautok, Calatrava, Negros Occidental', 'dominicchu74@gmail.com', '0953408797', '2026-10-13 07:48:22', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(41, 'GSC-07-661-06045-0001', 'Chamae', 'S.', 'Geronan', '', 'B', 'Female', '2007-05-01', 'Brgy Mabini, Escalante City, Negros Occidental', 'charmaegeronan01@gmail.com', '095341784424', '2026-10-13 07:50:47', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(42, 'PAV-07-786-06045-001', 'Alyssa', 'V.', 'Perolena', '', 'B', 'Female', '2007-08-06', 'Brgy Old Sagay City, Negros Occidental', 'perolenaalyssa81@gmail.com', '09283201855', '2026-10-13 07:53:20', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(43, 'AHC-08-281-06045-001', 'Harley Quin', 'C.', 'Aplacador', '', 'B', 'Male', '2008-08-01', 'Brgy Minapasok, Calatrava, Negros Occidental', 'aplacadorquin234@gmail,com', '0931900332', '2026-10-13 07:55:51', 'CSS', 3, NULL, NULL, 0, 0, NULL),
(44, 'GJR-08177-06045-001', 'Joezel', 'R.', 'Guillero', '', 'A', 'Male', '2008-05-17', 'Brgy Libertad, Escalante City, Negros Occidental', 'guillerojoezel87@gmail.com', '09534153307', '2026-10-13 07:58:20', 'ATS', 5, NULL, NULL, 0, 0, NULL),
(45, 'DLV-08212-060045-001', 'Lester', 'V.', 'De Los Santos', '', 'A', 'Male', '2008-06-12', 'Brgy Paraiso, Sagay  City, Negros Occidental', 'delossantoslesterr@gmail.com', '09924836013', '2026-10-13 08:01:18', 'ATS', 5, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(46, 'VWR-08-297-06045', 'Warren', 'R.', 'Vince', '', 'A', 'Male', '2008-08-17', 'Brgy Libertad, Escalante City, Negros Occidental', 'vincewarren632@gmail.com', '09068952387', '2026-10-13 08:05:14', 'ATS', 5, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(47, 'PBC-07-108-06045-001', 'Bob Marley', 'C.', 'Pugoy', '', 'A', 'Male', '2007-03-28', 'Brgy Tinampa-an, Cadiz City, Negros Occidental', 'pugoybobmarleyyy@gmail.com', '09916250740', '2026-10-13 08:08:03', 'ATS', 5, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(48, 'DVD-08-226-06045-001', 'Vince John', 'B.', 'Del Rosario', '', 'A', 'Male', '2008-06-26', 'Brgy Tinampa-an, Cadiz  City, Negros Occidental', 'delrosariovincerosario@gmail.com', '09636179867', '2026-10-13 08:16:23', 'ATS', 5, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(49, 'CRS-05-416-06045-001', 'Regie', 'S.', 'Cornoso', '', 'B', 'Male', '2005-11-16', 'Brgy Purisima, Manapla, Negros Occidental', 'cornosoregie30@gmail.com', '09942689425', '2026-10-13 08:19:58', 'ATS', 5, NULL, NULL, 0, 0, NULL),
(50, 'RAH-07-110-0A6045-001', 'Aldren', 'H.', 'Rivera', '', 'B', 'Male', '2007-03-03', 'Brgy General Luna, Sagay  City, Negros Occidental', 'riveraaldren44@gmail.com', '09197500381', '2026-10-13 08:22:12', 'ATS', 5, NULL, NULL, 0, 0, NULL),
(51, 'ECC-08-542-06045-001', 'Catherine', 'C.', 'Elarcosa', '', 'B', 'Female', '2000-02-02', 'Brgy Washington,  Escalante City, Negros Occidental', 'elarcosacatherine864@gmail.com', '09687433090', '2026-10-13 08:24:47', 'ATS', 5, NULL, NULL, 0, 0, NULL),
(52, 'BGE-07-450-06045-001', 'Glenn', 'E.', 'Banasing', '', 'B', 'Male', '2007-12-10', 'Brgy Cabahug, Cadiz City, Negros Occidental', 'banasingglenn67@gmail.com', '09277353030', '2026-10-13 08:29:29', 'ATS', 5, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(53, 'AAE-08-338-06045-001', 'Anjilo', 'E.', 'Alova', '', 'B', 'Male', '2007-09-18', 'Brgy Tamlang, Escalante City, Negros Occidental', 'alovaanjilooo@gmail.com', '09859756478', '2026-10-13 08:32:47', 'ATS', 5, NULL, NULL, 0, 0, NULL),
(54, 'PKC-05-826-06045-001', 'Kate Nicol', 'C.', 'Pabalate', '', 'A', 'Female', '2005-09-06', 'Brgy Salamanca, Toboso, Negros Occidental', 'katenicolpabalate42@gmail.com', '09083743792', '2026-10-13 09:00:44', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(55, 'CCB-06-954-86045-001', 'Camelle', 'B.', 'Caborog', '', 'A', 'Female', '2006-12-14', 'Brgy Junob Junob, Escalante City, Negros Occidental', 'caborogcamelle@gmail.com', '09854655199', '2026-10-13 09:05:32', 'HRS', 2, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(56, 'TB-06-505-0645-001', 'Bb-Jean', '', 'Tingal', '', 'A', 'Female', '2006-01-05', 'Brgy Punta Cruz, Toboso, Negros Occidental', 'tingalbb-jean32@gmail.com', '09155048761', '2026-10-13 09:09:28', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(57, 'BLR-07-684-06045-001', 'Lyca Mae', 'R.', 'Bendivel', '', 'A', 'Female', '2007-05-24', 'Brgy Junob Junob, Escalante City, Negros Occidental', 'bendivellycamae54@gmail.com', '09519769212', '2026-10-13 09:12:34', 'HRS', 2, 'Ready for Pre-Assessment', NULL, 0, 0, NULL),
(58, 'DCA-06-374-06045-001', 'Charyss', 'A.', 'Deso-Asiso', '', 'A', 'Female', '2006-10-24', 'Brgy General Luna, Toboso, Negros Occidental', 'deso-asisocharyss34@gmail.com', '093177644533', '2026-10-13 09:15:05', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(59, 'PMT-04-722-06045-001', 'Marilyn', 'V.', 'Vendanillo', '', 'B', 'Female', '2006-06-22', 'Brgy Mascovado, Toboso,  Negros Occidental', 'vendanillomarilyn655@gmail.com', '09682458350', '2026-10-13 09:23:50', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(60, 'MEV-04-722-06045-001', 'Emalyn', 'T.', 'Mahusga', '', 'B', 'Female', '2004-06-22', 'Brgy Tadlong, Sagay City', 'mahusgaemalyn662gmail.com', '09157364295', '2026-10-13 09:32:53', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(61, 'PMC-04-051-13039-001', 'Christian James', 'M.', 'Pable', '', 'B', 'Male', '2004-02-11', 'Brgy Lopez, Sagay City, Negros Occidental', 'pablejames565@gmail.com', '09303794309', '2026-10-13 09:35:13', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(62, 'NCM-07-846-06045-001', 'Charis', 'M.', 'Negrido', '', 'B', 'Female', '2007-10-16', 'Brgy Mabini, Escalante, Negros Occidental', 'negridocharis@gmail.com', '09662355495', '2026-10-13 09:38:38', 'HRS', 2, NULL, NULL, 0, 0, NULL),
(63, 'DSL-07-785-06045-001', 'Salcy Kim', 'L.', 'Destura', '', 'B', 'Female', '2007-08-05', 'Brgy Mabini, Escalante City, Negros Occidental', 'desturasalcykim657@gmail.com', '0916978450', '2026-10-13 09:42:00', 'HRS', 2, 'Ready for Pre-Assessment', NULL, 0, 0, NULL);

--
-- Triggers `trainees`
--
DELIMITER $$
CREATE TRIGGER `trg_trainees_before_delete` BEFORE DELETE ON `trainees` FOR EACH ROW BEGIN     IF NOT EXISTS (         SELECT 1 FROM trainees_archive WHERE original_id = OLD.id     ) THEN         INSERT INTO trainees_archive (             original_id, first_name, middle_initial, last_name, suffix,             year_level, section, sex, email, contact, department,             qualification_id, status, pre_assessment_notified,             completion_alert_sent, completion_alert_sent_at,             created_at, deleted_at, archived_at, archive_reason, archived_by         ) VALUES (             OLD.id, OLD.first_name, OLD.middle_initial, OLD.last_name, OLD.suffix,             OLD.year_level, OLD.section, OLD.sex, OLD.email, OLD.contact,             OLD.department, OLD.qualification_id, OLD.status,             OLD.pre_assessment_notified, OLD.completion_alert_sent,             OLD.completion_alert_sent_at, OLD.created_at,             NOW(), NOW(), 'deleted', NULL         );     END IF; END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `trainees_archive`
--

CREATE TABLE `trainees_archive` (
  `id` int NOT NULL,
  `original_id` int NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_initial` varchar(5) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `section` enum('A','B') NOT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `qualification_id` int DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `pre_assessment_notified` tinyint(1) DEFAULT '0',
  `completion_alert_sent` tinyint(1) DEFAULT '0',
  `completion_alert_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archive_reason` enum('deleted','completed') NOT NULL DEFAULT 'deleted',
  `archived_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainees_archive`
--

INSERT INTO `trainees_archive` (`id`, `original_id`, `first_name`, `middle_initial`, `last_name`, `suffix`, `section`, `sex`, `email`, `contact`, `department`, `qualification_id`, `status`, `pre_assessment_notified`, `completion_alert_sent`, `completion_alert_sent_at`, `created_at`, `deleted_at`, `archived_at`, `archive_reason`, `archived_by`) VALUES
(1, 80, 'joshryan', 'A', 'mahusay', '', 'A', 'Male', 'joshfroztadeva@gmail.com', '09324145321', 'CSS', 3, 'In Progress', 0, 0, NULL, '2026-10-09 06:14:48', NULL, '2026-10-09 07:02:52', 'deleted', 9),
(2, 1, 'Michelle', 'B.', 'Barba', '', 'A', 'Female', 'barbamichelle2004@gmail.com', '09359703558', 'ATS', 6, 'Ready for Pre-Assessment', 0, 0, NULL, '2026-10-12 04:54:11', NULL, '2026-10-12 08:02:31', 'deleted', 1);

-- --------------------------------------------------------

--
-- Table structure for table `trainee_task_records`
--

CREATE TABLE `trainee_task_records` (
  `id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `task_id` int NOT NULL,
  `department` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` enum('A','B') COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_type` enum('Regular','Pre-Assessment') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Regular',
  `status` enum('Complied','Not Complied') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Complied',
  `evaluation_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `task_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_trainee_assignments`
--

CREATE TABLE `trainer_trainee_assignments` (
  `id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `trainee_id` int NOT NULL,
  `assigned_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `trainer_trainee_assignments`
--

INSERT INTO `trainer_trainee_assignments` (`id`, `trainer_id`, `trainee_id`, `assigned_date`, `is_active`) VALUES
(1, 8, 2, '2026-10-12 04:19:37', 1),
(4, 7, 3, '2026-10-12 08:11:43', 1),
(5, 4, 4, '2026-10-13 04:33:24', 1),
(6, 4, 5, '2026-10-13 04:37:49', 1),
(7, 4, 6, '2026-10-13 04:40:20', 1),
(8, 4, 7, '2026-10-13 04:43:18', 1),
(9, 4, 8, '2026-10-13 05:01:51', 1),
(10, 4, 9, '2026-10-13 05:17:41', 1),
(11, 4, 10, '2026-10-13 05:22:05', 1),
(12, 4, 11, '2026-10-13 05:25:56', 1),
(13, 4, 12, '2026-10-13 05:29:59', 1),
(14, 4, 13, '2026-10-13 05:33:40', 1),
(15, 5, 14, '2026-10-13 05:37:57', 1),
(16, 5, 15, '2026-10-13 05:43:42', 1),
(17, 5, 16, '2026-10-13 05:47:28', 1),
(18, 5, 17, '2026-10-13 05:51:32', 1),
(19, 5, 18, '2026-10-13 05:55:33', 1),
(20, 5, 19, '2026-10-13 06:00:11', 1),
(21, 5, 20, '2026-10-13 06:04:46', 1),
(22, 5, 21, '2026-10-13 06:08:11', 1),
(23, 5, 22, '2026-10-13 06:12:12', 1),
(24, 5, 23, '2026-10-13 06:17:05', 1),
(25, 7, 24, '2026-10-13 06:22:44', 1),
(26, 7, 25, '2026-10-13 06:26:16', 1),
(27, 7, 26, '2026-10-13 06:30:13', 1),
(28, 7, 27, '2026-10-13 06:33:04', 1),
(29, 7, 28, '2026-10-13 06:37:06', 1),
(30, 7, 29, '2026-10-13 06:39:44', 1),
(31, 7, 30, '2026-10-13 06:43:07', 1),
(32, 7, 31, '2026-10-13 06:47:11', 1),
(33, 7, 32, '2026-10-13 06:50:06', 1),
(34, 7, 33, '2026-10-13 06:52:55', 1),
(35, 9, 34, '2026-10-13 07:30:58', 1),
(36, 9, 35, '2026-10-13 07:34:54', 1),
(37, 9, 36, '2026-10-13 07:37:10', 1),
(38, 9, 37, '2026-10-13 07:39:29', 1),
(39, 9, 38, '2026-10-13 07:41:26', 1),
(40, 9, 39, '2026-10-13 07:43:47', 1),
(41, 9, 40, '2026-10-13 07:48:22', 1),
(42, 9, 41, '2026-10-13 07:50:47', 1),
(43, 9, 42, '2026-10-13 07:53:20', 1),
(44, 9, 43, '2026-10-13 07:55:51', 1),
(45, 8, 44, '2026-10-13 07:58:20', 1),
(46, 8, 45, '2026-10-13 08:01:18', 1),
(47, 8, 46, '2026-10-13 08:05:14', 1),
(48, 8, 47, '2026-10-13 08:08:03', 1),
(49, 8, 48, '2026-10-13 08:16:23', 1),
(50, 8, 49, '2026-10-13 08:19:58', 1),
(51, 8, 50, '2026-10-13 08:22:12', 1),
(52, 8, 51, '2026-10-13 08:24:47', 1),
(53, 8, 52, '2026-10-13 08:29:29', 1),
(54, 8, 53, '2026-10-13 08:32:47', 1),
(55, 6, 54, '2026-10-13 09:00:44', 1),
(56, 6, 55, '2026-10-13 09:05:32', 1),
(57, 6, 56, '2026-10-13 09:09:28', 1),
(58, 6, 57, '2026-10-13 09:12:34', 1),
(59, 6, 58, '2026-10-13 09:15:05', 1),
(60, 6, 59, '2026-10-13 09:23:50', 1),
(61, 6, 60, '2026-10-13 09:32:53', 1),
(62, 6, 61, '2026-10-13 09:35:13', 1),
(63, 6, 62, '2026-10-13 09:38:38', 1),
(64, 6, 63, '2026-10-13 09:42:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_number` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` enum('coordinator','trainer') COLLATE utf8mb4_general_ci NOT NULL,
  `year_level` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1st Year',
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `department` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `login_attempts` int NOT NULL DEFAULT '0',
  `lockout_until` datetime DEFAULT NULL,
  `lockout_tier` tinyint NOT NULL DEFAULT '0',
  `is_logged_in` tinyint(1) NOT NULL DEFAULT '0',
  `active_session_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT '0',
  `profile_picture` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `contact_number`, `role`, `year_level`, `status`, `department`, `department_id`, `created_at`, `login_attempts`, `lockout_until`, `lockout_tier`, `is_logged_in`, `active_session_id`, `last_ip_address`, `last_login_at`, `is_locked`, `profile_picture`) VALUES
(1, 'coordinator', '$2y$10$elYNDg.w81zEyDQ.tdXnQeOvgwhun8WX5/Y68yae/Boc3UzzOIXUC', 'Roy Fernandez', 'michellebarba600@gmail.com', '', 'coordinator', '1st Year', 'active', NULL, NULL, '2026-09-17 12:13:06', 0, NULL, 0, 1, '41afd07b03a6b6b98943c22ca919fd4b894aafdb0b2cf8df1b6afd04d41ec007', NULL, '2026-10-13 23:13:46', 0, 'uploads/profiles/user_1_1790568345.jpg'),
(4, 'trainer2', '$2y$10$WqBV/lQbbeXbs.aEWY5BSuxON4QA16n8zBeWkXjmLSj1C0x3hsS2C', 'Mr. Joel S.  Milan', 'jumarcaniedobaron@gmail.com', '', 'trainer', '1st Year', 'active', 'CSS', 2, '2026-09-18 04:54:14', 0, NULL, 0, 0, NULL, NULL, '2026-10-12 17:46:19', 0, 'uploads/profiles/user_4_1790568177.jpg'),
(5, 'trainer3', '$2y$10$WqBV/lQbbeXbs.aEWY5BSuxON4QA16n8zBeWkXjmLSj1C0x3hsS2C', 'Mrs. Jannah Jimenez', 'jannajiminez@gmail.com', '', 'trainer', '1st Year', 'active', NULL, NULL, '2026-09-18 04:56:22', 0, NULL, 0, 0, NULL, NULL, '2026-10-10 13:59:12', 0, 'uploads/profiles/user_5_1790568236.jpg'),
(6, 'trainer4', '$2y$10$WqBV/lQbbeXbs.aEWY5BSuxON4QA16n8zBeWkXjmLSj1C0x3hsS2C', 'Mr. Roy Fernandez', 'royfernandez@gmail.com', '', 'trainer', '1st Year', 'active', NULL, NULL, '2026-09-18 04:56:54', 0, NULL, 0, 0, NULL, NULL, '2026-10-04 20:00:12', 0, 'uploads/profiles/user_6_1790568263.jpg'),
(7, 'trainer5', '$2y$10$WqBV/lQbbeXbs.aEWY5BSuxON4QA16n8zBeWkXjmLSj1C0x3hsS2C', 'Mr. Manny Anacleto', 'regiebaluarte@gmail.com', '', 'trainer', '1st Year', 'active', 'ATS', 3, '2026-09-18 05:03:28', 0, NULL, 0, 0, NULL, NULL, '2026-10-09 14:35:40', 0, 'uploads/profiles/user_7_1790568437.jpg'),
(8, 'trainer6', '$2y$10$v9lJcH35oxQ.6DiD7O7R8OYdW5U4zioCEi7QGBmthXto6RcXGXFtG', 'Mr. Philip Panonce', 'barbamichelle2004@gmail.com', '09359703558', 'trainer', '1st Year', 'active', NULL, NULL, '2026-09-18 05:30:32', 0, NULL, 0, 0, NULL, NULL, '2026-10-13 11:42:06', 0, 'uploads/profiles/user_8_1790568291.jpg'),
(9, 'trainer1', '$2y$10$XzRo4ky0mNHa1mVMmCRkpu9zBU2m2HDA0TnE55w0WRN15C69xvmN2', 'Mr. Jumar C. Baron', 'franklincamonislasdoce@gmail.com', '', 'trainer', '1st Year', 'active', NULL, NULL, '2026-09-18 05:40:07', 0, NULL, 0, 0, NULL, NULL, '2026-10-13 22:06:50', 0, 'uploads/profiles/user_9_1790567800.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `user_qualifications`
--

CREATE TABLE `user_qualifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `qualification_id` int NOT NULL,
  `assigned_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_qualifications`
--

INSERT INTO `user_qualifications` (`id`, `user_id`, `qualification_id`, `assigned_date`) VALUES
(28, 5, 1, '2026-09-18 04:56:22'),
(29, 6, 2, '2026-09-18 04:56:54'),
(33, 4, 4, '2026-10-12 09:45:07'),
(34, 8, 6, '2026-10-13 09:49:48'),
(35, 7, 5, '2026-10-13 09:50:31'),
(36, 9, 3, '2026-10-13 09:51:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `competency_standards`
--
ALTER TABLE `competency_standards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_task_code` (`code_no`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `competency_standards_qualification_fk` (`qualification_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_code` (`course_code`);

--
-- Indexes for table `evaluation_eligibility`
--
ALTER TABLE `evaluation_eligibility`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trainee_id` (`trainee_id`),
  ADD KEY `idx_eligibility` (`is_eligible`),
  ADD KEY `idx_ready_for_assessment` (`ready_for_assessment`);

--
-- Indexes for table `evaluation_tracker`
--
ALTER TABLE `evaluation_tracker`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_trainee_task` (`trainee_id`,`task_id`),
  ADD KEY `idx_trainee_status` (`trainee_id`,`status`),
  ADD KEY `idx_remarks` (`remarks`),
  ADD KEY `fk_sat_evaluator` (`evaluated_by`),
  ADD KEY `idx_trainee_task` (`trainee_id`,`task_id`),
  ADD KEY `idx_trainee_id` (`trainee_id`),
  ADD KEY `idx_task_id` (`task_id`);

--
-- Indexes for table `notification_history`
--
ALTER TABLE `notification_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trainee` (`trainee_id`),
  ADD KEY `idx_sent_at` (`sent_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_department` (`department`);

--
-- Indexes for table `notified_logs`
--
ALTER TABLE `notified_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notice_sender` (`sent_by`),
  ADD KEY `idx_trainee_id` (`trainee_id`),
  ADD KEY `idx_task_id` (`task_id`),
  ADD KEY `idx_date_sent` (`date_sent`),
  ADD KEY `idx_delivery_status` (`delivery_status`);

--
-- Indexes for table `performance_tasks`
--
ALTER TABLE `performance_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_performance_competency` (`competency_id`);

--
-- Indexes for table `pre_assessment`
--
ALTER TABLE `pre_assessment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trainee_id` (`trainee_id`),
  ADD KEY `idx_trainee_id` (`trainee_id`);

--
-- Indexes for table `qualifications`
--
ALTER TABLE `qualifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_qualification` (`course_id`,`qualification_code`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `trainees`
--
ALTER TABLE `trainees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_reference_id` (`reference_id`),
  ADD KEY `idx_section` (`section`),
  ADD KEY `idx_name` (`last_name`,`first_name`),
  ADD KEY `idx_trainees_email` (`email`),
  ADD KEY `idx_trainees_section` (`section`),
  ADD KEY `idx_qualification` (`qualification_id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `trainees_archive`
--
ALTER TABLE `trainees_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_original_id` (`original_id`),
  ADD KEY `idx_archive_reason` (`archive_reason`),
  ADD KEY `idx_archived_at` (`archived_at`),
  ADD KEY `idx_last_name` (`last_name`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_qualification` (`qualification_id`);

--
-- Indexes for table `trainee_task_records`
--
ALTER TABLE `trainee_task_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_trainee_task` (`trainee_id`,`task_id`),
  ADD KEY `idx_trainee_id` (`trainee_id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_task_type` (`task_type`);

--
-- Indexes for table `trainer_trainee_assignments`
--
ALTER TABLE `trainer_trainee_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_trainer_trainee` (`trainer_id`,`trainee_id`),
  ADD KEY `trainee_id` (`trainee_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_qualifications`
--
ALTER TABLE `user_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_qualification` (`user_id`,`qualification_id`),
  ADD KEY `fk_uq_qualification_id` (`qualification_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `competency_standards`
--
ALTER TABLE `competency_standards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=172;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `evaluation_eligibility`
--
ALTER TABLE `evaluation_eligibility`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `evaluation_tracker`
--
ALTER TABLE `evaluation_tracker`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=603;

--
-- AUTO_INCREMENT for table `notification_history`
--
ALTER TABLE `notification_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notified_logs`
--
ALTER TABLE `notified_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `performance_tasks`
--
ALTER TABLE `performance_tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=211;

--
-- AUTO_INCREMENT for table `pre_assessment`
--
ALTER TABLE `pre_assessment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `qualifications`
--
ALTER TABLE `qualifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `trainees`
--
ALTER TABLE `trainees`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `trainees_archive`
--
ALTER TABLE `trainees_archive`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trainee_task_records`
--
ALTER TABLE `trainee_task_records`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainer_trainee_assignments`
--
ALTER TABLE `trainer_trainee_assignments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `user_qualifications`
--
ALTER TABLE `user_qualifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `competency_standards`
--
ALTER TABLE `competency_standards`
  ADD CONSTRAINT `competency_standards_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `competency_standards_qualification_fk` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluation_eligibility`
--
ALTER TABLE `evaluation_eligibility`
  ADD CONSTRAINT `fk_pae_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluation_tracker`
--
ALTER TABLE `evaluation_tracker`
  ADD CONSTRAINT `fk_evaluation_tracker_task` FOREIGN KEY (`task_id`) REFERENCES `performance_tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_evaluation_tracker_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sat_evaluator` FOREIGN KEY (`evaluated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sat_task` FOREIGN KEY (`task_id`) REFERENCES `performance_tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sat_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notified_logs`
--
ALTER TABLE `notified_logs`
  ADD CONSTRAINT `fk_notice_sender` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notice_task` FOREIGN KEY (`task_id`) REFERENCES `performance_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notice_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `performance_tasks`
--
ALTER TABLE `performance_tasks`
  ADD CONSTRAINT `fk_performance_tasks_competency` FOREIGN KEY (`competency_id`) REFERENCES `competency_standards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `qualifications`
--
ALTER TABLE `qualifications`
  ADD CONSTRAINT `qualifications_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trainees`
--
ALTER TABLE `trainees`
  ADD CONSTRAINT `fk_trainees_qualification_id` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trainees_ibfk_3` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trainer_trainee_assignments`
--
ALTER TABLE `trainer_trainee_assignments`
  ADD CONSTRAINT `trainer_trainee_assignments_ibfk_1` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trainer_trainee_assignments_ibfk_2` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_qualifications`
--
ALTER TABLE `user_qualifications`
  ADD CONSTRAINT `fk_uq_qualification_id` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uq_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
