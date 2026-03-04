-- phpMyAdmin SQL Dump
-- version 5.2.1-1.el8.remi
-- https://www.phpmyadmin.net/
--
-- ホスト: localhost
-- 生成日時: 2026 年 3 月 04 日 14:49
-- サーバのバージョン： 10.5.22-MariaDB-log
-- PHP のバージョン: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- データベース: `ss911157_support`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `curriculum`
--

CREATE TABLE `curriculum` (
  `id` int(11) NOT NULL,
  `curriculum_name` varchar(255) NOT NULL,
  `sheet_key` varchar(255) NOT NULL,
  `sheet_name` varchar(255) NOT NULL,
  `map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`map`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `curriculum`
--

INSERT INTO `curriculum` (`id`, `curriculum_name`, `sheet_key`, `sheet_name`, `map`) VALUES
(1, 'week1_week2', '1IBGD-u-qxl7UF7WlXCmM9a_nT6ZE8UtmyndaLW1yJ4w', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"answer_2\": 8, \"q1\": 9, \"q2\": 10, \"q3\": 11, \"mail_address\": 12, \"curriculum_id\": 13}'),
(2, 'week2_week3', '1jAwXvg8gX3rLYa-OYyHhCaLXUX1EhjLyaGlgh_4BGoE', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"answer_2\": 8, \"q1\": 9, \"q2\": 10, \"q3\": 11, \"mail_address\": 12, \"curriculum_id\": 13}'),
(3, 'week3_week4', '1Uth0PaAYnfh67f8xw3Spdrbly8FzMUdgAKAG_lsj03I', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(4, 'week4_week5', '1Db9uO1-3E-LoF7uRmhm4jw39Pyl8U_ePeieA5bJjvMU', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(5, 'week5_week6', '11B2tsJzlc6wk5Y2unIaa_nBVEqbE0dMidrLBanj9qB4', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(6, 'week6_week7', '1HnDAfcnbZKCAqzQLuhLd-3cIRdeKrNuCV5iwNjrGUjQ', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(7, 'week7_week8', '1-Q_oXmW7T8ZNf5FsV-DiF_kHNU1s2hStDFZhJE62yOY', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(8, 'week8_week9', '1Ik6tO0A61UnHK7JbcXTcjbBPmvmsmoUwP89T4lwBB5Q', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"answer_2\": 8, \"q1\": 9, \"q2\": 10, \"q3\": 11, \"mail_address\": 12, \"curriculum_id\": 13}'),
(9, 'week9_week10', '1ZRhUvrefR6s9osmZ4tGyEbli6q3CYkYE-97J2NN5se0', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(10, 'week10_week11', '1BEWGjd534yllZaCeON7YrkILBWyjNUs1bvy31h7O0Y0', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"q1\": 8, \"q2\": 9, \"q3\": 10, \"mail_address\": 11, \"curriculum_id\": 12}'),
(11, 'week11_week12', '1BnpzEJJJ_o8BVyR8DQ_aEC6FIC3GE5JF03r0fuFDNk8', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"answer_2\": 8, \"q1\": 9, \"q2\": 10, \"q3\": 11, \"mail_address\": 12, \"curriculum_id\": 13}'),
(12, 'week12', '1TZ1pYTjgRwuLcGWMj-2FfWouK46w7lJiu-fhIwWOuig', 'シート1', '{\"line_user_id\": 1, \"answer_id\": 2, \"answer_date\": 3, \"answer_id_user\": 4, \"line_name\": 5, \"display_name\": 6, \"answer_1\": 7, \"answer_2\": 8, \"q1\": 9, \"q2\": 10, \"q3\": 11, \"mail_address\": 12, \"curriculum_id\": 13}');

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `curriculum`
--
ALTER TABLE `curriculum`
  ADD PRIMARY KEY (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
