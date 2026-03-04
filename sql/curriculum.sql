-- NOTE:
-- This script avoids CREATE DATABASE/USE because some shared MySQL users
-- (e.g. phpMyAdmin on rental servers) do not have database creation privileges.
-- Run this after selecting your target database.

CREATE TABLE IF NOT EXISTS curriculum (
  id INT NOT NULL PRIMARY KEY,
  curriculum_name VARCHAR(255) NOT NULL,
  sheet_key VARCHAR(255) NOT NULL,
  sheet_name VARCHAR(255) NOT NULL
);

INSERT INTO curriculum (id, curriculum_name, sheet_key, sheet_name) VALUES
  (1, 'week1_week2', '1IBGD-u-qxl7UF7WlXCmM9a_nT6ZE8UtmyndaLW1yJ4w', 'シート1'),
  (2, 'week2_week3', '1jAwXvg8gX3rLYa-OYyHhCaLXUX1EhjLyaGlgh_4BGoE', 'シート1'),
  (3, 'week3_week4', '1Uth0PaAYnfh67f8xw3Spdrbly8FzMUdgAKAG_lsj03I', 'シート1'),
  (4, 'week4_week5', '1Db9uO1-3E-LoF7uRmhm4jw39Pyl8U_ePeieA5bJjvMU', 'シート1'),
  (5, 'week5_week6', '11B2tsJzlc6wk5Y2unIaa_nBVEqbE0dMidrLBanj9qB4', 'シート1'),
  (6, 'week6_week7', '1HnDAfcnbZKCAqzQLuhLd-3cIRdeKrNuCV5iwNjrGUjQ', 'シート1'),
  (7, 'week7_week8', '1-Q_oXmW7T8ZNf5FsV-DiF_kHNU1s2hStDFZhJE62yOY', 'シート1'),
  (8, 'week8_week9', '1Ik6tO0A61UnHK7JbcXTcjbBPmvmsmoUwP89T4lwBB5Q', 'シート1'),
  (9, 'week9_week10', '1ZRhUvrefR6s9osmZ4tGyEbli6q3CYkYE-97J2NN5se0', 'シート1'),
  (10, 'week10_week11', '1BEWGjd534yllZaCeON7YrkILBWyjNUs1bvy31h7O0Y0', 'シート1'),
  (11, 'week11_week12', '1BnpzEJJJ_o8BVyR8DQ_aEC6FIC3GE5JF03r0fuFDNk8', 'シート1'),
  (12, 'week12', '1TZ1pYTjgRwuLcGWMj-2FfWouK46w7lJiu-fhIwWOuig', 'シート1')
ON DUPLICATE KEY UPDATE
  curriculum_name = VALUES(curriculum_name),
  sheet_key = VALUES(sheet_key),
  sheet_name = VALUES(sheet_name);