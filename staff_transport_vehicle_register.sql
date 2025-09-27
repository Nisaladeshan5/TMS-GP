-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 27, 2025 at 02:51 AM
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
(13, '123456', '2025-08-01', 'morning', 'Amal', 'GPDLC006', '12:25:00.000000', '14:25:00.000000', '0'),
(12, '123456', '2025-08-01', 'evening', 'Amal', 'GPDLC006', '06:25:00.000000', '05:25:00.000000', '0'),
(42, '123456', '2025-08-14', 'morning', 'ranga', 'GPDLC006', '05:06:00.000000', '08:05:00.000000', '0'),
(43, '123456', '2025-08-14', 'evening', 'ranga', 'GPDLC006', '05:05:00.000000', '08:05:00.000000', '0'),
(45, 'hfxx', '2025-08-25', 'morning', 'bfb', 'GPDLC006', '05:00:00.000000', '05:05:00.000000', '0'),
(46, 'hfxx', '2025-08-25', 'evening', 'bfb', 'GPDLC006', '14:00:00.000000', '14:05:00.000000', '0');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
