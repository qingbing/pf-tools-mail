# pf-tools-mail
## 描述
工具——php对外邮件的发送

## 注意事项
- mail 邮件发送配置参考"qingbing/config",配置在"conf/email.php"中
- mail 配置的属性可自行参考"\Tools\Email"的公有属性，以下为其中一种方式：
```php
return [
    'mail_addr' => 'PhpCorner<tonyzsjl@163.com>',
    'mail_pass' => 'xxxxxx',
];
```


## 使用方法
```php
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
```

## ====== 异常代码集合 ======

异常代码格式：1026 - XXX - XX （组件编号 - 文件编号 - 代码内异常）
```
 - 102600101 : 邮件中转地址 ({mail}) 无效
 - 102600102 : 邮件中转地址 ({mail}) 无效
 - 102600103 : 中转邮件地址密码不能为空
```