<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director','qa_staff']);
header("Location: departments.php?tab=programs");
exit();
