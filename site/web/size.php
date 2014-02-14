<?php
$file = $_GET['file'];

include('../src/constants.php');
include('../src/utils.php');
if (empty($file)) {
    http_response_code(400);
} else if (!file_exists(FILES_TO_DOWNLOAD_SERVER_DIRECTORY . SEEDBOX_NAME . '/' . $file)) {
    // Do nothing
} else {
    $decodedFile = urldecode($file);
    $size = shell_exec('du -sk ' . FILES_TO_DOWNLOAD_SERVER_DIRECTORY . SEEDBOX_NAME . '/' . $decodedFile . ' | awk \'{print$1}\'') * 1024;
    $filesDetails = json_decode(file_get_contents(FILES_DETAILS_MIRROR_SEEDBOX), true);
    foreach($filesDetails as $fileDetail) {
        if($fileDetail['file'] == $decodedFile) {
            $data = array(
                'h' => octetsToSize($size),
                's' => $size,
                't' => $fileDetail['size'] * 1024
            );
            echo json_encode($data);
        }
    }
}