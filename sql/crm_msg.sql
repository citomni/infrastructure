-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Vært: 127.0.0.1
-- Genereringstid: 03. 10 2025 kl. 21:45:17
-- Serverversion: 10.4.32-MariaDB
-- PHP-version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `citomni`
--

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `crm_msg`
--

CREATE TABLE `crm_msg` (
  `id` int(11) NOT NULL,
  `msg_subject` varchar(150) NOT NULL,
  `msg_body` text NOT NULL,
  `msg_from_name` varchar(100) NOT NULL,
  `msg_from_company_name` varchar(100) DEFAULT NULL,
  `msg_from_company_no` varchar(20) DEFAULT NULL,
  `msg_from_email` varchar(100) NOT NULL,
  `msg_from_phone` varchar(30) DEFAULT NULL,
  `msg_from_ip` varchar(50) DEFAULT NULL,
  `msg_posted_from_url` varchar(300) DEFAULT NULL,
  `msg_assigned_to_userid` int(11) DEFAULT NULL,
  `msg_deadline_date` datetime DEFAULT NULL,
  `msg_status` tinyint(1) NOT NULL DEFAULT 0,
  `msg_notes` text DEFAULT NULL,
  `msg_added_dt` datetime DEFAULT current_timestamp(),
  `msg_added_userid` int(11) NOT NULL DEFAULT 0,
  `msg_lastupdated_dt` datetime DEFAULT NULL,
  `msg_lastupdated_userid` int(11) DEFAULT NULL,
  `msg_hitcount` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Begrænsninger for dumpede tabeller
--

--
-- Indeks for tabel `crm_msg`
--
ALTER TABLE `crm_msg`
  ADD PRIMARY KEY (`id`);

--
-- Brug ikke AUTO_INCREMENT for slettede tabeller
--

--
-- Tilføj AUTO_INCREMENT i tabel `crm_msg`
--
ALTER TABLE `crm_msg`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
