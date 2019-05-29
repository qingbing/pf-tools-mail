<?php
/**
 * Link         :   http://www.phpcorner.net
 * User         :   qingbing<780042175@qq.com>
 * Date         :   2019-01-11
 * Version      :   1.0
 */

namespace Tools;


use Abstracts\Component;
use Helper\Exception;

class Email extends Component
{
    /* @var string Smtp host, eg: smtp.163.com */
    public $smtp_host;
    /* @var int Smtp port. */
    public $smtp_port = 25;
    /* @var int Timeout for smtp. */
    public $smtp_timeout = 5;
    /* @var string The relay mail, eg: example@163.com */
    public $mail_addr;
    /* @var string The account about relay mail, eg: example */
    public $mail_user;
    /* @var string The password about the mail account. */
    public $mail_pass;
    /* @var string The display name on the receive mail */
    public $disp_name;
    /* @var string The type of mail content, eg: text, html */
    public $mail_type = 'html';
    /* @var string The charset for mail component. */
    public $charset = 'utf-8';
    /* @var int The current priority of mail component. */
    public $priority = 2;
    /* @var boolean Whether need auth. */
    public $auth = true;

    private $_relay_mail;
    private $_headers = [];
    private $_to_array = [];
    private $_cc_array = [];
    private $_bcc_array = [];
    private $_user_agent = 'phpcorner.net';
    private $_host_name = 'phpcorner';
    private $_priorities = ['1 (Highest)', '2 (High)', '3 (Normal)', '4 (Low)', '5 (Lowest)'];
    private $_sock;
    private $_errors = []; // error data.

    /**
     * Initialize the configures of mail component.
     * @throws Exception
     */
    public function init()
    {
        // Check the from mail format.
        if (preg_match('#^(.*)<((.*)@(.*))>$#', $this->mail_addr, $matches)) {
            $mail_addr = $matches[2];
            $mail_user = $matches[3];
            $disp_name = $matches[1];
            $relay_host = $matches[4];
        } else if (preg_match('#^(.*)@(.*)$#', $this->mail_addr, $matches)) {
            $mail_addr = $matches[0];
            $mail_user = $matches[1];
            $disp_name = $matches[1];
            $relay_host = $matches[2];
        } else {
            throw new Exception(str_cover('邮件中转地址 ({mail}) 无效', [
                '{mail}' => $this->mail_addr,
            ]), 102600101);
        }

        // Validate and set relay email address.
        if (!$this->_validateEmail($mail_addr)) {
            throw new Exception(str_cover('邮件中转地址 ({mail}) 无效', [
                '{mail}' => $mail_addr,
            ]), 102600102);
        } else {
            $this->_relay_mail = $mail_addr;
        }

        // Check empty mail password.
        if (empty($this->mail_pass)) {
            throw new Exception('中转邮件地址密码不能为空', 102600103);
        }

        // Check relay smtp sever.
        if (empty($this->smtp_host)) {
            $this->smtp_host = 'smtp.' . $relay_host;
        }

        // Check the smtp user.
        if (empty($this->mail_user)) {
            $this->mail_user = $mail_user;
        }

        // Check the display name.
        if (empty($this->disp_name)) {
            $this->disp_name = $disp_name;
        }
    }

    /**
     * Set type for mail content.
     * @param string $mail_type => text | html
     */
    public function setMailType($mail_type = 'text')
    {
        $mail_type = strtolower($mail_type);
        $this->mail_type = 'text' === $mail_type ? 'text' : 'html';
    }

    /**
     * Set the TO email addresses, and reset CC and BCC email addresses.
     * @param mixed $address
     */
    public function setTo($address)
    {
        // Set TO email addresses.
        $this->_to_array = $this->_getEmailArray($address);
        unset($this->_headers['To']);

        // Reset CC and BCC email addresses.
        $this->_cc_array = [];
        $this->_bcc_array = [];
        unset($this->_headers['Cc']);
    }

    /**
     * Add some new email addresses for TO.
     * @param mixed $address
     */
    public function addTo($address)
    {
        $this->_to_array = array_merge($this->_to_array, $this->_getEmailArray($address));
    }

