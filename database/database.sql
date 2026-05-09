-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 09, 2026 at 09:34 PM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `telegram_job_bot`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=support, 2=editor, 3=super',
  `permissions` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discounts`
--

CREATE TABLE `discounts` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `amount` int(11) NOT NULL COMMENT 'مبلغ تخفیف به سکه',
  `max_uses` int(11) DEFAULT 1,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL COMMENT 'متن آگهی',
  `contact_info` varchar(255) NOT NULL COMMENT '@username یا شماره تماس',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=pending, 2=active, 3=expired, 4=rejected',
  `admin_note` text DEFAULT NULL COMMENT 'دلیل رد توسط ادمین',
  `channel_post_id` int(11) DEFAULT NULL COMMENT 'ID پیام در کانال',
  `published_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `type` enum('string','integer','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key`, `value`, `type`, `description`, `is_public`, `updated_at`) VALUES
(1, 'coin_value', '1000', 'integer', 'ارزش هر سکه به تومان (1 سکه = 1000 تومان)', 1, '2026-05-09 09:08:03'),
(2, 'job_price', '10', 'integer', 'قیمت انتشار آگهی به سکه', 1, '2026-05-09 09:08:03'),
(3, 'referral_bonus', '2', 'integer', 'پاداش دعوت به سکه', 1, '2026-05-09 09:08:03'),
(4, 'welcome_bonus', '10', 'integer', 'هدیه ثبت‌نام به سکه', 1, '2026-05-09 09:08:03'),
(5, 'min_deposit', '10', 'integer', 'حداقل شارژ به سکه', 1, '2026-05-09 09:08:03'),
(6, 'max_deposit', '10000', 'integer', 'حداکثر شارژ به سکه', 1, '2026-05-09 09:08:03'),
(7, 'job_expire_days', '30', 'integer', 'مدت اعتبار آگهی به روز', 0, '2026-05-09 09:08:03'),
(8, 'maintenance_mode', 'false', 'boolean', 'حالت تعمیرات', 0, '2026-05-09 09:08:03'),
(9, 'bot_username', '', 'string', 'نام کاربری ربات', 1, '2026-05-09 09:08:03');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ticket_number` varchar(20) NOT NULL COMMENT 'شماره پیگیری',
  `subject` varchar(255) NOT NULL COMMENT 'عنوان مشکل',
  `message` text NOT NULL COMMENT 'متن اصلی',
  `attachment_url` varchar(500) DEFAULT NULL COMMENT 'لینک عکس/فایل ضمیمه',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=open, 2=answered, 3=closed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL COMMENT 'مقدار به سکه (+ یا -)',
  `balance_before` int(11) NOT NULL COMMENT 'موجودی قبل',
  `balance_after` int(11) NOT NULL COMMENT 'موجودی بعد',
  `type` tinyint(1) NOT NULL COMMENT '1=deposit, 2=withdraw, 3=job_payment, 4=referral_bonus, 5=welcome_bonus, 6=discount, 7=refund',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'job, discount, payment',
  `reference_id` int(11) DEFAULT NULL COMMENT 'ID جدول مربوطه',
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 2 COMMENT '1=pending, 2=completed, 3=failed, 4=cancelled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `telegram_id` bigint(20) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `referral_code` varchar(50) NOT NULL,
  `referred_by` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=active, 2=blocked, 3=deleted',
  `last_activity` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_discounts`
--

CREATE TABLE `user_discounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `discount_id` int(11) NOT NULL,
  `used_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `balance` int(11) NOT NULL DEFAULT 0 COMMENT 'موجودی به سکه',
  `frozen_balance` int(11) NOT NULL DEFAULT 0 COMMENT 'سکه مسدود شده برای آگهی در انتظار',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expires_at` (`expires_at`);
ALTER TABLE `jobs` ADD FULLTEXT KEY `ft_jobs_search` (`content`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user_status` (`user_id`,`status`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user_date` (`user_id`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telegram_id` (`telegram_id`),
  ADD UNIQUE KEY `referral_code` (`referral_code`),
  ADD KEY `referred_by` (`referred_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indexes for table `user_discounts`
--
ALTER TABLE `user_discounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `discount_id` (`discount_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_discounts`
--
ALTER TABLE `user_discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `fk_admins_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_discounts`
--
ALTER TABLE `user_discounts`
  ADD CONSTRAINT `fk_user_discounts_discount_id` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_discounts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wallets`
--
ALTER TABLE `wallets`
  ADD CONSTRAINT `fk_wallet_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
