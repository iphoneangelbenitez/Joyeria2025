<?php
// logout_seguro.php
session_start();
unset($_SESSION['super_acceso']);
header("Location: create_admin.php");
exit;