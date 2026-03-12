<?php

namespace App\Helpers;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailHelper
{
    public static function sendMail($to, $subject, $body, $isHtml = true, $attachments = [])
    {
        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME');
            $mail->Password   = env('MAIL_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            $mail->addAddress($to);

            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body    = $body;

            /* -----------------------------
            ATTACHMENTS
            ----------------------------- */

            if (!empty($attachments)) {

                foreach ($attachments as $file) {

                    if (is_array($file)) {
                        $mail->addAttachment($file['path'], $file['name']);
                    } else {
                        $mail->addAttachment($file);
                    }
                }
            }

            $mail->send();

            return true;
        } catch (Exception $e) {

            return $mail->ErrorInfo;
        }
    }
}
