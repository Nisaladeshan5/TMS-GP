-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 26, 2025 at 03:38 AM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `transport`
--

-- --------------------------------------------------------

--
-- Table structure for table `checkup`
--

DROP TABLE IF EXISTS `checkup`;
CREATE TABLE IF NOT EXISTS `checkup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `vehicle_no` varchar(11) NOT NULL,
  `route` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `transport_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `inspector` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `date` date NOT NULL,
  `revenue_license_status` tinyint(1) NOT NULL,
  `revenue_license_remark` varchar(255) DEFAULT NULL,
  `driver_license_status` tinyint(1) NOT NULL,
  `driver_license_remark` varchar(255) DEFAULT NULL,
  `insurance_status` tinyint(1) NOT NULL,
  `insurance_remark` varchar(255) DEFAULT NULL,
  `driver_data_sheet_status` tinyint(1) NOT NULL,
  `driver_data_sheet_remark` varchar(255) DEFAULT NULL,
  `driver_nic_status` tinyint(1) NOT NULL,
  `driver_nic_remark` varchar(255) DEFAULT NULL,
  `break_status` tinyint(1) NOT NULL,
  `break_remark` varchar(255) DEFAULT NULL,
  `tires_status` tinyint(1) NOT NULL,
  `tires_remark` varchar(255) DEFAULT NULL,
  `spare_wheel_status` tinyint(1) NOT NULL,
  `spare_wheel_remark` varchar(255) DEFAULT NULL,
  `lights_status` tinyint(1) NOT NULL,
  `lights_remark` varchar(255) DEFAULT NULL,
  `revers_lights_status` tinyint(1) NOT NULL,
  `revers_lights_remark` varchar(255) DEFAULT NULL,
  `horns_status` tinyint(1) NOT NULL,
  `horns_remark` varchar(255) DEFAULT NULL,
  `windows_status` tinyint(1) NOT NULL,
  `windows_remark` varchar(255) DEFAULT NULL,
  `door_locks_status` tinyint(1) NOT NULL,
  `door_locks_remark` varchar(255) DEFAULT NULL,
  `no_oil_leaks_status` tinyint(1) NOT NULL,
  `no_oil_leaks_remark` varchar(255) DEFAULT NULL,
  `no_high_smoke_status` tinyint(1) NOT NULL,
  `no_high_smoke_remark` varchar(255) DEFAULT NULL,
  `seat_condition_status` tinyint(1) NOT NULL,
  `seat_condition_remark` varchar(255) DEFAULT NULL,
  `seat_gap_status` tinyint(1) NOT NULL,
  `seat_gap_remark` varchar(255) DEFAULT NULL,
  `body_condition_status` tinyint(1) NOT NULL,
  `body_condition_remark` varchar(255) DEFAULT NULL,
  `roof_leek_status` tinyint(1) NOT NULL,
  `roof_leek_remark` varchar(255) DEFAULT NULL,
  `air_conditions_status` tinyint(1) NOT NULL,
  `air_conditions_remark` varchar(255) DEFAULT NULL,
  `noise_status` tinyint(1) NOT NULL,
  `noise_remark` varchar(255) DEFAULT NULL,
  `other_observations` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `checkup`
--

