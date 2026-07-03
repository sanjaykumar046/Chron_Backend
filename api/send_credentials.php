<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Make sure to install PHPMailer via composer

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['to_email']) || !isset($data['admin_username']) || !isset($data['admin_password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$mail = new PHPMailer(true);

try {
    // SMTP Configuration
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com'; // Change to your SMTP host
    $mail->SMTPAuth = true;
    $mail->Username = 'your-email@gmail.com'; // Your SMTP username
    $mail->Password = 'your-app-password'; // Your SMTP password or app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Recipients
    $mail->setFrom('noreply@yourcompany.com', 'Tenant Management System');
    $mail->addAddress($data['to_email']);

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Welcome to ' . $data['company_name'] . ' - Account Credentials';
    
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .credentials { background-color: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #4CAF50; }
            .credentials strong { color: #4CAF50; }
            .footer { text-align: center; margin-top: 20px; color: #777; font-size: 12px; }
            .button { display: inline-block; padding: 12px 30px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to Your New Account!</h1>
            </div>
            <div class='content'>
                <p>Dear Admin,</p>
                <p>Your tenant account for <strong>{$data['company_name']}</strong> has been successfully created.</p>
                
                <div class='credentials'>
                    <h3>Your Login Credentials:</h3>
                    <p><strong>Username:</strong> {$data['admin_username']}</p>
                    <p><strong>Password:</strong> {$data['admin_password']}</p>
                </div>
                
                <p><strong>License Information:</strong></p>
                <ul>
                    <li>Start Date: {$data['license_start_date']}</li>
                    <li>End Date: {$data['license_end_date']}</li>
                </ul>
                
                <p><strong>Important Security Notes:</strong></p>
                <ul>
                    <li>Please change your password after first login</li>
                    <li>Do not share your credentials with anyone</li>
                    <li>Keep this email secure for your records</li>
                </ul>
                
                <center>
                    <a href='https://yourcompany.com/login' class='button'>Login Now</a>
                </center>
                
                <p style='margin-top: 30px;'>If you have any questions, please contact our support team.</p>
                <p>Best regards,<br>The Tenant Management Team</p>
            </div>
            <div class='footer'>
                <p>This is an automated email. Please do not reply to this message.</p>
                <p>&copy; 2025 Your Company Name. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $mail->Body = $emailBody;
    $mail->AltBody = "Username: {$data['admin_username']}\nPassword: {$data['admin_password']}\nLicense: {$data['license_start_date']} to {$data['license_end_date']}";

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Credentials sent successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Email could not be sent. Error: ' . $mail->ErrorInfo]);
}
?>