<?php
declare(strict_types=1);
namespace Engine\Atomic\Mail;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

class Mailer
{
    protected App $atomic;
    private static ?self $instance = null;

    protected ?\SMTP $smtp = null;
    protected array $recipients = [];
    protected array $message = [];
    protected array $headers = [];
    protected array $attachments = [];
    protected string $charset;
    
    private const EOL = "\r\n";

    private function __construct(string $charset = 'UTF-8')
    {
        $this->atomic = App::instance();
        $this->charset = $charset;
        $this->init_smtp();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function init_smtp(): void
    {
        $this->smtp = new \SMTP(
            $this->atomic->get('MAILER.smtp.host'),
            $this->atomic->get('MAILER.smtp.port'),
            $this->atomic->get('MAILER.smtp.scheme'),
            $this->atomic->get('MAILER.smtp.user'),
            $this->atomic->get('MAILER.smtp.pw')
        );

        if ($from = $this->atomic->get('MAILER.from_mail')) {
            $this->set_from($from, $this->atomic->get('MAILER.from_name'));
        }

        if ($reply = $this->atomic->get('MAILER.reply_to')) {
            $this->set_reply($reply);
        }

        if ($this->atomic->get('MAILER.force_tls', false)) {
            $this->smtp->set('SMTPSecure', 'tls');
        }
    }

    public function set_from(string $email, ?string $name = null): self
    {
        $this->smtp->set('From', $this->build_email($email, $name));
        return $this;
    }

    public function set_reply(string $email, ?string $name = null): self
    {
        $this->smtp->set('Reply-To', $this->build_email($email, $name));
        return $this;
    }

    public function add_to(string $email, ?string $name = null): self
    {
        if ($this->is_valid_email($email)) {
            $this->recipients['To'][$email] = $name;
        }
        return $this;
    }

    public function add_cc(string $email, ?string $name = null): self
    {
        if ($this->is_valid_email($email)) {
            $this->recipients['Cc'][$email] = $name;
        }
        return $this;
    }

    public function add_bcc(string $email, ?string $name = null): self
    {
        if ($this->is_valid_email($email)) {
            $this->recipients['Bcc'][$email] = $name;
        }
        return $this;
    }

    public function set_text(string $message): self
    {
        $this->message['text/plain'] = [
            'content' => $message,
            'type' => 'text/plain; charset=' . $this->charset
        ];
        return $this;
    }

    public function set_html(string $message): self
    {
        $this->message['text/html'] = [
            'content' => $message,
            'type' => 'text/html; charset=' . $this->charset
        ];
        return $this;
    }

    public function attach(string $path, ?string $alias = null, ?string $cid = null): self
    {
        if (is_file($path)) {
            $this->attachments[] = compact('path', 'alias', 'cid');
        }
        return $this;
    }

    public function add_header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(string $subject, bool $mock = false): bool
    {
        // Set recipients
        foreach ($this->recipients as $type => $rcpts) {
            $emails = [];
            foreach ($rcpts as $email => $name) {
                $emails[] = $this->build_email($email, $name);
            }
            if ($emails) {
                $this->smtp->set($type, implode(', ', $emails));
            }
        }

        // Set subject
        $this->smtp->set('Subject', $this->encode_header($subject));

        // Set custom headers
        foreach ($this->headers as $name => $value) {
            $this->smtp->set($name, $value);
        }

        // Build body
        $body = $this->build_body();

        // Process attachments
        foreach ($this->attachments as $att) {
            $this->smtp->attach($att['path'], $att['alias'], $att['cid']);
        }

        // Send
        $success = $this->smtp->send($this->encode($body), 'verbose', $mock);

        // Handle failure
        if (!$success && $handler = $this->atomic->get('MAILER.on.failure')) {
            $this->atomic->call($handler, [$this, $this->smtp->log()]);
        }

        return $success;
    }

    public function reset(): self
    {
        $this->recipients = [];
        $this->message = [];
        $this->headers = [];
        $this->attachments = [];
        $this->init_smtp();
        return $this;
    }

    private function build_body(): string
    {
        if (empty($this->message)) {
            return '';
        }

        if (count($this->message) === 1) {
            $msg = reset($this->message);
            $this->smtp->set('Content-Type', $msg['type']);
            return $msg['content'] . self::EOL;
        }

        // Multipart
        $boundary = uniqid('', true);
        $this->smtp->set('Content-Type', 'multipart/alternative; boundary="' . $boundary . '"');
        
        $body = '';
        foreach ($this->message as $msg) {
            $body .= '--' . $boundary . self::EOL;
            $body .= 'Content-Type: ' . $msg['type'] . self::EOL . self::EOL;
            $body .= $msg['content'] . self::EOL . self::EOL;
        }
        $body .= '--' . $boundary . '--' . self::EOL;

        return $body;
    }

    private function build_email(string $email, ?string $name = null): string
    {
        if ($name) {
            return '"' . $this->encode_header($name) . '" <' . $email . '>';
        }
        return '<' . $email . '>';
    }

    private function is_valid_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function encode(string $str): string
    {
        if ($this->charset === 'UTF-8') {
            return $str;
        }

        if (extension_loaded('iconv')) {
            $result = @iconv('UTF-8', $this->charset . '//IGNORE', $str);
            if ($result !== false) return $result;
        }

        if (extension_loaded('mbstring')) {
            return mb_convert_encoding($str, $this->charset, 'UTF-8');
        }

        return utf8_decode($str);
    }

    private function encode_header(string $str): string
    {
        if (extension_loaded('iconv')) {
            $encoded = iconv_mime_encode('Subject', $str, [
                'input-charset' => 'UTF-8',
                'output-charset' => $this->charset
            ]);
            return substr($encoded, strlen('Subject: '));
        }

        if (extension_loaded('mbstring')) {
            mb_internal_encoding('UTF-8');
            return mb_encode_mimeheader($str, $this->charset, 'B', self::EOL, strlen('Subject: '));
        }

        return wordwrap($str, 65, self::EOL);
    }

    private function __clone() {}
}
