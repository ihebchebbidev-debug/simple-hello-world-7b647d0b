-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: luccybcdb.mysql.db
-- Generation Time: May 12, 2026 at 12:11 PM
-- Server version: 8.0.45-36
-- PHP Version: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `luccybcdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `crminternet_prospects`
--

CREATE TABLE `crminternet_prospects` (
  `id` varchar(40) NOT NULL,
  `civility` enum('M','Mme') NOT NULL DEFAULT 'M',
  `last_name` varchar(120) NOT NULL,
  `first_name` varchar(120) NOT NULL DEFAULT '',
  `phone` varchar(40) NOT NULL DEFAULT '',
  `phone2` varchar(40) NOT NULL DEFAULT '',
  `cin` varchar(40) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `email` varchar(160) NOT NULL DEFAULT '',
  `source` varchar(80) NOT NULL DEFAULT 'Terrain',
  `status` varchar(80) NOT NULL DEFAULT 'Nouveau',
  `stage` varchar(80) DEFAULT NULL,
  `assigned_to` varchar(80) DEFAULT NULL,
  `created_at` date NOT NULL,
  `city` varchar(120) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `zone` varchar(120) NOT NULL DEFAULT '',
  `outcome` enum('pending','won','lost') NOT NULL DEFAULT 'pending',
  `lost_reason` varchar(255) DEFAULT NULL,
  `comment` text,
  `comment2` text,
  `check_valeur` enum('valid','invalid','pending') NOT NULL DEFAULT 'pending',
  `converted` tinyint(1) NOT NULL DEFAULT '0',
  `converted_at` datetime DEFAULT NULL,
  `opportunity_id` varchar(40) DEFAULT NULL,
  `type_id` varchar(40) DEFAULT NULL,
  `gouvernorat` varchar(120) NOT NULL DEFAULT '',
  `delegation` varchar(120) NOT NULL DEFAULT '',
  `code_postal` varchar(16) DEFAULT NULL,
  `localisation_xy` varchar(64) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `crminternet_prospects`
--

INSERT INTO `crminternet_prospects` (`id`, `civility`, `last_name`, `first_name`, `phone`, `phone2`, `cin`, `birth_date`, `email`, `source`, `status`, `stage`, `assigned_to`, `created_at`, `city`, `address`, `zone`, `outcome`, `lost_reason`, `comment`, `comment2`, `check_valeur`, `converted`, `converted_at`, `opportunity_id`, `type_id`, `gouvernorat`, `delegation`, `code_postal`, `localisation_xy`, `deleted_at`) VALUES
('P-00002d56', 'M', 'IDRISSI', '', '', '', '4707272', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '12 RUE ASFOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-0000b315', 'M', 'ALLEGUI', '', '', '', '7102056', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', 'RUE IBN CHARAF', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00018891', 'M', 'AMJED', '', '', '', '9370168', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '17 AV 10 DECEMBRE', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-00019d81', 'M', 'Brahmi', '', '', '', '89109876', NULL, '', 'Terrain', 'En cours', NULL, NULL, '2026-05-12', 'SFAX', 'Av. HÃ©di Chaker', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'SFAX', '', NULL, NULL, NULL),
('P-0001a531', 'M', 'Zarrouk', '', '', '', '67890123', NULL, '', 'Email', 'En cours', NULL, NULL, '2026-05-12', 'SFAX', 'CitÃ© El Bahri', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'SFAX', '', NULL, NULL, NULL),
('P-0001fe96', 'M', 'Ouali', '', '', '', NULL, NULL, '', 'Web', 'En cours', NULL, NULL, '2026-05-12', 'TUNIS', 'Rue de Rome', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'TUNIS', '', NULL, NULL, NULL),
('P-00020811', 'M', 'Mejri', '', '', '', '23456789', NULL, '', 'Web', 'En cours', NULL, NULL, '2026-05-12', 'BIZERTE', 'Av. de France', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BIZERTE', '', NULL, NULL, NULL),
('P-0002165a', 'M', 'MANSOUR', '', '', '', '738418', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '3 RUE BENBLA', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-00029ad8', 'M', 'Ben Salah', '', '', '', '1234567', NULL, '', 'Terrain', 'En cours', NULL, NULL, '2026-05-12', 'TUNIS', '12 Rue de la RÃ©publique', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'TUNIS', '', NULL, NULL, NULL),
('P-00048e05', 'M', 'ATRI', '', '', '', '135947', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '45 RUE ALBATROS', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-0004b44b', 'M', 'QATest', '', '', '', NULL, NULL, '', 'Terrain', 'En cours', NULL, NULL, '2026-05-12', '', '', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, '', '', NULL, NULL, NULL),
('P-0004e49c', 'M', 'Saidi', '', '', '', '78210987', NULL, '', 'Salon', 'En cours', NULL, NULL, '2026-05-12', 'GAFSA', 'Rue Ali Bach Hamba', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'GAFSA', '', NULL, NULL, NULL),
('P-00050acb', 'M', 'BESSOUDA', '', '', '', '7117941', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', 'RUE IMEM CHAAFI', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-0005ee60', 'M', 'ALLAGUI', '', '', '', '14667766', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '26 RUE FADHEL BEN ACHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00060a01', 'M', 'Gharbi', '', '', '', '99887766', NULL, '', 'Web', 'En cours', NULL, NULL, '2026-05-12', 'SFAX', 'Rue Mongi Slim', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'SFAX', '', NULL, NULL, NULL),
('P-00061a9b', 'M', 'Gharbi', '', '', '', '99887766', NULL, '', 'Web', 'En cours', NULL, NULL, '2026-05-12', 'SFAX', 'Rue Mongi Slim', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'SFAX', '', NULL, NULL, NULL),
('P-0006475a', 'M', 'JLASSI', '', '', '', '7000735', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '25 RUE FADHEL BEN ACHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00068623', 'M', 'KACHROUD', '', '', '', '7239075', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '17 RUE FADHEL BEN ACHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-0006d441', 'M', 'KACHROUD', '', '', '', '7239075', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '17 RUE FADHEL BEN ACHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00074e64', 'M', 'IDRISSI', '', '', '', '4707272', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '12 RUE ASFOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-0007da7d', 'M', 'HAMZA', '', '', '', '610310', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '25 RUE DAHBEL CITE MNARA', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-0007fbf7', 'M', 'JABALLAH', '', '', '', '14307976', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '13 RUE ALI BEN AISSA CITEE EZZOUHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-00080514', 'M', 'BOUAGUEL', '', '', '', '75869', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '32 RUE IMEM CHAAFI', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00080971', 'M', 'Hammami', '', '', '', '12345678', NULL, '', 'Terrain', 'En cours', NULL, NULL, '2026-05-12', 'TUNIS', '5 Rue de Carthage', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'TUNIS', '', NULL, NULL, NULL),
('P-00086f06', 'M', 'BOUSLAMA', '', '', '', '3705761', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '23 RUE IMEM ECHAFI', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00088f30', 'M', 'MIGHRI', '', '', '', '872778', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', 'RUE FADHEL BEN ACHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL),
('P-00092d63', 'M', 'Marzouki', '', '', '', '90123456', NULL, '', 'Recommandation', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', 'Av. de la Plage', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', NULL, NULL, NULL),
('P-00094875', 'M', 'TOUJANI', '', '', '', '51550197', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '20 RUE ALI BEN AISSA CITEE EZZOUHOUR', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1353', NULL, NULL),
('P-0009ab69', 'M', 'QAfix', '', '', '', NULL, NULL, '', 'Terrain', 'En cours', NULL, NULL, '2026-05-12', 'TUNIS', '', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'TUNIS', '', NULL, NULL, NULL),
('P-0009e901', 'M', 'HMIDI', '', '', '', '2790176', NULL, '', '', 'En cours', NULL, NULL, '2026-05-12', 'BEN AROUS', '9 RUE EZZAHRA CITE LES JARDINS', '', 'pending', NULL, NULL, NULL, 'pending', 0, NULL, NULL, NULL, 'BEN AROUS', '', '1362', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `crminternet_prospects`
--
ALTER TABLE `crminternet_prospects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assigned` (`assigned_to`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_outcome` (`outcome`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `ix_prospect_cin` (`cin`),
  ADD KEY `idx_deleted` (`deleted_at`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