    /**
     * Set the CC email addresses.
     * @param mixed $address
     */
    public function setCc($address)
    {
        $this->_cc_array = $this->_getEmailArray($address);
        unset($this->_headers['Cc']);
    }

    /**
     * Add some new email addresses for CC.
     * @param mixed $address
     */
    public function addCC($address)
    {
        $this->_cc_array = array_merge($this->_cc_array, $this->_getEmailArray($address));
    }

    /**
     * Set the BCC email addresses.
     * @param mixed $address
     */
    public function setBcc($address)
    {
        $this->_bcc_array = $this->_getEmailArray($address);
    }

    /**
     * Add some new email addresses for BCC.
     * @param mixed $address
     */
    public function addBcc($address)
    {
        $this->_bcc_array = array_merge($this->_bcc_array, $this->_getEmailArray($address));
    }

    /**
     * Send mail.
     * @param string $subject
     * @param string $body
     * @return bool
     */
    public function send($subject, $body = '')
    {
        // Set mail type header.
        if ('html' === strtolower($this->mail_type)) {
            $this->_setHeader('Content-Type', 'text/html');
        } else {
            unset($this->_headers['Content-Type']);
        }

        // Set date header information.
        $timezone = date('Z');
        $operator = (strncmp($timezone, '-', 1) == 0) ? '-' : '+';
        $timezone = abs($timezone);
        $timezone = floor($timezone / 3600) * 100 + ($timezone % 3600) / 60;
        $date = sprintf("%s %s%04d", date("D, j M Y H:i:s"), $operator, $timezone);
        $this->_setHeader('Date', $date);

        // Set Message-ID.
        list($msec, $sec) = explode(" ", microtime());
        $this->_setHeader('Message-ID', '<' . date("YmdHis", $sec) . "." . ($msec * 1000000) . "." . $this->_relay_mail . '>');

        // Set Subject header information.
        $this->_setHeader('Subject', $this->_QuoteEncoding($subject));

        //Set To header and merge send addresses.
        if (empty($this->_to_array)) {
            return false;
        }
        $t = [];
        foreach ($this->_to_array as $m) {
            array_push($t, "{$m['disp_name']}<{$m['mail_addr']}>");
        }
        $this->_setHeader('To', implode(', ', $t));

        $addresses = $this->_to_array;

        // Set Cc header information.
        if (!empty($this->_cc_array)) {
            $t = [];
            foreach ($this->_cc_array as $m) {
                array_push($t, "{$m['disp_name']}<{$m['mail_addr']}>");
            }
            $this->_setHeader('Cc', implode(', ', $t));
            $addresses = array_merge($addresses, $this->_cc_array);
        }

        // Merge the bcc email addresses.
        if (!empty($this->_bcc_array)) {
            $addresses = array_merge($addresses, $this->_bcc_array);
        }

        // Trim body.
        $body = rtrim(str_replace("\r", '', $body));
        // Note : In PHP 5.4, get_magic_quotes_gpc always return 0 and it will probable not exist in future versions at all.
        if (floatval(PHP_VERSION) < 5.4 && get_magic_quotes_gpc()) {
            $body = stripslashes($body);
        }

        /**
         * Send mail.
         */
        $status = true;
        $header = $this->_buildHeader();
        foreach ($addresses as $mail) {
            $mail_addr = $mail['mail_addr'];
            // Connect relay smtp server.
            if (!$this->smtp_sockopen()) {
                $this->addError('发邮件到 {mail} 失败', [
                    '{mail}' => $mail_addr,
                ]);
                $status = false;
                continue;
            }
            // Send mail to relay server.
            if (!$this->smtp_send($mail_addr, $header, $body)) {
                $this->addError('发邮件到 {mail} 失败', [
                    '{mail}' => $mail_addr,
                ]);
                $status = false;
                continue;
            }
            // Close sock.
            fclose($this->_sock);
        }
        return $status;
    }

