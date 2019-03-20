<?php
/**
 * Link         :   http://www.phpcorner.net
 * User         :   qingbing<780042175@qq.com>
 * Date         :   2018-05-19
 * Version      :   1.0
 */
require("../vendor/autoload.php");


$to = 'to@qq.com';
$subject = 'phpcorner.net 邮件测试';
$body = '测试 phpcorner.net 邮件';
$cc = 'xxx@qq.com';
$bcc = 'xxx@163.com';
$mail = \Tools\Email::getInstance();
$status = $mail->sendMail($to, $subject, $body, $cc, $bcc);
if ($status) {
    echo 'success';
} else {
    var_dump($mail->getErrors());
}