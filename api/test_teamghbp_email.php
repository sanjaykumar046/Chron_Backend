<?php
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
function testSMTP($port, $encryption) {
    $mail = new PHPMailer(true);
    try {
        echo "\n========== Testing Port $port with $encryption ==========\n";

        $mail->SMTPDebug = 2;
        $mail->isSMTP();
        $mail->Host = 'mail.teamghbp.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'PMS.Reports';
        $mail->Password = 'GlobalHealth@2026';

        if ($encryption === 'SMTPS') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'STARTTLS') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->Port = $port;
        $mail->Timeout = 30;

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('PMS.Reports@teamghbp.com', 'Test');
        $mail->addAddress('your-email@gmail.com'); // Replace!
        $mail->Subject = "Test from port $port";
        $mail->Body = "Successfully sent via port $port with $encryption";

        $mail->send();
        echo "\n? SUCCESS on port $port with $encryption!\n\n";
        return true;

    } catch (Exception $e) {
        echo "\n? FAILED on port $port: " . $mail->ErrorInfo . "\n\n";
        return false;
    }
}
// Test in order of likelihood
echo "Testing mail.teamghbp.com...\n";
// Test 1: Port 465 with SSL (client specified SMTP port)
if (testSMTP(465, 'SMTPS')) exit;
// Test 2: Port 995 with SSL (client's POP port, might work)
if (testSMTP(995, 'SMTPS')) exit;
// Test 3: Port 587 with STARTTLS (common alternative)
if (testSMTP(587, 'STARTTLS')) exit;
// Test 4: Port 25 unencrypted (last resort)
if (testSMTP(25, 'none')) exit;
echo "All ports failed. Contact client's IT admin.\n";