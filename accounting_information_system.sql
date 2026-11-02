-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 08, 2026 at 09:59 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `accounting_information_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

CREATE TABLE `assessments` (
  `assessment_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `type` enum('Quiz','Examination','Exercise') NOT NULL,
  `title` varchar(200) NOT NULL,
  `total_points` decimal(10,2) DEFAULT 0.00,
  `schedule_datetime` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assessment_questions`
--

CREATE TABLE `assessment_questions` (
  `question_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(50) NOT NULL,
  `correct_answer` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assessment_submissions`
--

CREATE TABLE `assessment_submissions` (
  `submission_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `answers` text DEFAULT NULL,
  `score` decimal(10,2) DEFAULT 0.00,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `instructions` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `timestamp`) VALUES
(1, 2, 'User logged in', '2026-10-04 00:52:53'),
(2, 2, 'User logged out', '2026-10-04 00:53:42'),
(3, 2, 'User logged in', '2026-10-04 00:53:51'),
(4, 2, 'User logged out', '2026-10-04 00:53:53'),
(5, 1, 'User logged in', '2026-10-04 00:54:10'),
(6, 1, 'User logged out', '2026-10-04 01:07:46'),
(7, 3, 'User logged in', '2026-10-04 01:08:15'),
(8, 3, 'User logged out', '2026-10-04 01:19:45'),
(9, 1, 'User logged in', '2026-10-04 01:19:55'),
(10, 1, 'Created user jjacob@example.com (Faculty)', '2026-10-04 01:20:51'),
(11, 1, 'User logged out', '2026-10-04 01:21:03'),
(12, 4, 'User logged in', '2026-10-04 01:21:16'),
(13, 4, 'User logged out', '2026-10-04 01:21:31'),
(14, 1, 'User logged in', '2026-10-04 01:21:40'),
(15, 1, 'User logged in', '2026-10-05 14:12:14'),
(16, 1, 'User logged out', '2026-10-05 14:12:54'),
(17, 4, 'User logged in', '2026-10-05 14:13:38'),
(18, 4, 'User logged out', '2026-10-05 14:13:49'),
(19, 1, 'User logged in', '2026-10-05 14:31:49'),
(20, 1, 'User logged in', '2026-10-06 07:02:59'),
(21, 1, 'User logged out', '2026-10-06 07:42:00'),
(22, 3, 'User logged in', '2026-10-06 07:42:19'),
(23, 3, 'User logged out', '2026-10-06 07:45:11'),
(24, 4, 'User logged in', '2026-10-07 12:10:30'),
(25, 4, 'User logged out', '2026-10-08 01:02:24'),
(26, 1, 'User logged in', '2026-10-08 01:02:36'),
(27, 1, 'Disabled account jjacob@example.com', '2026-10-08 01:02:55'),
(28, 1, 'User logged out', '2026-10-08 01:03:10'),
(29, 1, 'User logged in', '2026-10-08 01:04:07'),
(30, 1, 'User logged out', '2026-10-08 01:18:47'),
(31, 3, 'User logged in', '2026-10-08 01:18:56'),
(32, 3, 'User logged out', '2026-10-08 01:19:47'),
(33, 2, 'User logged in', '2026-10-08 01:19:54'),
(34, 2, 'User logged out', '2026-10-08 01:20:39'),
(35, 1, 'User logged in', '2026-10-08 01:20:47'),
(36, 1, 'Recorded journal entry: Valid', '2026-10-08 04:21:38'),
(37, 1, 'User logged out', '2026-10-08 04:22:19'),
(38, 2, 'User logged in', '2026-10-08 04:22:27'),
(39, 2, 'User logged out', '2026-10-08 04:23:12'),
(40, 3, 'User logged in', '2026-10-08 04:23:38'),
(41, 3, 'User logged out', '2026-10-08 04:24:14'),
(42, 1, 'User logged in', '2026-10-08 04:25:39'),
(43, 1, 'User logged out', '2026-10-08 07:59:11'),
(44, 1, 'User logged in', '2026-10-08 07:59:20');

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `account_id` int(11) NOT NULL,
  `account_code` varchar(30) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`account_id`, `account_code`, `account_name`, `account_type`, `description`) VALUES