    /**
     * Send mail.
     * @param mixed $to
     * @param string $subject
     * @param string $body
     * @param mixed $cc
     * @param mixed $bcc
     * @return bool
     */
    public function sendMail($to, $subject = '', $body = '', $cc = '', $bcc = '')
    {
        // Set common header information.
        $this->_setCommonHeader();

        // Set to email address.
        $this->setTo($to);

        // Set cc email address.
        $this->setCc($cc);

        // Set bcc email address.
        $this->setBcc($bcc);

        return $this->send($subject, $body);
    }

    /**
     * Send a mail message.
     * @param string $to
     * @param string $header
     * @param string $body
     * @return bool
     */
    protected function smtp_send($to, $header, $body)
    {
        // Send HELO command to sock.
        if (!$this->smtp_putcmd('HELO', $this->_host_name)) {
            $this->addError('发送 HELO 命令失败');
            return false;
        }
        // Auth user.
        if ($this->auth) {
            if (!$this->smtp_putcmd('AUTH LOGIN', base64_encode($this->mail_user))) {
                $this->addError('用户认证失败');
                return false;
            }
            if (!$this->smtp_putcmd('', base64_encode($this->mail_pass))) {
                $this->addError('认证用户密码失败');
                return false;
            }
        }
        if (!$this->smtp_putcmd("MAIL", "FROM:<" . $this->_relay_mail . ">")) {
            $this->addError('发送 FROM 命令失败');
            return false;
        }
        if (!$this->smtp_putcmd("RCPT", "TO:<" . $to . ">")) {
            $this->addError('发送 RCPT TO 命令失败');
            return false;
        }
        if (!$this->smtp_putcmd("DATA")) {
            $this->addError('发送 DATA 命令失败');
            return false;
        }
        if (!$this->smtp_message($header, $body)) {
            $this->addError('发送 HEADER 命令失败');
            return false;
        }
        if (!$this->smtp_eom()) {
            $this->addError('插入特殊标记失败');
            return false;
        }
        if (!$this->smtp_putcmd("QUIT")) {
            $this->addError('发送 QUIT 命令失败');
            return false;
        }
        return true;
    }

    /**
     * Connect smtp server.
     * @return bool
     */
    protected function smtp_sockopen()
    {
        $this->_sock = @fsockopen($this->smtp_host, $this->smtp_port, $errno, $errstr, $this->smtp_timeout);
        if (!($this->_sock && $this->smtp_ok())) {
            $this->addError('连接不到邮件服务器主机');
            return false;
        }
        return true;
    }

    /**
     * Check whether the sock command is ok.
     * @return bool
     */
    protected function smtp_ok()
    {
        $response = str_replace("\r\n", '', fgets($this->_sock, 512));
        if (!preg_match('#^[23]#', $response)) {
            fputs($this->_sock, "QUIT\r\n");
            fgets($this->_sock, 512);
            return false;
        }
        return true;
    }

    /**
     * Send a smtp command to server.
     * @param string $cmd
     * @param string $arg
     * @return bool
     */
    protected function smtp_putcmd($cmd, $arg = '')
    {
        if ('' !== $arg) {
            if ('' == $cmd) {
                $cmd = $arg;
            } else {
                $cmd .= ' ' . $arg;
            }
        }
        fputs($this->_sock, $cmd . "\r\n");
        return $this->smtp_ok();
    }

    /**
     * Send mail message to mail server.
     * @param string $header
     * @param string $body
     * @return bool
     */
    protected function smtp_message($header, $body)
    {
        fputs($this->_sock, "{$header}\r\n{$body}");
        return true;
    }

    /**
     * Send end command to smtp server.
     * @return bool
     */
    protected function smtp_eom()
    {
        fputs($this->_sock, "\r\n.\r\n");
        return $this->smtp_ok();
    }

    /**
     * Format the email addresses to an array.
     * @param mixed $address
     * @return array
     */
    private function _getEmailArray($address)
    {
        if (!is_array($address)) {
            $address = explode(',', $address);
        }
        $address = array_map('trim', $address);
        $rs = [];
        foreach ($address as $mail) {
            if (preg_match('#^(.*)<((.*)@(.*))>$#', $mail, $matches)) {
                $mail_addr = $matches[2];
                $mail_user = $matches[3];
                $disp_name = $matches[1];
                $relay_host = $matches[4];
            } else if (preg_match('#^(.*)@(.*)$#', $mail, $matches)) {
                $mail_addr = $matches[0];
                $mail_user = $matches[1];
                $disp_name = $matches[1];
                $relay_host = $matches[2];
            } else {
                continue;
            }
            array_push($rs, [
                'disp_name' => $disp_name,
                'mail_addr' => $mail_addr,
            ]);
        }
        return $rs;
    }

