<?php
namespace Whsuite\Email;

use Openbuildings\Swiftmailer\CssInlinerPlugin;

/**
 * Email Utility
 *
 * Extends SwiftMailer and sets it up ready to use with WHSuite. This simply
 * lets you set up SwiftMailer with a simple array of details. You can then use
 * the standard SwiftMailer functions.
 */
class Email extends \Swift
{
    public $transport;
    public $instance;

    public $to;
    public $cc;
    public $bcc;
    public $subject;
    public $body;

    /**
     * Load (cant be called init as it would conflict with Swift's own init method)
     *
     * @return object Returns a SwiftMailer instance. This is needed when sending a message.
     */
    public function load()
    {
        $transport = \App::get('configs')->get('settings.mail.mail_transport');

        // Set the transport type
        if ($transport == 'smtp') {
            $this->transport = \Swift_SmtpTransport::newInstance();
            $this->transport->setHost(\App::get('configs')->get('settings.mail.mail_smtp_host'));
            $this->transport->setPort(\App::get('configs')->get('settings.mail.mail_smtp_port'));

            if (\App::get('configs')->get('settings.mail.mail_smtp_ssl') == '1') {
                $this->transport->setEncryption('ssl');
            }

            if (\App::get('configs')->get('settings.mail.mail_smtp_username') != '') {
                $this->transport->setUsername(\App::get('configs')->get('settings.mail.mail_smtp_username'));
                $this->transport->setPassword(\App::get('configs')->get('settings.mail.mail_smtp_password'));
            }

        } elseif ($transport == 'sendmail') {
            $this->transport = \Swift_SendmailTransport::newInstance(\App::get('configs')->get('settings.mail.mail_sendmail_path'));
        } else {
            // No compatible transport type selected, fall back to PHP mail()
            $this->transport = \Swift_MailTransport::newInstance();
        }
        // Create a SwiftMailer instance with the chosen transport method
        $this->instance = \Swift_Mailer::newInstance($this->transport);

        // Add the CSS to inline CSS converter plugin
        $this->instance->registerPlugin(new CssInlinerPlugin());

        return $this->instance; // Return the instance for any use needed outside of this class
    }

