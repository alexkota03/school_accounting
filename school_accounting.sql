-- phpMyAdmin SQL Dump
-- version 4.5.5.1
-- http://www.phpmyadmin.net
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2025 at 01:33 PM
-- Server version: 5.7.11
-- PHP Version: 7.0.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `school_accounting`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `year_name` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`id`, `year_name`, `start_date`, `end_date`, `is_current`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2024-2025', '2024-09-01', '2025-06-30', 1, 1, '2025-10-15 07:40:23', '2025-10-15 07:40:23');

-- --------------------------------------------------------

--
-- Table structure for table `discounts`
--

CREATE TABLE `discounts` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `fee_type_id` int(11) DEFAULT NULL,
  `discount_type` enum('percentage','fixed') COLLATE utf8_unicode_ci NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `reason` text COLLATE utf8_unicode_ci,
  `applied_by` int(11) DEFAULT NULL,
  `academic_year` varchar(20) COLLATE utf8_unicode_ci DEFAULT '2024-2025',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `educational_stages`
--

CREATE TABLE `educational_stages` (
  `id` int(11) NOT NULL,
  `stage_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `stage_code` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `educational_stages`
--

INSERT INTO `educational_stages` (`id`, `stage_name`, `stage_code`, `description`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'رياض الأطفال', 'KG', 'مرحلة الروضة والتمهيدي', 1, 1, '2025-10-15 10:00:00', '2025-10-15 10:00:00'),
(2, 'المرحلة الابتدائية', 'PRIMARY', 'من الصف الأول إلى السادس الابتدائي', 1, 1, '2025-10-15 10:00:00', '2025-10-15 10:00:00'),
(3, 'المرحلة الإعدادية', 'PREP', 'من الصف الأول إلى الثالث الإعدادي', 1, 1, '2025-10-15 10:00:00', '2025-10-15 10:00:00'),
(4, 'المرحلة الثانوية', 'SECONDARY', 'من الصف الأول إلى الثالث الثانوي', 1, 1, '2025-10-15 10:00:00', '2025-10-15 10:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `fee_types`
--

CREATE TABLE `fee_types` (
  `id` int(11) NOT NULL,
  `stage_id` int(11) DEFAULT NULL,
  `grade_id` int(11) DEFAULT NULL,
  `fee_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT '0',
  `academic_year` varchar(20) COLLATE utf8_unicode_ci DEFAULT '2024-2025',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `fee_types`
--

INSERT INTO `fee_types` (`id`, `stage_id`, `grade_id`, `fee_name`, `amount`, `is_mandatory`, `academic_year`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(11, 3, 9, 'رسوم دراسه', '10000.00', 1, '2024-2025', 1, 1, '2025-10-17 20:25:41', '2025-10-17 20:25:41'),
(12, 3, 9, 'رسوم النشاط', '5000.00', 1, '2024-2025', 1, 1, '2025-10-17 20:26:06', '2025-10-17 20:26:06'),
(13, 3, 9, 'رسوم الكتب', '3000.00', 1, '2024-2025', 1, 1, '2025-10-17 20:26:24', '2025-10-17 20:26:24'),
(14, 3, 9, 'رسوم الكرسات', '2000.00', 0, '2024-2025', 1, 1, '2025-10-17 20:26:49', '2025-10-17 20:28:48');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,
  `grade_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `grade_order` int(11) DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`id`, `stage_id`, `grade_name`, `grade_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'رياض الأطفال 1', 1, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(2, 1, 'رياض الأطفال 2', 2, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(3, 2, 'الصف الأول الابتدائي', 1, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(4, 2, 'الصف الثاني الابتدائي', 2, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(5, 2, 'الصف الثالث الابتدائي', 3, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(6, 2, 'الصف الرابع الابتدائي', 4, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(7, 2, 'الصف الخامس الابتدائي', 5, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(8, 2, 'الصف السادس الابتدائي', 6, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(9, 3, 'الصف الأول الإعدادي', 1, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(10, 3, 'الصف الثاني الإعدادي', 2, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(11, 3, 'الصف الثالث الإعدادي', 3, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(12, 4, 'الصف الأول الثانوي', 1, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(13, 4, 'الصف الثاني الثانوي', 2, 1, '2025-10-15 07:40:52', '2025-10-15 07:40:52'),
(14, 4, 'الصف الثالث الثانوي', 3, 1, '2025-10-15 07:40:52', '2025-10-17 20:25:04');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `student_fee_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','credit_card','check') COLLATE utf8_unicode_ci DEFAULT 'cash',
  `receipt_number` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `bank_name` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `check_number` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8_unicode_ci,
  `received_by` int(11) DEFAULT NULL,
  `academic_year` varchar(20) COLLATE utf8_unicode_ci DEFAULT '2024-2025',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_additional` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `residential_areas`
--

CREATE TABLE `residential_areas` (
  `id` int(11) NOT NULL,
  `area_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `area_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text COLLATE utf8_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `residential_areas`
--

INSERT INTO `residential_areas` (`id`, `area_name`, `area_price`, `description`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(8, 'سيدى بشر', '8000.00', 'سيدى بشر و ضوحيها', 1, 1, '2025-10-17 20:30:34', '2025-10-17 20:30:34');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_code` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `stage_id` int(11) DEFAULT NULL,
  `grade_id` int(11) NOT NULL,
  `parent_name` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `parent_phone` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `parent_email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8_unicode_ci,
  `area_id` int(11) DEFAULT NULL,
  `religion` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `nationality` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `gender` enum('ذكر','أنثى') COLLATE utf8_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `academic_year` varchar(20) COLLATE utf8_unicode_ci DEFAULT '2024-2025',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_fees`
--

CREATE TABLE `student_fees` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `fee_type_id` int(11) DEFAULT NULL,
  `area_id` int(11) DEFAULT NULL,
  `original_amount` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `final_amount` decimal(10,2) DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT '0.00',
  `remaining_amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','paid','partial','overdue','cancelled') COLLATE utf8_unicode_ci DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `academic_year` varchar(20) COLLATE utf8_unicode_ci DEFAULT '2024-2025',
  `notes` text COLLATE utf8_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8_unicode_ci NOT NULL,
  `setting_description` text COLLATE utf8_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_description`, `created_at`, `updated_at`) VALUES
(1, 'school_name', 'مدرسة النجاح', 'اسم المدرسة', '2025-10-15 07:40:24', '2025-10-15 07:40:24'),
(2, 'school_address', 'المملكة العربية السعودية - الرياض', 'عنوان المدرسة', '2025-10-15 07:40:24', '2025-10-15 07:40:24'),
(3, 'school_phone', '+966112345678', 'هاتف المدرسة', '2025-10-15 07:40:24', '2025-10-15 07:40:24'),
(4, 'school_email', 'info@school.edu', 'البريد الإلكتروني للمدرسة', '2025-10-15 07:40:24', '2025-10-15 07:40:24'),
(5, 'currency', 'SAR', 'العملة المستخدمة', '2025-10-15 07:40:24', '2025-10-15 07:40:24'),
(6, 'receipt_prefix', 'REC', 'بادئة أرقام الإيصالات', '2025-10-15 07:40:24', '2025-10-15 07:40:24');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `role` enum('admin','staff','accountant') COLLATE utf8_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدير النظام', 'admin', 1, NULL, '2025-10-15 10:00:00', '2025-10-15 10:00:00'),
(2, 'staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'موظف الإدخال', 'staff', 1, NULL, '2025-10-15 10:00:00', '2025-10-15 10:00:00'),
(3, 'accountant', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'المحاسب المالي', 'accountant', 1, NULL, '2025-10-15 10:00:00', '2025-10-15 10:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_name` (`year_name`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `fee_type_id` (`fee_type_id`),
  ADD KEY `applied_by` (`applied_by`),
  ADD KEY `academic_year` (`academic_year`);

--
-- Indexes for table `educational_stages`
--
ALTER TABLE `educational_stages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stage_code` (`stage_code`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stage_id` (`stage_id`),
  ADD KEY `academic_year` (`academic_year`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stage_id` (`stage_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `student_fee_id` (`student_fee_id`),
  ADD KEY `received_by` (`received_by`),
  ADD KEY `academic_year` (`academic_year`);

--
-- Indexes for table `residential_areas`
--
ALTER TABLE `residential_areas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `area_name` (`area_name`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_code` (`student_code`),
  ADD KEY `stage_id` (`stage_id`),
  ADD KEY `academic_year` (`academic_year`),
  ADD KEY `students_ibfk_2` (`grade_id`),
  ADD KEY `area_id` (`area_id`);

--
-- Indexes for table `student_fees`
--
ALTER TABLE `student_fees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `fee_type_id` (`fee_type_id`),
  ADD KEY `academic_year` (`academic_year`),
  ADD KEY `status` (`status`),
  ADD KEY `student_fees_ibfk_area` (`area_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `educational_stages`
--
ALTER TABLE `educational_stages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `fee_types`
--
ALTER TABLE `fee_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `residential_areas`
--
ALTER TABLE `residential_areas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `student_fees`
--
ALTER TABLE `student_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=0;
--
-- Constraints for dumped tables
--

--
-- Constraints for table `discounts`
--
ALTER TABLE `discounts`
  ADD CONSTRAINT `discounts_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `discounts_ibfk_2` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `discounts_ibfk_3` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `educational_stages`
--
ALTER TABLE `educational_stages`
  ADD CONSTRAINT `educational_stages_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD CONSTRAINT `fee_types_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `educational_stages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fee_types_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `educational_stages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`student_fee_id`) REFERENCES `student_fees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `residential_areas`
--
ALTER TABLE `residential_areas`
  ADD CONSTRAINT `residential_areas_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `educational_stages` (`id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`),
  ADD CONSTRAINT `students_ibfk_area` FOREIGN KEY (`area_id`) REFERENCES `residential_areas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_fees`
--
ALTER TABLE `student_fees`
  ADD CONSTRAINT `student_fees_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_fees_ibfk_2` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_fees_ibfk_area` FOREIGN KEY (`area_id`) REFERENCES `residential_areas` (`id`) ON DELETE SET NULL;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
