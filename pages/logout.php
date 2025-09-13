<?php
// ============ pages/logout.php - Logout ============

session_destroy();
header('Location: /public/?page=login');
exit;
?>