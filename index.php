<?php
if (file_exists(__DIR__ . '/config.ini')) {
    header('Location: dashboard.php');
} else {
    header('Location: install/index.php');
}
exit;