    /**
     * Send Template
     *
     * Load, parse and send the email template
     *
     * @param  string $slug The unique slug of the email template to load
     * @param  array  $data Any data to have parsed into the email
     * @param  int    $lang The language id to use
     * @return bool   True = Mail sent.
     */
    public function sendTemplate($to, $slug, $html = true, $data = array(), $attachments = array(), $lang = '1')
    {
        // Load the template details
        if (is_int($slug)) {
            // The $slug var is the template id not the slug.
            $template = \EmailTemplate::find($slug);
        } else {
            $template = \EmailTemplate::where('slug', '=', $slug)->first();
        }

        if ($template) {
            // Attempt to load the template data in the selected language
            $template_data = \EmailTemplateTranslation::where('email_template_id', '=', $template->id)
                ->where('language_id', '=', $lang)->first();

            // If the selected language doesnt have a translation for this template, fall back to language id 1 (english/default)
            if (! $template_data) {
                $template_data = \EmailTemplateTranslation::where('email_template_id', '=', $template->id)
                    ->where('language_id', '=', '1')->first();
            }

            // If we still dont have an email template, lets quit as we cant send an email.
            if (! $template_data) {
                return false;
            }

            // Check if the email needs to be in HTML or Plaintext
            if ($html) {
                $body = $this->parseData(htmlspecialchars_decode($template_data->html_body), $data);
                $type = 'text/html';
            } else {
                $body = $this->parseData(htmlspecialchars_decode($template_data->plaintext_body), $data);
                $type = 'text/plain';
            }

            // Build the email
            $message = \Swift_Message::newInstance($this->parseData($template_data->subject, $data))
                ->setFrom(\App::get('configs')->get('settings.mail.send_emails_from'))
                ->setTo($to)
                ->setBody($body, $type);

            if ($template->cc != '') {
                if (strpos($template->cc, ',')) {
                    $message->setCc(explode(',', $template->cc));
                } else {
                    $message->setCc($template->cc);
                }
            }

            if ($template->bcc != '') {
                if (strpos($template->bcc, ',')) {
                    $message->setBcc(explode(',', $template->bcc));
                } else {
                    $message->setBcc($template->bcc);
                }
            }

            // Add the body and subject to our temp vars for storing in the log later.
            $this->subject = $template_data->subject;
            $this->body = $body;
            $this->to = $to;
            $this->cc = $template->cc;
            $this->bcc = $template->bcc;

            // Optionally add any attachments
            if (! empty($attachments)) {
                foreach ($attachments as $attachment) {
                    // If the filename is not set, set this to false and Swiftmailer will take care of it.
                    if (! isset($attachment['filename'])) {
                        $attachment['filename'] = null;
                    }
                    // If the mime type isnt known, set this to false and again, Swiftmailer will take care of it.
                    if (! isset($attachment['mime_type'])) {
                        $attachment['mime_type'] = null;
                    }

                    // We have two types of attachment. You can either provide the raw data (which is the most
                    // reliable option) or a URL - note that setting the type to URL also works for local files.
                    if ($attachment['type'] == 'url') {
                        // If we're doing a URL we can run fromPath, which works with URLs and local files.
                        $a = \Swift_Attachment::fromPath($attachment['data'], $attachment['mime_type']);
                    } elseif ($attachment['type'] == 'data') {
                        // If we have raw data to attach, we use a newInstance of SwiftAttachment. We then provide it
                        // with the raw attachment data, and can optionally provide a filename and mime type.
                        $a = \Swift_Attachment::newInstance($attachment['data'], $attachment['filename'], $attachment['mime_type']);
                    }

                     // All sorted? Great - lets attach the file and move on.
                    $message->attach($a);
                }
            }

            // Send the message.
            try {
                return $this->instance->send($message);
            } catch (\Exception $e) {
                return false;
            }
        }

        return false; // Template not found
    }

    /**
     * Send Email Template To Client
     *
     * Implements the email template sender (sendTemplate) but in a quicker/easier
     * method, that also stores a copy of the email in the client_emails table.
     *
     * @param  integer $client_id ID of the client to email
     * @param  string $slug The unique slug of the email template to load
     * @param  array  $data Any data to have parsed into the email
     * @param  array $attachments any attachments to include with the email.
     * @param  bool $skip_logging Set to true to skip storing a copy of the email in the database
     * @return bool True = Mail sent and logged.
     */
    public function sendTemplateToClient($client_id, $slug, $data = array(), $attachments = array(), $skip_logging = false)
    {
        $client = \Client::find($client_id);

        $data['client'] = $client;
        $data['settings'] = \App::get('configs')->get('settings');

        $html = false;
        if ($client->html_emails == '1') {
            $html = true;
        }

        if ($this->sendTemplate($client->email, $slug, $html, $data, $attachments, $client->language_id)) {
            if (! $skip_logging) {
                // insert logged record of the email
                $client_email = new \ClientEmail;
                $client_email->client_id = $client_id;
                $client_email->subject = $this->subject;
                $client_email->body = $this->body;
                $client_email->to = $this->to;
                $client_email->cc = $this->cc;
                $client_email->bcc = $this->bcc;

                return $client_email->save();
            } else {
                return true;
            }
        }
        return false;
    }