INSERT INTO `checkup` (`id`, `supplier`, `vehicle_no`, `route`, `transport_type`, `inspector`, `date`, `revenue_license_status`, `revenue_license_remark`, `driver_license_status`, `driver_license_remark`, `insurance_status`, `insurance_remark`, `driver_data_sheet_status`, `driver_data_sheet_remark`, `driver_nic_status`, `driver_nic_remark`, `break_status`, `break_remark`, `tires_status`, `tires_remark`, `spare_wheel_status`, `spare_wheel_remark`, `lights_status`, `lights_remark`, `revers_lights_status`, `revers_lights_remark`, `horns_status`, `horns_remark`, `windows_status`, `windows_remark`, `door_locks_status`, `door_locks_remark`, `no_oil_leaks_status`, `no_oil_leaks_remark`, `no_high_smoke_status`, `no_high_smoke_remark`, `seat_condition_status`, `seat_condition_remark`, `seat_gap_status`, `seat_gap_remark`, `body_condition_status`, `body_condition_remark`, `roof_leek_status`, `roof_leek_remark`, `air_conditions_status`, `air_conditions_remark`, `noise_status`, `noise_remark`, `other_observations`) VALUES
(1, 'gfgh', 'dsgdsg', 'Minuwangoda', 'Staff Trans', 'acfasfas', '2025-08-25', 1, '', 0, '', 1, '', 1, '', 0, 'segvv', 1, '', 1, '', 0, '', 1, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 1, '', 0, '', 0, '', ''),
(2, 'gfgh', '123455', 'Minuwangoda', 'Staff Trans', 'vdsvsa', '2025-08-25', 1, '', 1, '', 0, 'hbsrf', 0, 'bd', 0, '', 0, '', 0, '', 1, '', 1, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', 0, '', ''),
(3, 'gfgf', '123454', 'Minuwangoda', 'Staff Trans', 'aaa', '2025-08-26', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', ''),
(4, 'gfgh', '123456', 'Minuwangoda', 'Staff Transport', 'aaa', '2025-08-26', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', 1, '', '');

-- --------------------------------------------------------

--
-- Table structure for table `consumption`
--

DROP TABLE IF EXISTS `consumption`;
CREATE TABLE IF NOT EXISTS `consumption` (
  `c_id` int NOT NULL AUTO_INCREMENT,
  `c_type` varchar(11) NOT NULL,
  `distance` int NOT NULL,
  PRIMARY KEY (`c_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `consumption`
--

INSERT INTO `consumption` (`c_id`, `c_type`, `distance`) VALUES
(1, 'Non A/C', 10),
(2, 'Front A/C', 9),
(3, 'Dual A/C', 8);

-- --------------------------------------------------------

--
-- Table structure for table `department`
--

DROP TABLE IF EXISTS `department`;
CREATE TABLE IF NOT EXISTS `department` (
  `d_id` int NOT NULL AUTO_INCREMENT,
  `d_name` varchar(50) NOT NULL,
  PRIMARY KEY (`d_id`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `department`
--

INSERT INTO `department` (`d_id`, `d_name`) VALUES
(1, 'HR'),
(2, 'W/S'),
(3, 'Purchasing'),
(4, 'Operation and Finance'),
(5, 'Production'),
(6, 'Accounts'),
(7, 'Merchandising'),
(8, 'Planning'),
(9, 'Sample'),
(10, 'Stores'),
(11, 'Transport'),
(12, 'IT'),
(13, 'Export'),
(14, 'Intex'),
(15, 'Cutting');

-- --------------------------------------------------------

--
-- Table structure for table `driver`
--

DROP TABLE IF EXISTS `driver`;
CREATE TABLE IF NOT EXISTS `driver` (
  `driver_NIC` varchar(20) NOT NULL,
  `calling_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `full_name` varchar(50) NOT NULL,
  `phone_no` int NOT NULL,
  `license_expiry_date` date NOT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`driver_NIC`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `driver`
--

INSERT INTO `driver` (`driver_NIC`, `calling_name`, `full_name`, `phone_no`, `license_expiry_date`, `is_active`) VALUES
('777777777v', 'Ranga', 'Ranga AA', 265665655, '0000-00-00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `extra_distance`
--

DROP TABLE IF EXISTS `extra_distance`;
CREATE TABLE IF NOT EXISTS `extra_distance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vehicle_no` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `route_code` varchar(11) NOT NULL,
  `date` date NOT NULL,
  `distance` int NOT NULL,
  `remark` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `extra_distance`
--

INSERT INTO `extra_distance` (`id`, `vehicle_no`, `route_code`, `date`, `distance`, `remark`) VALUES
(1, '123456', 'GPDLC006', '2025-08-20', 54, ''),
(2, '123456', 'GPDLC006', '2025-08-21', 15, '');

-- --------------------------------------------------------

--
-- Table structure for table `extra_vehicle_register`
--

DROP TABLE IF EXISTS `extra_vehicle_register`;
CREATE TABLE IF NOT EXISTS `extra_vehicle_register` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vehicle_no` varchar(11) NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `route_code` varchar(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `extra_vehicle_register`
--

INSERT INTO `extra_vehicle_register` (`id`, `vehicle_no`, `date`, `amount`, `reason`, `route_code`) VALUES
(1, '123456', '2025-08-20', 1534.00, '/knl', 'GPDLC006');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_rate`
--

DROP TABLE IF EXISTS `fuel_rate`;
CREATE TABLE IF NOT EXISTS `fuel_rate` (
  `rate_id` int NOT NULL AUTO_INCREMENT,
  `rate` decimal(10,2) NOT NULL,
  `date` datetime(6) NOT NULL,
  PRIMARY KEY (`rate_id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fuel_rate`
--

INSERT INTO `fuel_rate` (`rate_id`, `rate`, `date`) VALUES
(1, 54.00, '2025-08-19 21:45:51.000000'),
(2, 295.00, '2025-08-19 21:53:37.000000'),
(3, 84.00, '2025-08-19 21:55:59.000000'),
(4, 56.00, '2025-08-19 21:56:05.000000'),
(5, 87.00, '2025-08-19 21:56:12.000000'),
(6, 295.00, '2025-08-20 07:52:54.000000'),
(7, 307.00, '2025-08-20 21:28:48.000000');

-- --------------------------------------------------------

--
-- Table structure for table `reason`
--

DROP TABLE IF EXISTS `reason`;
CREATE TABLE IF NOT EXISTS `reason` (
  `r_id` int NOT NULL AUTO_INCREMENT,
  `reason` varchar(50) NOT NULL,
  `r_group` varchar(50) NOT NULL,
  PRIMARY KEY (`r_id`)
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `reason`
--

INSERT INTO `reason` (`r_id`, `reason`, `r_group`) VALUES
(1, 'Hospital', 'Emergency'),
(2, 'Post Office', 'Official -Other'),
(3, 'Banking work', 'Official -Other'),
(4, 'Drop - Office staff', 'Official -Other'),
(5, 'Pickup - Office staff', 'Official -Other'),
(6, 'Factory maintenance', 'Official -Other'),
(7, 'Purchasing', 'Official -Other'),
(8, 'Special arrangements', 'Official -Other'),
(9, 'Employee drop', 'Official -Other');

-- --------------------------------------------------------

--
-- Table structure for table `route`
--

DROP TABLE IF EXISTS `route`;
CREATE TABLE IF NOT EXISTS `route` (
  `route_code` varchar(11) NOT NULL,
  `route` varchar(50) NOT NULL,
  `working_days` int NOT NULL,
  `distance` int NOT NULL,
  `vehicle_no` varchar(50) NOT NULL,
  `monthly_fixed_rental` double NOT NULL,
  `extra_day_rate` double NOT NULL,
  `assigned_person` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`route_code`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `route`
--

INSERT INTO `route` (`route_code`, `route`, `working_days`, `distance`, `vehicle_no`, `monthly_fixed_rental`, `extra_day_rate`, `assigned_person`) VALUES
('GPDLC006', 'Minuwangoda', 22, 112, '123456', 60000, 5000, 'GP026126');

-- --------------------------------------------------------

--
-- Table structure for table `running_chart`
--

DROP TABLE IF EXISTS `running_chart`;
CREATE TABLE IF NOT EXISTS `running_chart` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vehicle_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `finish_time` time NOT NULL,
  `start_meter_reading` int NOT NULL,
  `finish_meter_reading` int NOT NULL,
  `purpose` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `running_chart`
--

INSERT INTO `running_chart` (`id`, `vehicle_no`, `date`, `start_time`, `finish_time`, `start_meter_reading`, `finish_meter_reading`, `purpose`) VALUES
(1, '123456', '2025-07-14', '11:56:00', '13:56:00', 15000, 15010, ''),
(2, '123456', '2025-07-14', '11:58:00', '13:58:00', 15000, 15010, ''),
(3, '789456', '2025-07-14', '12:00:00', '14:00:00', 16000, 16015, ''),
(4, '456123', '2025-07-14', '12:01:00', '14:01:00', 555, 855, ''),
(5, '123456', '2025-07-12', '14:05:00', '15:05:00', 12000, 12500, 'staff');

-- --------------------------------------------------------

--
-- Table structure for table `staff_transport_vehicle_register`
--

DROP TABLE IF EXISTS `staff_transport_vehicle_register`;
CREATE TABLE IF NOT EXISTS `staff_transport_vehicle_register` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vehicle_no` varchar(11) NOT NULL,
  `date` date NOT NULL,
  `shift` enum('morning','evening','','') NOT NULL,
  `driver` varchar(50) NOT NULL,
  `route` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `in_time` time(6) NOT NULL,
  `out_time` time(6) DEFAULT NULL,
  `status` enum('0','1') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff_transport_vehicle_register`
--

INSERT INTO `staff_transport_vehicle_register` (`id`, `vehicle_no`, `date`, `shift`, `driver`, `route`, `in_time`, `out_time`, `status`) VALUES
(13, 'ABC-4525', '2025-07-29', 'evening', 'Amal', '001', '12:25:00.000000', '14:25:00.000000', '0'),
(12, 'ABC-4525', '2025-07-29', 'morning', 'Amal', '001', '06:25:00.000000', '05:25:00.000000', '0'),
(42, '123456', '2025-08-14', 'morning', 'ranga', 'GPDLC006', '05:06:00.000000', '08:05:00.000000', '0'),
(43, '123456', '2025-08-14', 'evening', 'ranga', 'GPDLC006', '05:05:00.000000', '08:05:00.000000', '0'),
(44, '123456', '2025-08-15', 'morning', 'ranga', 'GPDLC006', '09:07:45.224466', '09:08:00.819582', '0'),
(45, 'hfxx', '2025-08-25', 'morning', 'bfb', 'GPDLC006', '05:00:00.000000', '05:05:00.000000', '0'),
(46, 'hfxx', '2025-08-25', 'evening', 'bfb', 'GPDLC006', '14:00:00.000000', '14:05:00.000000', '0');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle`
--

DROP TABLE IF EXISTS `vehicle`;
CREATE TABLE IF NOT EXISTS `vehicle` (
  `vehicle_no` varchar(11) NOT NULL,
  `driver_NIC` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `owner` varchar(20) NOT NULL,
  `owner_phone_no` varchar(11) NOT NULL,
  `capacity` int NOT NULL,
  `condition_type` enum('non A/C','front A/C','dual A/C') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'non A/C',
  `type` varchar(11) NOT NULL,
  `purpose` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `bank` varchar(11) NOT NULL,
  `holder_name` varchar(50) NOT NULL,
  `acc_no` varchar(20) NOT NULL,
  `branch` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_expiry_date` date NOT NULL,
  `insurance_expiry_date` date NOT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`vehicle_no`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vehicle`
--

INSERT INTO `vehicle` (`vehicle_no`, `driver_NIC`, `owner`, `owner_phone_no`, `capacity`, `condition_type`, `type`, `purpose`, `bank`, `holder_name`, `acc_no`, `branch`, `license_expiry_date`, `insurance_expiry_date`, `is_active`) VALUES
('123456', 'dg', 'kamal', '564', 15, 'dual A/C', 'van', 'staff', '', '', '0', '', '0000-00-00', '0000-00-00', 1),
('dsgdsg', '777777777v', 'dbdd', 'dbbd', 445, 'non A/C', 'dbdb', 'staff', 'dbxb', 'dbd', 'dbdxb', 'dxbxb', '2025-08-08', '2025-07-31', 1);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
