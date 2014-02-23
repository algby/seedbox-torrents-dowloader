<?php
require_once('../src/constants.php');
require_once('../vendor/PHPMailer/PHPMailerAutoload.php');
require_once('../vendor/Smarty/Smarty.class.php');

/**
 * Function which initialise smarty with header and footer information
 *
 * @param $smarty
 * @param $currentPage
 * @param $diskInfo
 */
function initSmarty($smarty, $currentPage, $diskInfo = true)
{
    $header = array(
        'title' => WEBSITE_TITLE,
        'currentPage' => $currentPage,
        'isSeedboxInitialized' => isSeedboxInitialized()
    );

    if ($diskInfo) {
        /* DISK SIZE INFO */
        $diskInfo = shell_exec('df -k ' . ROOT_SERVER_DIRECTORY . ' | awk \'NR==2\'');
        $diskInfo = explode(' ', $diskInfo);
        $sizeTotal = $diskInfo[3] * 1024;
        $sizeUsed = $diskInfo[4] * 1024;
        $percent = 100 * $sizeUsed / $sizeTotal;
        $sizeLeft = $sizeTotal - $sizeUsed;

        $header['lastUpdate'] = date(DATE_PATTERN, file_get_contents(TEMP_DIR . LAST_UPDATE_FILE));
        $header['diskInfo'] = array(
            'totalSize' => $sizeTotal,
            'totalSizeUsed' => $sizeUsed,
            'totalPercentSizeUsed' => $percent,
            'totalSizeLeft' => $sizeLeft
        );
    }

    $smarty->assign('header', $header);

    $footer = array(
        'title' => sprintf(WEBSITE_FOOTER, date('Y'))
    );

    $smarty->assign('footer', $footer);

    initSettingsSmarty($smarty);
}

/**
 * Function initialize settings for smarty
 *
 * @param $smarty
 */
function initSettingsSmarty($smarty)
{
    $smarty->setTemplateDir('../src/smarty/templates');
    $smarty->setCompileDir('../src/smarty/templates_c');
    $smarty->setCacheDir('../src/smarty/cache');
    $smarty->setConfigDir('../src/smarty/configs');
    $smarty->addPluginsDir('../src/smarty/plugins');
}

/**
 * Function return true if seedbox information have been set
 */
function isSeedboxInitialized()
{
    if (file_exists(TEMP_DIR . SETTINGS_FILE)) {
        $settings = json_decode(file_get_contents(TEMP_DIR . SETTINGS_FILE), true);

        return !(empty($settings['seedbox']) || empty($settings['seedbox']['host']) || empty($settings['seedbox']['username'])
            || empty($settings['seedbox']['password']));
    } else {
        return false;
    }
}

/**
 * Function used to initialize ftp with seedbox information locate in settings file.
 *
 * Return false if settings are not set or invalids
 */
function createFTPConnection()
{
    if (file_exists(TEMP_DIR . SETTINGS_FILE)) {
        $settings = json_decode(file_get_contents(TEMP_DIR . SETTINGS_FILE), true);

        if (empty($settings['seedbox']) || empty($settings['seedbox']['host']) || empty($settings['seedbox']['username'])
            || empty($settings['seedbox']['password'])
        ) {
            return false;
        } else {
            // Connect to seedbox with SSL
            $ftp = ftp_ssl_connect($settings['seedbox']['host'], intval($settings['seedbox']['port']));
            if (!$ftp) {
                return false;
            }
            // Log with information set on settings screen
            if (!ftp_login($ftp, $settings['seedbox']['username'], $settings['seedbox']['password'])) {
                return false;
            };

            // Enter on passive mode
            if (ftp_pasv($ftp, true)) {
                return $ftp;
            } else {
                return false;
            }
        }
    } else {
        return false;
    }
}

/**
 * Function used to send an email when a download is completed
 *
 * @param $parameters array with possibles values are
 * <ul>
 *  <li>file</li>
 *  <li>size</li>
 *  <li>begin</li>
 *  <li>end</li>
 *  <li>duration</li>
 *  <li>average</li>
 * </ul>
 */
function sendCompleteMail($parameters)
{
    $smarty = new Smarty();
    initSettingsSmarty($smarty);

    foreach ($parameters as $key => $value) {
        $smarty->assign($key, $value);
    }
    $subject = 'Your download is complete';
    $smarty->assign('title', $subject);
    $smarty->assign('footer', sprintf(WEBSITE_FOOTER, date('Y')));
    $output = $smarty->fetch('mail-download-complete.tpl');

    sendMail($output, $subject);
}

/**
 * Abstract function used to send an email
 * @param $text
 * @param $subject
 * @return bool
 */
function sendMail($text, $subject)
{
    if (file_exists(TEMP_DIR . SETTINGS_FILE)) {
        $settings = json_decode(file_get_contents(TEMP_DIR . SETTINGS_FILE), true);

        if (empty($settings['mailing']) || empty($settings['mailing']['smtpHost']) ||
            empty($settings['mailing']['username']) || empty($settings['mailing']['password'])
        ) {
            return false;
        } else {
            $mail = new PHPMailer;

            $mail->isSMTP();
            $mail->Host = $settings['mailing']['smtpHost'];
            $mail->SMTPAuth = true;
            $mail->Username = $settings['mailing']['username'];
            $mail->Password = $settings['mailing']['password'];
            $mail->SMTPSecure = 'tls';

            $mail->From = $settings['mailing']['username'];
            $mail->FromName = $settings['mailing']['username'];
            $mail->addAddress($settings['mailing']['recipient'], $settings['mailing']['recipient']);
            $mail->addReplyTo($settings['mailing']['username'], 'No-Reply');
            $mail->isHTML(true);

            $mail->Subject = $subject;
            $mail->Body = $text;

            return !$mail->send();
        }
    } else {
        return false;
    }
}

function getFileSize($file)
{
    return shell_exec('du -sk ' . $file . ' | awk \'{print$1}\'') * 1024;
}

/**
 * Convert bytes to human readable format
 *
 * @param $octets
 * @param int $precision
 * @return string
 */
function octetsToSize($octets, $precision = 2)
{
    $kilooctet = 1024;
    $megaoctet = $kilooctet * 1024;
    $gigaoctet = $megaoctet * 1024;
    $teraoctet = $gigaoctet * 1024;

    if (($octets >= 0) && ($octets < $kilooctet)) {
        return $octets . ' o';

    } elseif (($octets >= $kilooctet) && ($octets < $megaoctet)) {
        return round($octets / $kilooctet, $precision) . ' Ko';

    } elseif (($octets >= $megaoctet) && ($octets < $gigaoctet)) {
        return round($octets / $megaoctet, $precision) . ' Mo';

    } elseif (($octets >= $gigaoctet) && ($octets < $teraoctet)) {
        return round($octets / $gigaoctet, $precision) . ' Go';

    } elseif ($octets >= $teraoctet) {
        return round($octets / $teraoctet, $precision) . ' To';
    } else {
        return $octets . ' O';
    }
}