    /**
     * Send Email Template To Staff
     *
     * Implements the email template sender (sendTemplate) but in a quicker/easier
     * method.
     *
     * @param  integer $admin_id ID of the admin to email
     * @param  string $slug The unique slug of the email template to load
     * @param  array  $data Any data to have parsed into the email
     * @param  array $attachments any attachments to include with the email.
     * @param  bool $skip_logging Set to true to skip storing a copy of the email in the database
     */
    public function sendTemplateToStaff($staff_id, $slug, $data = array(), $attachments = array())
    {
        $user = \Staff::find($staff_id);

        $data['staff'] = $user;
        $data['settings'] = \App::get('configs')->get('settings');

        // Load Language
        $language = \Language::where('slug', '=', $user->language)->first();

        if (! $language) {
            $language_id = '1';
        } else {
            $language_id = $language->id;
        }

        $html = true; // We don't yet offer the option to set html/plaintext for staff so default to plaintext

        if ($this->sendTemplate($user->email, $slug, $html, $data, $attachments, $language_id)) {
            return true;
        }

        return false;
    }

    /**
     * Send Email
     *
     * Sends a standard email (that doesn't use a template)
     *
     * @return bool   True = Mail sent.
     */
    public function sendEmail(
        $to,
        $subject,
        $body,
        $html = true,
        $data = array(),
        $cc = false,
        $bcc = false,
        $from = null,
        $attachments = array(),
        $lang = '1',
        $reply_to = false
    ) {
        // Add settings to the data array
        $data['settings'] = \App::get('configs')->get('settings');

        // Check if the email needs to be in HTML or Plaintext
        if ($html) {
            $type = 'text/html';
        } else {
            $type = 'text/plain';
        }

        if (! $from) {
            $from = \App::get('configs')->get('settings.mail.send_emails_from');
        }

        // Build the email
        $message = \Swift_Message::newInstance($this->parseData($subject, $data))
            ->setFrom($from)
            ->setTo($to)
            ->setBody($body, $type);

        if ($cc) {
            $message->setCc($cc);
        }

        if ($bcc) {
            $message->setBcc($bcc);
        }

        if ($reply_to) {
            $message->setReplyTo($reply_to);
        }

        // Optionally add any attachments
        if (! empty($attachments)) {
            foreach ($attachments as $attachment) {
                // If the filename is not set, set this to false and Swiftmailer will take care of it.
                if (! isset($attachment['filename'])) {
                    $attachment['filename'] = null;
                }
                // If the mime type isnt known, set this to false and again, Swiftmailer will take care of it.
                if (! isset($attachment['mime_type'])) {
                    $attachment['mime_type'] = null;
                }

                // We have two types of attachment. You can either provide the raw data (which is the most
                // reliable option) or a URL - note that setting the type to URL also works for local files.
                if ($attachment['type'] == 'url') {
                    // If we're doing a URL we can run fromPath, which works with URLs and local files.
                    $a = \Swift_Attachment::fromPath($attachment['data'], $attachment['mime_type']);
                } elseif ($attachment['type'] == 'data') {
                    // If we have raw data to attach, we use a newInstance of SwiftAttachment. We then provide it
                    // with the raw attachment data, and can optionally provide a filename and mime type.
                    $a = \Swift_Attachment::newInstance($attachment['data'], $attachment['filename'], $attachment['mime_type']);
                }

                 // All sorted? Great - lets attach the file and move on.
                $message->attach($a);
            }
        }

        // Send the message.
        try {
            return $this->instance->send($message);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Parse Data
     *
     * A basic function to parse template variables
     *
     * @param  string $body The raw string to run through the parser
     * @param  array  $data The array of data to attempt to parse
     * @return string The parsed string
     */
    public function parseData($body, $data = array())
    {
        $mustache = new \Mustache_Engine;

        if (! empty($data['settings']) && is_array($data['settings'])) {
            foreach ($data['settings'] as &$item) {
                if (is_string($item)) {
                    $item = htmlspecialchars_decode($item);
                } elseif (is_array($item)) {
                    foreach ($item as &$sub_item) {
                        if (is_string($sub_item)) {
                            $sub_item = htmlspecialchars_decode($sub_item);
                        }
                    }
                }
            }
        }

        return $mustache->render($body, $data);
    }
}