(1, '101', 'Cash', 'Asset', 'Cash available for business operations'),
(2, '110', 'Accounts Receivable', 'Asset', 'Amounts collectible from customers'),
(3, '201', 'Accounts Payable', 'Liability', 'Amounts owed to suppliers and creditors'),
(4, '301', 'Owner Capital', 'Equity', 'Owner\'s investment in the business'),
(5, '401', 'Service Revenue', 'Revenue', 'Income earned from services rendered'),
(6, '501', 'Rent Expense', 'Expense', 'Cost of rent for business premises');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `course_id` int(11) NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `course_name` varchar(200) NOT NULL,
  `professor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`course_id`, `course_code`, `course_name`, `professor_id`) VALUES
(1, 'ACCT101', 'Fundamentals of Accounting', 2);

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`enrollment_id`, `student_id`, `course_id`, `semester`, `status`) VALUES
(1, 3, 1, '2026-2027 1st Semester', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `grade_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `assessment_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `score` decimal(10,2) NOT NULL,
  `max_score` decimal(10,2) NOT NULL DEFAULT 100.00,
  `date_recorded` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `entry_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `ai_validation_status` enum('Valid','Invalid') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`entry_id`, `student_id`, `entry_date`, `description`, `ai_validation_status`, `created_at`) VALUES
(1, 1, '2026-10-08', 'Rent', 'Valid', '2026-10-08 04:21:38');

-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `line_id` int(11) NOT NULL,
  `entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit_amount` decimal(15,2) DEFAULT 0.00,
  `credit_amount` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_lines`
--

INSERT INTO `journal_lines` (`line_id`, `entry_id`, `account_id`, `debit_amount`, `credit_amount`) VALUES
(1, 1, 1, 1200.00, 1200.00),
(2, 1, 6, 1000.00, 1000.00);

-- --------------------------------------------------------

--
-- Table structure for table `learning_modules`
--

CREATE TABLE `learning_modules` (
  `module_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content_type` varchar(50) NOT NULL,
  `content_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `audience` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_submissions`
--

CREATE TABLE `task_submissions` (
  `submission_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `topic` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Faculty','Student') NOT NULL,
  `two_factor_secret` varchar(255) NOT NULL,
  `status` enum('Active','Pending','Disabled') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `name`, `email`, `password`, `role`, `two_factor_secret`, `status`, `created_at`) VALUES
(1, 'System Administrator', 'admin@example.com', '$2y$12$fvYGuMSboiEar7EGggu9DO8B4VxNrsYh6Y7BaBkRcNjmLVYBOL3V6', 'Admin', 'MTIzNDU2Nzg5MDEyMzQ1Ng==', 'Active', '2026-10-04 00:49:12'),
(2, 'Faculty Demo', 'faculty@example.com', '$2y$12$fvYGuMSboiEar7EGggu9DO8B4VxNrsYh6Y7BaBkRcNjmLVYBOL3V6', 'Faculty', 'MTIzNDU2Nzg5MDEyMzQ1Ng==', 'Active', '2026-10-04 00:49:12'),
(3, 'Student Demo', 'student@example.com', '$2y$12$fvYGuMSboiEar7EGggu9DO8B4VxNrsYh6Y7BaBkRcNjmLVYBOL3V6', 'Student', 'MTIzNDU2Nzg5MDEyMzQ1Ng==', 'Active', '2026-10-04 00:49:12'),
(4, 'Jakee Jacob', 'jjacob@example.com', '$2y$10$MnKzZzmE/pCKTMSB4V1DUOqPAOlB8z3GMIlEAnObLGkaWybOhtj1m', 'Faculty', 'F+q/SyNzTLY+v0fcp6d+xw==', 'Disabled', '2026-10-04 01:20:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assessments`
--
ALTER TABLE `assessments`
  ADD PRIMARY KEY (`assessment_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  ADD PRIMARY KEY (`question_id`),
  ADD KEY `assessment_id` (`assessment_id`);

--
-- Indexes for table `assessment_submissions`
--
ALTER TABLE `assessment_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `account_code` (`account_code`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`course_id`),
  ADD KEY `professor_id` (`professor_id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`grade_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`line_id`),
  ADD KEY `entry_id` (`entry_id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `learning_modules`
--
ALTER TABLE `learning_modules`
  ADD PRIMARY KEY (`module_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `task_submissions`
--
ALTER TABLE `task_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assessments`
--
ALTER TABLE `assessments`
  MODIFY `assessment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  MODIFY `question_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assessment_submissions`
--
ALTER TABLE `assessment_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `line_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `learning_modules`
--
ALTER TABLE `learning_modules`
  MODIFY `module_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_submissions`
--
ALTER TABLE `task_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assessments`
--
ALTER TABLE `assessments`
  ADD CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `assessment_questions`
--
ALTER TABLE `assessment_questions`
  ADD CONSTRAINT `assessment_questions_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`assessment_id`) ON DELETE CASCADE;

--
-- Constraints for table `assessment_submissions`
--
ALTER TABLE `assessment_submissions`
  ADD CONSTRAINT `assessment_submissions_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`assessment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assessment_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `journal_lines_ibfk_1` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`entry_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_lines_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`account_id`);

--
-- Constraints for table `learning_modules`
--
ALTER TABLE `learning_modules`
  ADD CONSTRAINT `learning_modules_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `task_submissions`
--
ALTER TABLE `task_submissions`
  ADD CONSTRAINT `task_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
