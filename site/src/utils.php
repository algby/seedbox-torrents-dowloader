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
        $sizeTotal = disk_total_space(ROOT_SERVER_DIRECTORY);
        $sizeLeft = disk_free_space(ROOT_SERVER_DIRECTORY);
        $sizeUsed = $sizeTotal - $sizeLeft;

        $percent = 100 * $sizeUsed / $sizeTotal;
        if ($percent > 90) {
            $progressClass = 'danger';
        } else if ($percent > 70) {
            $progressClass = 'warning';
        } else {
            $progressClass = 'success';
        }

        if (file_exists(TEMP_DIR . LAST_UPDATE_FILE)) {
            $lastUpdateTimeStamp = file_get_contents(TEMP_DIR . LAST_UPDATE_FILE);
            if (is_numeric($lastUpdateTimeStamp)) {
                $header['lastUpdate'] = date(DATE_PATTERN, $lastUpdateTimeStamp);
            } else {
                $header['lastUpdate'] = '-';
            }
        } else {
            $header['lastUpdate'] = '-';
        }
        $header['diskInfo'] = array(
            'totalSize' => $sizeTotal,
            'totalSizeUsed' => $sizeUsed,
            'totalPercentSizeUsed' => $percent,
            'totalSizeLeft' => $sizeLeft,
            'progressClass' => $progressClass
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
    $settings = getSettings();

    return !(empty($settings['seedbox']) || empty($settings['seedbox']['host']) || empty($settings['seedbox']['username']) || empty($settings['seedbox']['password']));
}

/**
 * Function used to retrieve settings parameters
 *
 * @return array
 */
function getSettings()
{
    if (!file_exists(TEMP_DIR . SETTINGS_FILE)) {
        if (touch(TEMP_DIR . SETTINGS_FILE)) {
            chmod(TEMP_DIR . SETTINGS_FILE, 0600);
            initDownloadDirectory();
        } else {
            addLog('ERROR', 'Unable to create settings file on directory ' . TEMP_DIR . SETTINGS_FILE, 'error');
        }
        return array();
    } else {
        $settings = json_decode(file_get_contents(TEMP_DIR . SETTINGS_FILE), true);
        if (!is_null($settings) && !is_array($settings)) {
            addLog('ERROR', 'It\' seems your settings file are not well formatted.', 'error');
            return array();
        } else if(is_null($settings)) {
            return array();
        } else {
            return $settings;
        }
    }
}

function getSeedboxDetails()
{
    if (!file_exists(TEMP_DIR . SEEDBOX_DETAILS_FILE)) {
        if (touch(TEMP_DIR . SEEDBOX_DETAILS_FILE)) {
            chmod(TEMP_DIR . SEEDBOX_DETAILS_FILE, 0600);
            file_put_contents(TEMP_DIR . SEEDBOX_DETAILS_FILE, json_encode(array()));
        } else {
            addLog('ERROR', 'Unable to create seedbox details file on directory ' . TEMP_DIR . SEEDBOX_DETAILS_FILE, 'error');
        }
        return array();
    } else {
        $fileDetails = json_decode(file_get_contents(TEMP_DIR . SEEDBOX_DETAILS_FILE), true);
        if (!is_array($fileDetails)) {
            addLog('ERROR', 'It\' seems your seedbox details file are not well formatted.', 'error');
            return array();
        } else {
            return $fileDetails;
        }
    }
}

/**
 * Function used to
 */
function initDownloadDirectory()
{
    $settings = getSettings();
    $settings['downloadDirectory'] = realpath(getcwd() . '/../') . '/download';
    file_put_contents(TEMP_DIR . SETTINGS_FILE, json_encode($settings));
}

/**
 * Function returning download directory from settings
 * @return string download directory
 */
function getDownloadDirectory()
{
    $settings = getSettings();

    return $settings['downloadDirectory'] . '/';
}

/**
 * Function used to initialize ftp with seedbox information locate in settings file.
 *
 * Return false if settings are not set or invalids
 */