    /**
     * Set header information.
     * @param string $header
     * @param string $value
     */
    private function _setHeader($header, $value)
    {
        $this->_headers[$header] = $value;
    }

    /**
     * Build the header string for mail.
     * @return string
     */
    private function _buildHeader()
    {
        $header = '';
        foreach ($this->_headers as $h => $v) {
            $header .= "{$h}: {$v}\r\n";
        }
        return $header;
    }

    /**
     * Set the common header information.
     */
    private function _setCommonHeader()
    {
        if (empty($this->_header)) {
            // Set version header.
            $this->_setHeader('MIME-Version', '1.0');

            // Set User Agent for mail sock header.
            $this->_setHeader('User-Agent', $this->_user_agent);

            // Set X-Sender header information.
            $this->_setHeader('X-Sender', $this->_relay_mail);

            // Set X-Mailer header information.
            $this->_setHeader('X-Mailer', 'By ' . $this->_user_agent . '(PHP/' . PHP_VERSION . ').');

            // Set X-Priority header information.
            $this->_setHeader('X-Priority', $this->_priorities[$this->priority]);

            // Set From header information.
            $this->_setHeader('From', "{$this->disp_name}<{$this->_relay_mail}>");
        }
    }

    /**
     * Validate mail addresses.
     * @param mixed $addresses
     * @return bool
     */
    private function _validateEmail($addresses)
    {
        $email_pattern = '#^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$#ix';
        if (!is_array($addresses)) {
            return preg_match($email_pattern, $addresses) ? true : false;
        }
        foreach ($addresses as $address) {
            if (!preg_match($email_pattern, $address)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get a quote encoding string.
     * @param string $str
     * @param bool $from
     * @return string
     */
    private function _QuoteEncoding($str, $from = false)
    {
        $str = str_replace(["\r", "\n"], ['', ''], $str);

        // Line length must not exceed 76 characters, so we adjust for
        // a space, 7 extra characters =??Q??=, and the charset that we will add to each line
        $limit = 75 - 7 - strlen($this->charset);

        // These special characters must be converted too
        $convert = ['_', '=', '?'];
        if (true === $from) {
            array_push($convert, ',');
            array_push($convert, ';');
        }
        $output = '';
        $tmp = '';
        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            // Grab the next character
            $c = $str{$i};
            $ascii = ord($c);

            // Convert ALL non-printable ASCII characters and our specials
            if ($ascii < 32 || $ascii > 126 || in_array($c, $convert)) {
                $c = '=' . dechex($ascii);
            }

            // handle regular spaces a bit more compactly than =20
            if ($ascii == 32) {
                $c = '_';
            }

            // If we're at the character limit, add the line to the output,
            // reset our temp variable, and keep on chuggin'
            if (strlen($tmp) + strlen($c) >= $limit) {
                $output .= $tmp . "\n";
                $tmp = '';
            }

            // Add the character to our temporary line
            $tmp .= $c;
        }
        $str = $output . $tmp;

        // wrap each line with the shebang, charset, and transfer encoding
        // the preceding space on successive lines is required for header "folding"
        $str = trim(preg_replace('#^(.*)$#m', ' =?' . $this->charset . '?Q?$1?=', $str));
        return $str;
    }

    /**
     * 添加错误消息
     * @param string $msgKey
     * @param array $params
     */
    public function addError($msgKey, $params = [])
    {
        array_push($this->_errors, str_cover($msgKey, $params));
    }

    /**
     * 获取错误消息
     * @return mixed
     */
    public function getErrors()
    {
        return empty($this->_errors) ? null : $this->_errors;
    }

    /**
     * 清空错误消息
     */
    public function emptyErrors()
    {
        $this->_errors = [];
    }
}