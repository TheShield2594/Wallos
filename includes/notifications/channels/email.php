<?php

class WallosEmailChannel extends WallosNotificationChannel
{
    const NAME = 'email';
    const LABEL = 'Email';
    const TABLE = 'email_notifications';

    /** @var string|false|null the approved SMTP IP; null until checked */
    private $smtpIp = null;

    protected function send(array $message, array $context)
    {
        $settings = $this->settings;

        // Re-validate at send time: a save-time check alone is bypassable via
        // DNS rebinding between when the host was saved and when the cron fires.
        // The mailer is then pinned to the approved IP so it cannot resolve
        // the host to somewhere else between this check and the connection.
        if ($this->smtpIp === null) {
            $this->smtpIp = $this->env->smtpValidatedIp($settings['smtp_address'] ?? '', (int) ($settings['smtp_port'] ?? 0));
            if ($this->smtpIp === false) {
                $this->env->log('SSRF attempt detected for SMTP host. Email notifications not sent.');
            }
        }
        if ($this->smtpIp === false) {
            return false;
        }

        $address = !empty($context['payer_email']) ? $context['payer_email'] : ($context['owner_email'] ?? '');
        $name = !empty($context['payer']) ? $context['payer'] : ($context['user'] ?? '');

        try {
            $mail = $this->env->mailerFactory !== null ? call_user_func($this->env->mailerFactory) : new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Timeout = 15;
            wallos_pin_mailer_to_ip($mail, $settings['smtp_address'], $this->smtpIp);
            $smtpAuth = ($settings['smtp_username'] ?? '') !== '' || ($settings['smtp_password'] ?? '') !== '';
            $mail->SMTPAuth = $smtpAuth;
            if ($smtpAuth) {
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'];
            }
            if (($settings['encryption'] ?? 'none') !== 'none') {
                $mail->SMTPSecure = $settings['encryption'];
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            $mail->Port = $settings['smtp_port'];

            $mail->setFrom(!empty($settings['from_email']) ? $settings['from_email'] : 'wallos@wallosapp.com', 'Tally');
            $mail->addAddress($address, $name);

            if (!empty($settings['other_emails'])) {
                $others = array_unique(array_map('trim', explode(';', $settings['other_emails'])));
                foreach ($others as $other) {
                    if ($other !== '' && $other !== $address) {
                        $mail->addCC($other);
                    }
                }
            }

            $mail->Subject = $message['title'];
            $mail->Body = $message['body'];

            if ($mail->send()) {
                $this->env->log('Email notification sent');
                return true;
            }
            $this->env->log('Error sending email notification to ' . $address . ': ' . $mail->ErrorInfo);
        } catch (\Throwable $e) {
            // PHPMailer sends to the accepted recipients, then throws for the
            // rejected ones. A rejected CC is not a failed reminder when the
            // payer's own address was accepted (it is not in the failed list).
            $recipientsFailed = $e instanceof PHPMailer\PHPMailer\Exception
                && $e->getCode() === PHPMailer\PHPMailer\PHPMailer::STOP_CONTINUE;
            if ($recipientsFailed && $address !== '' && stripos($e->getMessage(), $address) === false) {
                $this->env->log('Email notification sent; some CC addresses were rejected: ' . $e->getMessage());
                return true;
            }
            // Keep going: the next recipient may well be deliverable.
            $this->env->log('Error sending email notification to ' . $address . ': ' . $e->getMessage());
        }

        return false;
    }
}