function createFTPConnection()
{
    $settings = getSettings();
    if (empty($settings['seedbox']) || empty($settings['seedbox']['host']) || empty($settings['seedbox']['username'])
        || empty($settings['seedbox']['password'])
    ) {
        addLog('ERROR', 'No setting file found.', 'ftp');
        return false;
    } else {
        // Connect to seedbox with SSL
        $ftp = ftp_ssl_connect($settings['seedbox']['host'], intval($settings['seedbox']['port']));
        if (!$ftp) {
            addLog('ERROR', 'Wrong FTP host.', 'ftp');
            return false;
        }
        // Log with information set on settings screen
        if (!ftp_login($ftp, $settings['seedbox']['username'], $settings['seedbox']['password'])) {
            addLog('ERROR', 'Wrong FTP login or password.', 'ftp');
            return false;
        };

        // Enter on passive mode
        if (ftp_pasv($ftp, true)) {
            return $ftp;
        } else {
            addLog('ERROR', 'Unable to switch to passive mode.', 'ftp');
            return false;
        }
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
 * @return bool true if everything's good
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

    return sendMail($output, $subject);
}

/**
 * Abstract function used to send an email
 * @param $text
 * @param $subject
 * @return bool
 */
function sendMail($text, $subject)
{
    $settings = getSettings();
    if (empty($settings['mailing']) || empty($settings['mailing']['smtpHost']) ||
        empty($settings['mailing']['username']) || empty($settings['mailing']['password'])
    ) {
        addLog('WARNING', 'Mailing configuration is not set', 'mailing');
        return false;
    } else {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->SMTPDebug = 0; // debugging: 1 = errors and messages, 2 = messages only
        $mail->Host = $settings['mailing']['smtpHost'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['mailing']['username'];
        $mail->Password = $settings['mailing']['password'];
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        $mail->From = $settings['mailing']['username'];
        $mail->FromName = $settings['mailing']['username'];
        $mail->addAddress($settings['mailing']['recipient'], $settings['mailing']['recipient']);
        $mail->addReplyTo($settings['mailing']['username'], 'No-Reply');
        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body = $text;

        try {
            if (!$mail->send()) {
                addLog('ERROR', 'Unable to send mail.', 'mailing');
                return false;
            } else {
                addLog('SUCCESS', 'Mail has been sent', 'mailing');
                return true;
            }
        } catch (phpmailerException $e) {
            addLog('ERROR', 'Unable to send mail. Details : ' . $e->getMessage(), 'mailing');
            return false;
        } catch (Exception $e) {
            addLog('ERROR', 'Unable to send mail. Details : ' . $e->getMessage(), 'mailing');
            return false;
        }
    }
}

/**
 * Function used to know file size (octet)
 * @param $file
 * @return string
 */
function getFileSize($file)
{
    return (float)shell_exec('du -sk "' . $file . '" | awk \'{print$1}\'') * 1024;
}

/**
 * Convert bytes to human readable format
 *
 * @param $size
 * @param int $precision
 * @return string
 */
function fileOfSize($size, $precision = 2)
{
    if ($size >= 1099511627776) return round(($size / 1099511627776 * 100) / 100, $precision) . ' To';
    if ($size >= 1073741824) return round(($size / 1073741824 * 100) / 100, $precision) . ' Go';
    if ($size >= 1048576) return round(($size / 1048576 * 100) / 100, $precision) . ' Mo';
    if ($size >= 1024) return round(($size / 1024 * 100) / 100, $precision) . ' Ko';
    if ($size > 0) return $size . ' o';
    return '-';
}

function searchFile($filePath, $currentPathKey, $files)
{
    // Check if currentPath is the last one of $filePath
    if ($currentPathKey == (count($filePath) - 1)) {
        return $files[$filePath[$currentPathKey]];
    } else {
        return searchFile($filePath, ($currentPathKey + 1), $files[$filePath[$currentPathKey]]['children']);
    }
}

function computeChildren($children, $downloadDirectory, $dir = '')
{
    $torrents = array();
    foreach ($children as $fileDetail) {
        if ($fileDetail['name'] != 'recycle_bin') {
            // 4 cases :
            //  - File/Dir can be downloaded
            //  - File/Dir is already downloaded
            //  - File/Dir is pending to be downloaded
            //  - File/Dir is pending and is being downloaded
            // status can be (DOWNLOADED, PENDING, DOWNLOADING, NONE)
            $status = 'NONE';
            $detailsStatus = array();
            $fileSize = (float)$fileDetail['size'];
            if (file_exists(TEMP_DIR . 'pending/' . $dir . $fileDetail['name'])) {
                // File is pending, check if download is started or not
                if (file_exists($downloadDirectory . $dir . $fileDetail['name'])) {
                    $status = 'DOWNLOADING';
                    $currentSize = getFileSize($downloadDirectory . $dir . $fileDetail['name']);
                    $detailsStatus = array(
                        'currentSize' => $currentSize,
                        'currentPercent' => 100 * $currentSize / $fileSize
                    );
                } else {
                    $status = 'PENDING';
                }
            } else if (file_exists($downloadDirectory . $dir . $fileDetail['name'])) {
                // File is downloaded
                $status = 'DOWNLOADED';
            }
            $fileNameEncoded = urlencode($fileDetail['name']);

            $torrents[] = array(
                'status' => $status,
                'detailsStatus' => $detailsStatus,
                'size' => $fileSize,
                'name' => $fileDetail['name'],
                'encodedName' => $fileNameEncoded,
                'isDirectory' => $fileDetail['type'] === 'directory'
            );
        }
    }

    return $torrents;
}

function addLog($lvl, $text, $file)
{
    $logFile = date('Y-m-d') . '-' . $file . '.log';
    $text = '[' . $lvl . '] ' . date(DATE_PATTERN) . ' : ' . $text;
    file_put_contents(LOGS_DIRECTORY . $logFile, $text . "\n", FILE_APPEND);
}