-- Create database if not exists (for MySQL)
CREATE DATABASE IF NOT EXISTS welcome_app;
USE welcome_app;

-- Create table for welcome messages
CREATE TABLE IF NOT EXISTS welcome_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(255) DEFAULT NULL,
    message TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample welcome message
INSERT INTO welcome_messages (title, subtitle, message, is_active) VALUES
(
    'Welcome to Our Website!',
    'Crafted with PHP, HTML5, CSS3, and SQL',
    'We are thrilled to have you here. This greeting is loaded dynamically from your SQL database backend via PHP PDO. You can easily add, edit, or customize messages anytime.',
    1
);
